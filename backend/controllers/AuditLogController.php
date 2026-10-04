<?php
declare(strict_types=1);

namespace Pharmacy\Controllers;

use Pharmacy\Helpers\Response;
use Pharmacy\Helpers\Validator;
use Pharmacy\Models\AuditLog;

/**
 * Read-only audit trail. There are deliberately no update/delete methods —
 * audit rows are insert-only (see models/AuditLog.php).
 */
class AuditLogController extends BaseController
{
    public function index(array $request): void
    {
        $q = $this->query($request);
        [$page, $perPage] = Validator::pagination($q);
        Response::success(AuditLog::list($page, $perPage, [
            'action'  => $q['action'] ?? null,
            'module'  => $q['module'] ?? null,
            'user_id' => isset($q['user_id']) ? (int) $q['user_id'] : null,
            'from'    => $q['from'] ?? null,
            'to'      => $q['to'] ?? null,
        ]));
    }
}
