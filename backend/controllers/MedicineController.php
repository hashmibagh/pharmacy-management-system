<?php
declare(strict_types=1);

namespace Pharmacy\Controllers;

use Pharmacy\Config\Database;
use Pharmacy\Helpers\ApiException;
use Pharmacy\Helpers\Excel;
use Pharmacy\Helpers\Response;
use Pharmacy\Helpers\Validator;
use Pharmacy\Models\Category;
use Pharmacy\Models\Manufacturer;
use Pharmacy\Models\Medicine;
use Pharmacy\Models\MedicineBatch;
use Pharmacy\Models\StockTransaction;

class MedicineController extends BaseController
{
    /**
     * Frontend-facing input keys (see frontend/src/pages/Medicines.jsx).
     * Mapped to real columns in medicineData()/batchData():
     *   name→medicine_name, brand→brand_name, unit→packing,
     *   purchase_price/sale_price/min_stock/max_stock/shelf→medicine_batches.
     * Keys with no column (sku, pack_size, mrp, requires_prescription) are ignored.
     */
    private const FILLABLE = [
        'name', 'generic_name', 'brand', 'category_id', 'manufacturer_id',
        'barcode', 'strength', 'dosage_form', 'unit',
        'purchase_price', 'sale_price', 'min_stock', 'max_stock', 'shelf',
        'description',
    ];

    public function index(array $request): void
    {
        $q = $this->query($request);
        [$page, $perPage] = Validator::pagination($q);
        $result = Medicine::searchPaginate(
            $page, $perPage,
            trim((string) ($q['q'] ?? $q['search'] ?? '')),
            [
                'category_id'     => $q['category_id'] ?? null,
                'manufacturer_id' => $q['manufacturer_id'] ?? null,
                'status'          => $q['status'] ?? null,
                'is_active'       => $q['is_active'] ?? null,
                'in_stock'        => $q['in_stock'] ?? null,
                'stock'           => $q['stock'] ?? null,
            ]
        );
        Response::success($result);
    }

    public function store(array $request): void
    {
        $b = $this->body($request);
        $this->validateInput($b);

        $data = $this->medicineData($b);
        $data['barcode'] = $data['barcode'] ?? $this->generateEan13();
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        Database::beginTransaction();
        try {
            $id = (int) Medicine::create($data);
            $this->syncBatchFields($id, $b);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $this->audit($request, 'MEDICINE_CREATED', 'medicines', $id, null, $data);
        Response::success(Medicine::findWithRelations($id), 'Medicine created.', 201);
    }

    public function show(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $medicine = Medicine::findWithRelations($id);
        if (!$medicine) {
            throw new ApiException('Medicine not found.', 404);
        }
        $medicine['batches'] = array_map(
            [$this, 'shapeBatch'],
            MedicineBatch::where('medicine_id = :mid AND deleted_at IS NULL', [':mid' => $id], 'expiry_date ASC')
        );
        Response::success($medicine);
    }

    public function update(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $old = Medicine::find($id);
        if (!$old) {
            throw new ApiException('Medicine not found.', 404);
        }
        $b = $this->body($request);
        $this->validateInput($b, true);

        $data = $this->medicineData($b, true);
        $data['updated_at'] = date('Y-m-d H:i:s');

        Database::beginTransaction();
        try {
            if ($data) {
                Medicine::update($id, $data);
            }
            $this->syncBatchFields($id, $b);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $this->audit($request, 'MEDICINE_UPDATED', 'medicines', $id, $old, $data);
        Response::success(Medicine::findWithRelations($id), 'Medicine updated.');
    }

    public function destroy(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $old = Medicine::find($id);
        if (!$old) {
            throw new ApiException('Medicine not found.', 404);
        }
        $stock = (float) (Medicine::rawOne(
            Medicine::STOCK_SQL . ' AS s FROM medicines m WHERE m.id = :id',
            [':id' => $id]
        )['s'] ?? 0);
        if ($stock > 0) {
            throw new ApiException('Cannot delete a medicine that still has stock. Adjust stock to zero first.', 422);
        }
        $linked = Medicine::rawOne(
            'SELECT (SELECT COUNT(*) FROM sale_items WHERE medicine_id = :a)
              + (SELECT COUNT(*) FROM purchase_items WHERE medicine_id = :b) AS c',
            [':a' => $id, ':b' => $id]
        )['c'] ?? 0;
        if ((int) $linked > 0) {
            throw new ApiException('Cannot delete: this medicine has sales/purchase history. Deactivate it instead.', 422);
        }

        Medicine::delete($id);
        $this->audit($request, 'MEDICINE_DELETED', 'medicines', $id, $old, null);
        Response::success(null, 'Medicine deleted.');
    }

    public function duplicate(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $old = Medicine::find($id);
        if (!$old) {
            throw new ApiException('Medicine not found.', 404);
        }
        unset($old['id'], $old['created_at'], $old['updated_at'], $old['deleted_at']);
        $old['medicine_name'] = $old['medicine_name'] . ' (Copy)';
        $old['barcode']       = $this->generateEan13();
        $old['status']        = 'active';
        $old['created_at']    = date('Y-m-d H:i:s');
        $old['updated_at']    = date('Y-m-d H:i:s');

        $newId = (int) Medicine::create($old);
        $this->audit($request, 'MEDICINE_CREATED', 'medicines', $newId, null, ['duplicated_from' => $id]);
        Response::success(Medicine::findWithRelations($newId), 'Medicine duplicated.', 201);
    }

    public function import(array $request): void
    {
        $file = $request['files']['file'] ?? null;
        if (!$file) {
            throw new ApiException('No file uploaded. Use the "file" field (.xlsx/.csv).', 422);
        }
        [$headings, $rows] = Excel::readUploaded($file);

        $required = ['name'];
        foreach ($required as $req) {
            if (!in_array($req, $headings, true)) {
                throw new ApiException("Missing required column: {$req}.", 422);
            }
        }

        $created = 0;
        $skipped = [];
        Database::beginTransaction();
        try {
            foreach ($rows as $i => $row) {
                $rec = array_combine($headings, array_pad($row, count($headings), null));
                $name = trim((string) ($rec['name'] ?? ''));
                if ($name === '') {
                    $skipped[] = ['row' => $i + 2, 'reason' => 'Name is empty'];
                    continue;
                }
                $data = [
                    'medicine_name'   => $name,
                    'generic_name'    => trim((string) ($rec['generic_name'] ?? '')) ?: null,
                    'brand_name'      => trim((string) ($rec['brand'] ?? '')) ?: null,
                    'category_id'     => $this->resolveCategory(trim((string) ($rec['category'] ?? ''))),
                    'manufacturer_id' => $this->resolveManufacturer(trim((string) ($rec['manufacturer'] ?? ''))),
                    'barcode'         => trim((string) ($rec['barcode'] ?? '')) ?: $this->generateEan13(),
                    'packing'         => trim((string) ($rec['unit'] ?? 'strip')) ?: 'strip',
                    'strength'        => trim((string) ($rec['strength'] ?? '')) ?: null,
                    'dosage_form'     => trim((string) ($rec['dosage_form'] ?? '')) ?: null,
                    'description'     => trim((string) ($rec['description'] ?? '')) ?: null,
                    'status'          => 'active',
                    'created_at'      => date('Y-m-d H:i:s'),
                    'updated_at'      => date('Y-m-d H:i:s'),
                ];
                $id = (int) Medicine::create($data);
                $this->syncBatchFields($id, [
                    'purchase_price' => $rec['purchase_price'] ?? null,
                    'sale_price'     => $rec['sale_price'] ?? null,
                    'min_stock'      => $rec['min_stock'] ?? null,
                ]);
                $created++;
            }
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $this->audit($request, 'MEDICINE_IMPORTED', 'medicines', null, null, ['created' => $created, 'skipped' => count($skipped)]);
        Response::success(['created' => $created, 'skipped' => $skipped], "Import complete: {$created} created.");
    }

    public function export(array $request): void
    {
        $q = $this->query($request);
        $result = Medicine::searchPaginate(1, 100000, trim((string) ($q['q'] ?? $q['search'] ?? '')), [
            'category_id' => $q['category_id'] ?? null,
            'manufacturer_id' => $q['manufacturer_id'] ?? null,
        ]);
        $headings = ['ID', 'Name', 'Generic Name', 'Brand', 'Category', 'Manufacturer', 'Barcode', 'Unit', 'Purchase Price', 'Sale Price', 'Stock Qty', 'Min Stock', 'Status'];
        $rows = [];
        foreach ($result['data'] as $m) {
            $rows[] = [
                $m['id'], $m['name'], $m['generic_name'], $m['brand'], $m['category_name'], $m['manufacturer_name'],
                $m['barcode'], $m['unit'], $m['purchase_price'], $m['sale_price'],
                $m['stock'], $m['min_stock'], $m['status'],
            ];
        }
        Excel::download('medicines_' . date('Ymd_His'), $headings, $rows, 'Medicines');
    }

    /**
     * Generate (or return existing) barcode for a medicine.
     * Returns the EAN-13 value, a pure-PHP Code 39 SVG, and a QR payload
     * string (render the QR on the client — no server QR library required).
     */
    public function barcode(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $medicine = Medicine::find($id);
        if (!$medicine) {
            throw new ApiException('Medicine not found.', 404);
        }
        if (empty($medicine['barcode'])) {
            $barcode = $this->generateEan13();
            Medicine::update($id, ['barcode' => $barcode, 'updated_at' => date('Y-m-d H:i:s')]);
            $medicine['barcode'] = $barcode;
        }
        Response::success([
            'medicine_id'   => $id,
            'name'          => $medicine['medicine_name'],
            'barcode'       => $medicine['barcode'],
            'barcode_svg'   => self::code39Svg((string) $medicine['barcode']),
            // QR payload: frontend renders the actual QR code from this string.
            'qr_payload'    => json_encode([
                'type' => 'medicine', 'id' => $id,
                'barcode' => $medicine['barcode'],
                'name' => $medicine['medicine_name'],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Stock movement history for one medicine (newest first).
     * Powers the "History" drawer in Medicines.jsx.
     */
    public function history(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        if (!Medicine::find($id)) {
            throw new ApiException('Medicine not found.', 404);
        }
        $q = $this->query($request);
        [$page, $perPage] = Validator::pagination($q);
        Response::success(StockTransaction::history($page, $perPage, ['medicine_id' => $id]));
    }

    // ---------------------------------------------------------- internals
    /** Map frontend input keys to real `medicines` columns. */
    private function medicineData(array $b, bool $isUpdate = false): array
    {
        $map = [
            'name'            => 'medicine_name',
            'generic_name'    => 'generic_name',
            'brand'           => 'brand_name',
            'category_id'     => 'category_id',
            'manufacturer_id' => 'manufacturer_id',
            'barcode'         => 'barcode',
            'strength'        => 'strength',
            'dosage_form'     => 'dosage_form',
            'unit'            => 'packing',
            'description'     => 'description',
        ];
        $data = [];
        foreach ($map as $in => $col) {
            if (array_key_exists($in, $b)) {
                $v = $b[$in];
                if (in_array($in, ['category_id', 'manufacturer_id'], true)) {
                    $data[$col] = ($v === '' || $v === null) ? null : (int) $v;
                } else {
                    $v = is_string($v) ? trim($v) : $v;
                    $data[$col] = ($v === '') ? null : $v;
                }
            }
        }
        if (!$isUpdate) {
            $data['status'] = 'active';
        } elseif (isset($b['status']) && in_array($b['status'], ['active', 'inactive'], true)) {
            $data['status'] = $b['status'];
        }
        return $data;
    }

    /**
     * Pricing / stock-level fields live on medicine_batches, not medicines.
     * On create, store them on a fresh (zero-qty) batch; on update, apply
     * them to the most recent batch (creating one when none exists).
     */
    private function syncBatchFields(int $medicineId, array $b): void
    {
        $batch = [];
        if (isset($b['purchase_price']) && $b['purchase_price'] !== '' && $b['purchase_price'] !== null) {
            $batch['purchase_price'] = (float) $b['purchase_price'];
        }
        if (isset($b['sale_price']) && $b['sale_price'] !== '' && $b['sale_price'] !== null) {
            $batch['sale_price'] = (float) $b['sale_price'];
        }
        if (isset($b['min_stock']) && $b['min_stock'] !== '' && $b['min_stock'] !== null) {
            $batch['minimum_stock'] = (int) $b['min_stock'];
        }
        if (isset($b['max_stock']) && $b['max_stock'] !== '' && $b['max_stock'] !== null) {
            $batch['maximum_stock'] = (int) $b['max_stock'];
        }
        if (isset($b['shelf']) && trim((string) $b['shelf']) !== '') {
            $batch['rack_number'] = trim((string) $b['shelf']);
        }
        if (!$batch) {
            return;
        }
        $existing = MedicineBatch::rawOne(
            'SELECT id FROM medicine_batches WHERE medicine_id = :mid AND deleted_at IS NULL ORDER BY id DESC LIMIT 1',
            [':mid' => $medicineId]
        );
        if ($existing) {
            $batch['updated_at'] = date('Y-m-d H:i:s');
            MedicineBatch::update((int) $existing['id'], $batch);
        } else {
            $batch['medicine_id']  = $medicineId;
            $batch['batch_number']  = 'OPEN-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
            $batch['quantity']      = 0;
            $batch['purchase_price'] = $batch['purchase_price'] ?? 0;
            $batch['sale_price']     = $batch['sale_price'] ?? 0;
            $batch['created_at']    = date('Y-m-d H:i:s');
            $batch['updated_at']    = date('Y-m-d H:i:s');
            MedicineBatch::create($batch);
        }
    }

    /** Add the JSON keys the frontend reads on batch rows. */
    private function shapeBatch(array $b): array
    {
        $b['qty']       = $b['quantity'] ?? 0;
        $b['batch_no']  = $b['batch_number'] ?? '';
        $b['min_stock'] = $b['minimum_stock'] ?? 0;
        return $b;
    }

    private function validateInput(array $b, bool $isUpdate = false): void
    {
        $rules = [
            'name'             => ($isUpdate ? 'nullable' : 'required') . '|string|max:200',
            'generic_name'     => 'nullable|string|max:200',
            'brand'            => 'nullable|string|max:200',
            'category_id'      => 'nullable|integer',
            'manufacturer_id'  => 'nullable|integer',
            'barcode'          => 'nullable|string|max:50',
            'strength'         => 'nullable|string|max:50',
            'dosage_form'      => 'nullable|string|max:50',
            'unit'             => 'nullable|string|max:30',
            'purchase_price'   => 'nullable|numeric',
            'sale_price'       => 'nullable|numeric',
            'min_stock'        => 'nullable|integer',
            'max_stock'        => 'nullable|integer',
            'shelf'            => 'nullable|string|max:20',
            'description'      => 'nullable|string|max:2000',
            'status'           => 'nullable|in:active,inactive',
        ];
        Validator::validate($b, $rules);
    }

    private function generateEan13(): string
    {
        do {
            $digits = '';
            for ($i = 0; $i < 12; $i++) {
                $digits .= (string) random_int(0, 9);
            }
            // EAN-13 check digit.
            $sum = 0;
            for ($i = 0; $i < 12; $i++) {
                $sum += ((int) $digits[$i]) * ($i % 2 === 0 ? 1 : 3);
            }
            $code = $digits . (string) ((10 - ($sum % 10)) % 10);
        } while (Medicine::findBy('barcode', $code));
        return $code;
    }

    private function resolveCategory(string $name): ?int
    {
        if ($name === '') {
            return null;
        }
        $found = Category::rawOne('SELECT id FROM medicine_categories WHERE name = :n LIMIT 1', [':n' => $name]);
        return $found ? (int) $found['id'] : null;
    }

    private function resolveManufacturer(string $name): ?int
    {
        if ($name === '') {
            return null;
        }
        $found = Manufacturer::rawOne('SELECT id FROM manufacturers WHERE name = :n LIMIT 1', [':n' => $name]);
        return $found ? (int) $found['id'] : null;
    }

    /**
     * Pure-PHP Code 39 barcode → SVG. No extensions required.
     */
    public static function code39Svg(string $text): string
    {
        $patterns = [
            '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn',
            '4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw',
            '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn', 'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw',
            'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn', 'F' => 'nnwnwwnnn',
            'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
            'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww',
            'O' => 'wnnnwnnwn', 'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn',
            'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn', 'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw',
            'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn', 'Z' => 'nwwnwnnnn',
            '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '*' => 'nwnnwnwnn',
            '$' => 'nwnwnwnnn', '/' => 'nwnwnnnwn', '+' => 'nwnnnwnwn', '%' => 'nnnwnwnwn',
        ];
        $text = '*' . strtoupper(preg_replace('/[^0-9A-Z \-\.\$\/\+\%]/', '', $text)) . '*';
        $narrow = 2;
        $wide = 6;
        $height = 60;
        $x = 10;
        $rects = '';
        $chars = str_split($text);
        foreach ($chars as $ci => $ch) {
            $pattern = $patterns[$ch] ?? $patterns[' '];
            for ($i = 0; $i < 9; $i++) {
                $w = ($pattern[$i] === 'w') ? $wide : $narrow;
                if ($i % 2 === 0) { // bar
                    $rects .= "<rect x=\"{$x}\" y=\"10\" width=\"{$w}\" height=\"{$height}\" fill=\"#000\"/>";
                }
                $x += $w;
            }
            if ($ci < count($chars) - 1) {
                $x += $narrow; // inter-character gap
            }
        }
        $width = $x + 10;
        $label = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        return "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"{$width}\" height=\"90\" viewBox=\"0 0 {$width} 90\">"
            . "<rect x=\"0\" y=\"0\" width=\"{$width}\" height=\"90\" fill=\"#fff\"/>"
            . $rects
            . "<text x=\"" . ($width / 2) . "\" y=\"84\" text-anchor=\"middle\" font-family=\"monospace\" font-size=\"12\">{$label}</text>"
            . '</svg>';
    }
}
