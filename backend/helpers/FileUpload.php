<?php
declare(strict_types=1);

namespace Pharmacy\Helpers;

use Pharmacy\Config\Config;

/**
 * Secure file uploads: randomized filenames, extension + MIME + size
 * validation. The original client filename is NEVER trusted or used.
 *
 * Files land under Config::uploadPath()/<subdir>/ e.g.
 *   <uploadPath>/prescriptions/prescription_8f31a2c9e4b7.pdf
 *
 * Returns the path RELATIVE to the upload root; store that in the DB.
 */
final class FileUpload
{
    /**
     * @param array<string, mixed> $file one $_FILES entry
     * @param string[] $allowedExts e.g. ['jpg','jpeg','png','pdf']
     * @param array<string, string> $allowedMimes ext => mime map (checked via finfo)
     * @throws ApiException on any validation failure
     */
    public static function store(
        array $file,
        string $prefix,
        string $subdir,
        array $allowedExts,
        array $allowedMimes = [],
        ?int $maxBytes = null
    ): string {
        $maxBytes ??= Config::uploadMaxBytes();

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new ApiException(self::uploadErrorMessage($error), 422);
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if (!is_uploaded_file($tmpPath)) {
            throw new ApiException('Invalid upload.', 422);
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            throw new ApiException(
                sprintf('File too large. Maximum allowed is %d MB.', (int) ($maxBytes / 1048576)),
                422
            );
        }

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowedExts, true)) {
            throw new ApiException('File type not allowed. Allowed: ' . implode(', ', $allowedExts), 422);
        }

        // MIME sniffing — never trust the client-supplied type.
        if ($allowedMimes) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($tmpPath) ?: 'application/octet-stream';
            $expected = $allowedMimes[$ext] ?? null;
            if ($expected === null || !self::mimeMatches($mime, $expected)) {
                throw new ApiException("File content does not match its extension (.{$ext}).", 422);
            }
        }

        // Extra sanity for images when gd is available.
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) && extension_loaded('gd')) {
            if (@getimagesize($tmpPath) === false) {
                throw new ApiException('Uploaded file is not a valid image.', 422);
            }
        }

        $subdir = trim(preg_replace('#[^a-z0-9_-]#i', '', $subdir), '/');
        $targetDir = Config::uploadPath() . '/' . $subdir;
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0750, true)) {
            throw new ApiException('Upload directory is not writable.', 500);
        }

        $filename = sprintf('%s_%s.%s', $prefix, bin2hex(random_bytes(8)), $ext);
        $target   = $targetDir . '/' . $filename;

        if (!move_uploaded_file($tmpPath, $target)) {
            throw new ApiException('Failed to save uploaded file.', 500);
        }
        @chmod($target, 0640);

        return $subdir . '/' . $filename;
    }

    public static function delete(?string $relativePath): void
    {
        if (!$relativePath) {
            return;
        }
        $full = realpath(Config::uploadPath() . '/' . ltrim($relativePath, '/'));
        $root = realpath(Config::uploadPath());
        // Path-traversal guard: only delete inside the upload root.
        if ($full && $root && str_starts_with($full, $root) && is_file($full)) {
            @unlink($full);
        }
    }

    /** Resolve a relative path for the file-serve endpoint (auth required). */
    public static function resolve(string $relativePath): ?string
    {
        $full = realpath(Config::uploadPath() . '/' . ltrim($relativePath, '/'));
        $root = realpath(Config::uploadPath());
        if ($full && $root && str_starts_with($full, $root) && is_file($full)) {
            return $full;
        }
        return null;
    }

    private static function mimeMatches(string $actual, string $expected): bool
    {
        // Allow e.g. image/jpeg vs image/pjpeg style variants loosely.
        if ($actual === $expected) {
            return true;
        }
        $a = explode('/', $actual)[0] ?? '';
        $e = explode('/', $expected)[0] ?? '';
        return $a !== '' && $a === $e && str_contains($actual, explode('/', $expected)[1] ?? '###');
    }

    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds the maximum upload size.',
            UPLOAD_ERR_PARTIAL   => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE   => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server misconfiguration: missing temp directory.',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write the uploaded file.',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by a server extension.',
            default               => 'File upload failed.',
        };
    }

    /** Common preset maps. */
    public static function imageRules(): array
    {
        return [
            ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'],
        ];
    }

    public static function documentRules(): array
    {
        return [
            ['jpg', 'jpeg', 'png', 'pdf'],
            ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'],
        ];
    }
}
