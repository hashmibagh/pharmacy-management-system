<?php
declare(strict_types=1);

namespace Pharmacy\Helpers;

/**
 * Thin Excel wrapper around phpoffice/phpspreadsheet.
 * Graceful degradation via Excel::available().
 */
final class Excel
{
    public static function available(): bool
    {
        return class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class);
    }

    public static function requireAvailable(): void
    {
        if (!self::available()) {
            throw new ApiException(
                'Excel export requires phpoffice/phpspreadsheet. Run: composer require phpoffice/phpspreadsheet',
                501
            );
        }
    }

    /**
     * Stream an .xlsx download and exit.
     *
     * @param string[] $headings
     * @param array<int, array<int, mixed>> $rows
     */
    public static function download(string $filename, array $headings, array $rows, string $sheetTitle = 'Sheet1'): void
    {
        self::requireAvailable();

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr($sheetTitle, 0, 31));

        $sheet->fromArray($headings, null, 'A1');
        if ($rows) {
            $sheet->fromArray($rows, null, 'A2');
        }

        // Header styling + auto-size.
        $lastCol = $sheet->getHighestColumn();
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastCol}1")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('1a73e8');
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->getColor()->setRGB('FFFFFF');
        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$lastCol}1");

        $safe = preg_replace('#[^a-zA-Z0-9._-]#', '_', $filename);
        if (!str_ends_with(strtolower($safe), '.xlsx')) {
            $safe .= '.xlsx';
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $safe . '"');
        header('Cache-Control: max-age=0');

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    /**
     * Read an uploaded .xlsx/.csv into [headings, rows].
     * @param array<string, mixed> $file one $_FILES entry
     * @return array{0: string[], 1: array<int, array<int, mixed>>}
     */
    public static function readUploaded(array $file): array
    {
        self::requireAvailable();

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new ApiException('No spreadsheet file was uploaded.', 422);
        }
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
            throw new ApiException('Only .xlsx, .xls or .csv files are accepted.', 422);
        }

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load((string) $file['tmp_name']);
        } catch (\Throwable $e) {
            throw new ApiException('Could not read the spreadsheet file.', 422);
        }

        $data = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        $data = array_values(array_filter($data, fn($r) => array_filter($r, fn($c) => $c !== null && $c !== '')));
        if (!$data) {
            throw new ApiException('The spreadsheet is empty.', 422);
        }
        $headings = array_map(fn($h) => strtolower(trim((string) $h)), array_shift($data));
        return [$headings, array_values($data)];
    }
}
