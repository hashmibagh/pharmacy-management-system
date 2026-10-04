<?php
declare(strict_types=1);

namespace Pharmacy\Controllers;

use Pharmacy\Helpers\ApiException;
use Pharmacy\Helpers\Response;
use Pharmacy\Helpers\Validator;
use Pharmacy\Models\Supplier;

class SupplierController extends BaseController
{
    /**
     * Frontend keys (Suppliers.jsx): name, phone, email, address, contact_person, notes.
     * Mapped: is_active→status. `notes` has no column and is ignored.
     */
    private const FILLABLE = [
        'name', 'contact_person', 'phone', 'whatsapp', 'email',
        'address', 'tax_number', 'opening_balance', 'status',
    ];

    public function index(array $request): void
    {
        $q = $this->query($request);
        [$page, $perPage] = Validator::pagination($q);
        $where = ['deleted_at IS NULL'];
        $params = [];
        $search = trim((string) ($q['q'] ?? $q['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(name LIKE :s OR phone LIKE :s OR email LIKE :s)';
            $params[':s'] = '%' . $search . '%';
        }
        $w = implode(' AND ', $where);
        $total = Supplier::rawOne("SELECT COUNT(*) AS c FROM suppliers WHERE {$w}", $params)['c'] ?? 0;
        $offset = ($page - 1) * $perPage;
        $result = [
            'data' => Supplier::where($w, $params, 'name ASC', $perPage, $offset),
            'meta' => [
                'current_page' => $page, 'per_page' => $perPage,
                'total' => (int) $total, 'last_page' => (int) max(1, ceil($total / $perPage)),
            ],
        ];
        // Attach outstanding per supplier (single query).
        $outstanding = Supplier::raw(
            'SELECT p.supplier_id,
                    COALESCE(SUM(p.grand_total),0)
                    - COALESCE((SELECT SUM(pp.amount) FROM purchase_payments pp
                                JOIN purchases p2 ON p2.id = pp.purchase_id
                                WHERE p2.supplier_id = p.supplier_id),0)
                    - COALESCE((SELECT SUM(pr.total_amount) FROM purchase_returns pr
                                JOIN purchases p3 ON p3.id = pr.purchase_id
                                WHERE p3.supplier_id = p.supplier_id),0) AS outstanding
             FROM purchases p WHERE p.deleted_at IS NULL GROUP BY p.supplier_id'
        );
        $map = [];
        foreach ($outstanding as $row) {
            $map[$row['supplier_id']] = round((float) $row['outstanding'], 2);
        }
        foreach ($result['data'] as &$s) {
            $s['outstanding'] = $map[$s['id']] ?? 0.0;
            $s['due'] = $map[$s['id']] ?? 0.0; // key the frontend reads (r.due || r.balance)
        }
        Response::success($result);
    }

    public function store(array $request): void
    {
        $b = $this->body($request);
        Validator::validate($b, [
            'name'           => 'required|string|max:150',
            'phone'          => 'nullable|string|max:30',
            'whatsapp'       => 'nullable|string|max:30',
            'email'          => 'nullable|email|max:190',
            'address'        => 'nullable|string|max:500',
            'contact_person' => 'nullable|string|max:150',
            'tax_number'     => 'nullable|string|max:50',
            'opening_balance'=> 'nullable|numeric',
            'notes'          => 'nullable|string|max:1000',
        ]);
        $data = $this->supplierData($b);
        $data['status'] = 'active';
        $data['created_at'] = date('Y-m-d H:i:s');
        $id = (int) Supplier::create($data);
        $this->audit($request, 'SUPPLIER_CREATED', 'suppliers', $id, null, $data);
        Response::success($this->shaped($id), 'Supplier created.', 201);
    }

    public function show(array $request): void
    {
        $s = $this->shaped((int) $this->param($request, 'id'));
        if (!$s) {
            throw new ApiException('Supplier not found.', 404);
        }
        Response::success($s);
    }

    public function update(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $old = Supplier::find($id);
        if (!$old) {
            throw new ApiException('Supplier not found.', 404);
        }
        $b = $this->body($request);
        Validator::validate($b, [
            'name'           => 'required|string|max:150',
            'phone'          => 'nullable|string|max:30',
            'whatsapp'       => 'nullable|string|max:30',
            'email'          => 'nullable|email|max:190',
            'address'        => 'nullable|string|max:500',
            'contact_person' => 'nullable|string|max:150',
            'tax_number'     => 'nullable|string|max:50',
            'opening_balance'=> 'nullable|numeric',
            'notes'          => 'nullable|string|max:1000',
            'status'         => 'nullable|in:active,inactive',
            'is_active'      => 'nullable|boolean',
        ]);
        $data = $this->supplierData($b, true);
        $data['updated_at'] = date('Y-m-d H:i:s');
        Supplier::update($id, $data);
        $this->audit($request, 'SUPPLIER_UPDATED', 'suppliers', $id, $old, $data);
        Response::success($this->shaped($id), 'Supplier updated.');
    }

    public function destroy(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $old = Supplier::find($id);
        if (!$old) {
            throw new ApiException('Supplier not found.', 404);
        }
        $linked = Supplier::rawOne('SELECT COUNT(*) AS c FROM purchases WHERE supplier_id = :id AND deleted_at IS NULL', [':id' => $id])['c'] ?? 0;
        if ((int) $linked > 0) {
            throw new ApiException('Cannot delete: supplier has purchase history. Deactivate instead.', 422);
        }
        Supplier::delete($id);
        $this->audit($request, 'SUPPLIER_DELETED', 'suppliers', $id, $old, null);
        Response::success(null, 'Supplier deleted.');
    }

    public function ledger(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        if (!Supplier::find($id)) {
            throw new ApiException('Supplier not found.', 404);
        }
        $q = $this->query($request);
        Response::success(Supplier::ledger($id, $q['from'] ?? null, $q['to'] ?? null));
    }

    // ---------------------------------------------------------- internals
    /** Map frontend keys to real `suppliers` columns. */
    private function supplierData(array $b, bool $isUpdate = false): array
    {
        $data = $this->filtered($b, self::FILLABLE);
        if (isset($b['is_active']) && !isset($b['status'])) {
            $data['status'] = ((int) $b['is_active'] === 1) ? 'active' : 'inactive';
        }
        unset($data['is_active']);
        if (isset($data['opening_balance'])) {
            $data['opening_balance'] = $data['opening_balance'] === '' ? null : (float) $data['opening_balance'];
        }
        return $data;
    }

    /** One supplier row with the JSON keys the frontend reads (due). */
    private function shaped(int $id): ?array
    {
        $row = Supplier::rawOne(
            "SELECT s.*,
                    COALESCE((SELECT SUM(p.grand_total) FROM purchases p
                              WHERE p.supplier_id = s.id AND p.deleted_at IS NULL),0)
                    - COALESCE((SELECT SUM(pp.amount) FROM purchase_payments pp
                                JOIN purchases p2 ON p2.id = pp.purchase_id
                                WHERE p2.supplier_id = s.id),0) AS due
             FROM suppliers s WHERE s.id = :id AND s.deleted_at IS NULL LIMIT 1",
            [':id' => $id]
        );
        return $row ?: null;
    }
}
