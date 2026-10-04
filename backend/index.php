<?php
declare(strict_types=1);

/**
 * Pharmacy Management System — API front controller.
 *
 * Works on Apache (.htaccess rewrites /api/* here), Nginx+PHP-FPM
 * (see README for the location block), KSWEB, XAMPP, WAMP, LAMP and
 * shared hosting. No Apache-only features are used in core code.
 */

define('BACKEND_PATH', __DIR__);

// ---------------------------------------------------------------- 1. Config
require_once BACKEND_PATH . '/config/Config.php';

use Pharmacy\Config\Config;

Config::load(BACKEND_PATH . '/.env');

// ------------------------------------------------------- 2. Autoloading
$vendorAutoload = BACKEND_PATH . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload; // firebase/php-jwt (+ optional dompdf/phpspreadsheet)
} else {
    // Bundled firebase/php-jwt fallback (MIT-licensed, from
    // https://github.com/firebase/php-jwt) so the API runs with zero
    // `composer install` — handy on KSWEB/Android and shared hosting.
    // `composer install` still works and takes precedence when present.
    $jwtLib = BACKEND_PATH . '/lib/Firebase/JWT';
    if (is_dir($jwtLib)) {
        foreach ([
            'BeforeValidException.php',
            'ExpiredException.php',
            'SignatureInvalidException.php',
            'Key.php',
            'JWT.php',
        ] as $jwtFile) {
            $jwtPath = $jwtLib . '/' . $jwtFile;
            if (is_file($jwtPath)) {
                require_once $jwtPath;
            }
        }
    }
}

// Fallback PSR-4-ish autoloader for our own classes so the API boots even
// before `composer install` (only JWT features will then report a clear error).
spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'Pharmacy\\')) {
        return;
    }
    $relative = substr($class, strlen('Pharmacy\\'));      // e.g. Helpers\Response
    $parts    = explode('\\', $relative);
    $dir      = strtolower(array_shift($parts));          // helpers
    $name     = implode('\\', $parts);                    // Response
    foreach ([
        BACKEND_PATH . "/{$dir}/{$name}.php",
        BACKEND_PATH . '/' . $dir . '/' . strtolower($name) . '.php',
    ] as $file) {
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});

use Pharmacy\Helpers\ApiException;
use Pharmacy\Helpers\Logger;
use Pharmacy\Helpers\Response;
use Pharmacy\Middleware\AuthMiddleware;
use Pharmacy\Middleware\Csrf;
use Pharmacy\Middleware\PermissionMiddleware;
use Pharmacy\Middleware\RateLimit;

// ---------------------------------------------------------------- 3. CORS
$corsOrigins = Config::get('CORS_ORIGINS', '*');
$origin      = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($corsOrigins === '*') {
    header('Access-Control-Allow-Origin: *');
} elseif ($origin !== '' && in_array($origin, array_map('trim', explode(',', $corsOrigins)), true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-TOKEN, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ------------------------------------------------- 4. Request parsing
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
// Strip the directory this front controller lives in (supports sub-folder installs).
$scriptDir = rtrim(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$scriptDir = preg_replace('#/index\.php$#', '', $scriptDir) ?? '';
$baseDir   = $scriptDir === '' || $scriptDir === '/' ? '' : $scriptDir;
$path      = '/' . ltrim(substr($requestUri, strlen($baseDir)), '/');
// Nginx fallback without rewrite: /index.php?_route=/api/...
if (($path === '/' || $path === '/index.php') && isset($_GET['_route'])) {
    $path = '/' . ltrim((string) $_GET['_route'], '/');
}

$body = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? '');
if (str_contains(strtolower($contentType), 'application/json')) {
    $decoded = json_decode((string) file_get_contents('php://input'), true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
} elseif (!empty($_POST)) {
    $body = $_POST;
}

$query = $_GET;
unset($query['_route']);

$request = [
    'method'     => $method,
    'path'       => $path,
    'query'      => $query,
    'body'       => $body,
    'params'     => [],
    'files'      => $_FILES,
    'user'       => null, // filled by AuthMiddleware
    'ip'         => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
    'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512),
];

// Health check (no auth).
if ($path === '/api/health' && $method === 'GET') {
    Response::success(['status' => 'ok', 'time' => gmdate('c')], 'API is running');
}

// ------------------------------------------------- 5. Routing
/** @var array<int, array> $routes */
$routes = require BACKEND_PATH . '/api/routes.php';

$matched = null;
$routeParams = [];
foreach ($routes as $route) {
    if (($route['method'] ?? 'GET') !== $method) {
        continue;
    }
    $pattern = '#^' . preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $route['path']) . '$#';
    if (preg_match($pattern, $path, $m)) {
        $matched = $route;
        foreach ($m as $k => $v) {
            if (!is_int($k)) {
                $routeParams[$k] = $v;
            }
        }
        break;
    }
}

if ($matched === null) {
    Response::error('Endpoint not found', 404);
}
$request['params'] = $routeParams;

// ------------------------------------------------- 6. Middleware + dispatch
try {
    if (Config::getBool('RATE_LIMIT_ENABLED', true) && !empty($matched['rate'])) {
        RateLimit::check($request, (int) $matched['rate'][0], (int) $matched['rate'][1]);
    }
    if (!empty($matched['auth'])) {
        AuthMiddleware::handle($request);
    }
    if (!empty($matched['permissions'])) {
        PermissionMiddleware::handle($request, (array) $matched['permissions']);
    }
    // CSRF: auto-enforced for state-changing requests when AUTH_MODE includes
    // sessions. JWT Bearer requests are immune (no ambient credentials) —
    // Csrf::handle() no-ops when the request isn't session-authenticated.
    // A route can still opt in explicitly via 'csrf' => true.
    if (!empty($matched['csrf']) || (Csrf::modeRequiresCsrf() && !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true))) {
        Csrf::handle($request);
    }

    [$controllerClass, $action] = $matched['handler'];
    $controller = new $controllerClass();
    if (!method_exists($controller, $action)) {
        throw new ApiException('Handler not implemented', 501);
    }
    $controller->$action($request);
} catch (ApiException $e) {
    Response::error($e->getMessage(), $e->getStatus(), $e->getErrors());
} catch (Throwable $e) {
    Logger::error('Uncaught exception', [
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
        'path'    => $path,
    ]);
    $message = Config::getBool('APP_DEBUG', false) ? $e->getMessage() : 'Internal server error';
    Response::error($message, 500);
}
