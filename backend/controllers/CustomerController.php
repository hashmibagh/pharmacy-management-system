<?php
declare(strict_types=1);

namespace Pharmacy\Controllers;

use Pharmacy\Helpers\ApiException;
use Pharmacy\Helpers\Response;
use Pharmacy\Helpers\Validator;
use Pharmacy\Models\Customer;

class CustomerController extends BaseController
{
    /**
     * Frontend keys (Customers.jsx): name, phone, email, address, credit_limit, notes.
     * Mapped to real columns: notes→medical_notes, is_active→status.
     * `email` has no column and is ignored.
     */
    private const FILLABLE = [
        'name', 'phone', 'whatsapp', 'address', 'cnic',
        'credit_limit', 'opening_balance', 'medical_notes', 'status',
    ];

    public function index(array $request): void
    {
        $q = $this->query($request);
        [$page, $perPage] = Validator::pagination($q);
        $where = ['c.deleted_at IS NULL'];
        $params = [];
        $search = trim((string) ($q['q'] ?? $q['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(c.name LIKE :s OR c.phone LIKE :s OR c.cnic LIKE :s)';
            $params[':s'] = '%' . $search . '%';
        }
        $w = implode(' AND ', $where);
        $total = Customer::rawOne("SELECT COUNT(*) AS c FROM customers c WHERE {$w}", $params)['c'] ?? 0;
        $offset = ($page - 1) * $perPage;
        $data = Customer::raw(
            "SELECT c.*, c.medical_notes AS notes,
                    COALESCE((SELECT SUM(s.due_amount) FROM sales s
                              WHERE s.customer_id = c.id AND s.deleted_at IS NULL),0)
                    + COALESCE(c.opening_balance,0) AS due
             FROM customers c WHERE {$w} ORDER BY c.name ASC
             LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );
        Response::success([
            'data' => $data,
            'meta' => [
                'current_page' => $page, 'per_page' => $perPage,
                'total' => (int) $total, 'last_page' => (int) max(1, ceil($total / $perPage)),
            ],
        ]);
    }

    public function store(array $request): void
    {
        $b = $this->body($request);
        Validator::validate($b, [
            'name'         => 'required|string|max:150',
            'phone'        => 'nullable|string|max:30',
            'whatsapp'     => 'nullable|string|max:30',
            'address'      => 'nullable|string|max:500',
            'cnic'         => 'nullable|string|max:20',
            'credit_limit' => 'nullable|numeric',
            'notes'        => 'nullable|string|max:1000',
            'medical_notes'=> 'nullable|string|max:1000',
        ]);
        $data = $this->customerData($b);
        $data['reward_points'] = 0;
        $data['status'] = 'active';
        $data['created_at'] = date('Y-m-d H:i:s');
        $id = (int) Customer::create($data);
        $this->audit($request, 'CUSTOMER_CREATED', 'customers', $id, null, $data);
        Response::success($this->shaped($id), 'Customer created.', 201);
    }

    public function show(array $request): void
    {
        $c = $this->shaped((int) $this->param($request, 'id'));
        if (!$c) {
            throw new ApiException('Customer not found.', 404);
        }
        Response::success($c);
    }

    public function update(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $old = Customer::find($id);
        if (!$old) {
            throw new ApiException('Customer not found.', 404);
        }
        $b = $this->body($request);
        Validator::validate($b, [
            'name'         => 'required|string|max:150',
            'phone'        => 'nullable|string|max:30',
            'whatsapp'     => 'nullable|string|max:30',
            'address'      => 'nullable|string|max:500',
            'cnic'         => 'nullable|string|max:20',
            'credit_limit' => 'nullable|numeric',
            'notes'        => 'nullable|string|max:1000',
            'medical_notes'=> 'nullable|string|max:1000',
            'status'       => 'nullable|in:active,inactive',
            'is_active'    => 'nullable|boolean',
        ]);
        $data = $this->customerData($b, true);
        $data['updated_at'] = date('Y-m-d H:i:s');
        Customer::update($id, $data);
        $this->audit($request, 'CUSTOMER_UPDATED', 'customers', $id, $old, $data);
        Response::success($this->shaped($id), 'Customer updated.');
    }

    public function destroy(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $old = Customer::find($id);
        if (!$old) {
            throw new ApiException('Customer not found.', 404);
        }
        $linked = Customer::rawOne('SELECT COUNT(*) AS c FROM sales WHERE customer_id = :id AND deleted_at IS NULL', [':id' => $id])['c'] ?? 0;
        if ((int) $linked > 0) {
            throw new ApiException('Cannot delete: customer has sales history. Deactivate instead.', 422);
        }
        Customer::delete($id);
        $this->audit($request, 'CUSTOMER_DELETED', 'customers', $id, $old, null);
        Response::success(null, 'Customer deleted.');
    }

    public function ledger(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        if (!Customer::find($id)) {
            throw new ApiException('Customer not found.', 404);
        }
        $q = $this->query($request);
        Response::success(Customer::ledger($id, $q['from'] ?? null, $q['to'] ?? null));
    }

    /** Printable statement: customer info + ledger entries + totals. */
    public function statement(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $customer = $this->shaped($id);
        if (!$customer) {
            throw new ApiException('Customer not found.', 404);
        }
        $q = $this->query($request);
        $ledger = Customer::ledger($id, $q['from'] ?? null, $q['to'] ?? null);
        Response::success([
            'customer' => $customer,
            'from' => $q['from'] ?? null,
            'to'   => $q['to'] ?? null,
            'entries' => $ledger['entries'],
            'receivable' => $ledger['receivable'],
            'generated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Add or redeem reward points. */
    public function rewardPoints(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $customer = Customer::find($id);
        if (!$customer) {
            throw new ApiException('Customer not found.', 404);
        }
        $b = $this->body($request);
        Validator::validate($b, [
            'action' => 'required|in:add,redeem',
            'points' => 'required|integer',
        ]);
        $points = (int) $b['points'];
        if ($points <= 0) {
            throw new ApiException('Points must be positive.', 422);
        }
        $current = (int) ($customer['reward_points'] ?? 0);
        if ($b['action'] === 'redeem' && $points > $current) {
            throw new ApiException("Customer only has {$current} points.", 422);
        }
        $new = $b['action'] === 'add' ? $current + $points : $current - $points;
        Customer::update($id, ['reward_points' => $new]);
        $this->audit($request, 'REWARD_POINTS_' . strtoupper($b['action']), 'customers', $id,
            ['reward_points' => $current], ['reward_points' => $new]);
        Response::success(['reward_points' => $new], 'Reward points updated.');
    }

    // ---------------------------------------------------------- internals
    /** Map frontend keys to real `customers` columns. */
    private function customerData(array $b, bool $isUpdate = false): array
    {
        $data = $this->filtered($b, self::FILLABLE);
        if (array_key_exists('notes', $b)) {
            $data['medical_notes'] = trim((string) $b['notes']) !== '' ? trim((string) $b['notes']) : null;
        }
        if (isset($b['is_active']) && !isset($b['status'])) {
            $data['status'] = ((int) $b['is_active'] === 1) ? 'active' : 'inactive';
        }
        unset($data['notes'], $data['is_active']);
        if (isset($data['credit_limit'])) {
            $data['credit_limit'] = $data['credit_limit'] === '' ? null : (float) $data['credit_limit'];
        }
        if (isset($data['opening_balance'])) {
            $data['opening_balance'] = $data['opening_balance'] === '' ? null : (float) $data['opening_balance'];
        }
        return $data;
    }

    /** One customer row with the JSON keys the frontend reads (notes, due). */
    private function shaped(int $id): ?array
    {
        $row = Customer::rawOne(
            "SELECT c.*, c.medical_notes AS notes,
                    COALESCE((SELECT SUM(s.due_amount) FROM sales s
                              WHERE s.customer_id = c.id AND s.deleted_at IS NULL),0)
                    + COALESCE(c.opening_balance,0) AS due
             FROM customers c WHERE c.id = :id AND c.deleted_at IS NULL LIMIT 1",
            [':id' => $id]
        );
        return $row ?: null;
    }
}
