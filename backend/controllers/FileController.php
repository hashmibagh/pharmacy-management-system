<?php
declare(strict_types=1);

namespace Pharmacy\Controllers;

use Pharmacy\Helpers\ApiException;
use Pharmacy\Helpers\FileUpload;
use Pharmacy\Helpers\Response;

/**
 * Authenticated file serving. Uploads are stored OUTSIDE the web root
 * (or behind uploads/.htaccess with PHP execution disabled) and are
 * only served here, after the auth middleware has run.
 *
 * GET /api/files/{type}/{name}
 *   type: medicines | prescriptions | employees | logos | attachments
 */
class FileController extends BaseController
{
    private const ALLOWED_TYPES = ['medicines', 'prescriptions', 'employees', 'logos', 'attachments'];

    public function show(array $request): void
    {
        $type = (string) $this->param($request, 'type', '');
        $name = (string) $this->param($request, 'name', '');

        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new ApiException('Invalid file type.', 404);
        }
        // Strict filename whitelist — no directories, no traversal.
        if (!preg_match('/^[a-zA-Z0-9_-]+\.[a-zA-Z0-9]{2,5}$/', $name)) {
            throw new ApiException('Invalid filename.', 404);
        }

        $full = FileUpload::resolve($type . '/' . $name);
        if (!$full) {
            throw new ApiException('File not found.', 404);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($full) ?: 'application/octet-stream';

        // Allow only safe content types to be served inline.
        $allowedMimes = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'application/pdf',
        ];
        $disposition = in_array($mime, $allowedMimes, true) ? 'inline' : 'attachment';

        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . $disposition . '; filename="' . $name . '"');
        header('Content-Length: ' . filesize($full));
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');

        readfile($full);
        exit;
    }
}
