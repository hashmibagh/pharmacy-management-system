<?php
declare(strict_types=1);

namespace Pharmacy\Helpers;

/**
 * Thin PDF wrapper around dompdf/dompdf.
 * Degrades gracefully when the library is not installed: callers should
 * check Pdf::available() and return a clear 501-style JSON error instead
 * of a fatal.
 */
final class Pdf
{
    public static function available(): bool
    {
        return class_exists(\Dompdf\Dompdf::class);
    }

    public static function requireAvailable(): void
    {
        if (!self::available()) {
            throw new ApiException(
                'PDF export requires dompdf/dompdf. Run: composer require dompdf/dompdf',
                501
            );
        }
    }

    /**
     * Render HTML to a PDF binary string.
     */
    public static function fromHtml(string $html, string $paper = 'A4', string $orientation = 'portrait'): string
    {
        self::requireAvailable();

        $dompdf = new \Dompdf\Dompdf([
            'isRemoteEnabled'      => false, // no external fetches
            'isHtml5ParserEnabled' => true,
        ]);
        $dompdf->setPaper($paper, $orientation);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();
        return (string) $dompdf->output();
    }

    /** Stream a PDF download response and exit. */
    public static function download(string $html, string $filename, string $paper = 'A4', string $orientation = 'portrait'): void
    {
        $pdf = self::fromHtml($html, $paper, $orientation);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . preg_replace('#[^a-zA-Z0-9._-]#', '_', $filename) . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    /** Shared print CSS for invoices / reports. */
    public static function baseStyles(): string
    {
        return '<style>
            body{font-family:DejaVu Sans,Arial,sans-serif;font-size:11px;color:#222;margin:0}
            .doc{padding:24px}.header{display:flex;justify-content:space-between;border-bottom:2px solid #1a73e8;padding-bottom:10px;margin-bottom:14px}
            .header h1{font-size:20px;margin:0;color:#1a73e8}.meta{font-size:11px;color:#555}
            table{width:100%;border-collapse:collapse;margin:12px 0}
            th{background:#1a73e8;color:#fff;padding:7px 8px;text-align:left;font-size:11px}
            td{padding:6px 8px;border-bottom:1px solid #ddd;font-size:11px}
            tr:nth-child(even) td{background:#f7f9fc}
            .totals{width:320px;margin-left:auto}.totals td{border:none;padding:4px 8px}
            .totals .grand{font-weight:bold;font-size:13px;border-top:2px solid #1a73e8}
            .footer{margin-top:24px;font-size:10px;color:#777;text-align:center;border-top:1px solid #ddd;padding-top:8px}
            .badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;background:#e8f0fe;color:#1a73e8}
        </style>';
    }

    public static function esc(mixed $v): string
    {
        return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
