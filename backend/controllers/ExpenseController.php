<?php
declare(strict_types=1);

namespace Pharmacy\Controllers;

use Pharmacy\Helpers\ApiException;
use Pharmacy\Helpers\Response;
use Pharmacy\Helpers\Validator;
use Pharmacy\Models\Expense;
use Pharmacy\Models\ExpenseCategory;

class ExpenseController extends BaseController
{
    // ------------------------------------------------------------ expenses
    public function index(array $request): void
    {
        $q = $this->query($request);
        [$page, $perPage] = Validator::pagination($q);
        Response::success(Expense::withCategory(
            $page, $perPage,
            $q['from'] ?? null, $q['to'] ?? null,
            isset($q['expense_category_id']) ? (int) $q['expense_category_id']
                : (isset($q['category_id']) ? (int) $q['category_id'] : null),
            trim((string) ($q['q'] ?? $q['search'] ?? ''))
        ));
    }

    public function store(array $request): void
    {
        $b = $this->body($request);
        Validator::validate($b, [
            'expense_category_id' => 'nullable|integer',
            'category_id'         => 'nullable|integer',
            'amount'              => 'required|numeric',
            'expense_date'        => 'nullable|date',
            'date'                => 'nullable|date',
            'title'               => 'nullable|string|max:200',
            'description'         => 'nullable|string|max:2000',
            'note'                => 'nullable|string|max:1000',
            'notes'               => 'nullable|string|max:1000',
            'payment_method'      => 'nullable|string|max:50',
        ]);
        $categoryId = $b['expense_category_id'] ?? $b['category_id'] ?? null;
        if (!$categoryId || !ExpenseCategory::find((int) $categoryId)) {
            throw new ApiException('Expense category not found.', 404);
        }
        if ((float) $b['amount'] <= 0) {
            throw new ApiException('Amount must be greater than zero.', 422);
        }
        $expenseDate = $b['expense_date'] ?? $b['date'] ?? null;
        if (!$expenseDate) {
            throw new ApiException('Expense date is required.', 422, ['date' => ['Expense date is required.']]);
        }
        $data = [
            'expense_category_id' => (int) $categoryId,
            'amount'              => (float) $b['amount'],
            'expense_date'        => $expenseDate,
            'description'         => $this->combineDescription($b),
            'payment_method'      => (string) ($b['payment_method'] ?? 'cash'),
            'created_by'          => $this->uid($request),
            'created_at'          => date('Y-m-d H:i:s'),
        ];
        $id = (int) Expense::create($data);
        $this->audit($request, 'EXPENSE_CREATED', 'expenses', $id, null, $data);
        Response::success(Expense::shape(Expense::find($id) ?? []), 'Expense recorded.', 201);
    }

    public function show(array $request): void
    {
        $e = Expense::find((int) $this->param($request, 'id'));
        if (!$e) {
            throw new ApiException('Expense not found.', 404);
        }
        Response::success(Expense::shape($e));
    }

    public function update(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $old = Expense::find($id);
        if (!$old) {
            throw new ApiException('Expense not found.', 404);
        }
        $b = $this->body($request);
        Validator::validate($b, [
            'expense_category_id' => 'nullable|integer',
            'category_id'         => 'nullable|integer',
            'amount'              => 'nullable|numeric',
            'expense_date'        => 'nullable|date',
            'date'                => 'nullable|date',
            'title'               => 'nullable|string|max:200',
            'description'         => 'nullable|string|max:2000',
            'note'                => 'nullable|string|max:1000',
            'notes'               => 'nullable|string|max:1000',
            'payment_method'      => 'nullable|string|max:50',
        ]);
        $data = [];
        $categoryId = $b['expense_category_id'] ?? $b['category_id'] ?? null;
        if ($categoryId) {
            if (!ExpenseCategory::find((int) $categoryId)) {
                throw new ApiException('Expense category not found.', 404);
            }
            $data['expense_category_id'] = (int) $categoryId;
        }
        if (isset($b['amount'])) {
            $data['amount'] = (float) $b['amount'];
        }
        if (isset($b['expense_date']) || isset($b['date'])) {
            $data['expense_date'] = $b['expense_date'] ?? $b['date'];
        }
        if (isset($b['title']) || isset($b['description']) || isset($b['note']) || isset($b['notes'])) {
            $data['description'] = $this->combineDescription($b);
        }
        if (isset($b['payment_method'])) {
            $data['payment_method'] = (string) $b['payment_method'];
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        Expense::update($id, $data);
        $this->audit($request, 'EXPENSE_UPDATED', 'expenses', $id, $old, $data);
        Response::success(Expense::shape(Expense::find($id) ?? []), 'Expense updated.');
    }

    public function destroy(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $old = Expense::find($id);
        if (!$old) {
            throw new ApiException('Expense not found.', 404);
        }
        Expense::delete($id);
        $this->audit($request, 'EXPENSE_DELETED', 'expenses', $id, $old, null);
        Response::success(null, 'Expense deleted.');
    }

    // ------------------------------------------------------------ categories
    public function categories(array $request): void
    {
        $q = $this->query($request);
        [$page, $perPage] = Validator::pagination($q);
        Response::success(ExpenseCategory::paginate($page, $perPage, '1=1', [], 'name ASC'));
    }

    public function storeCategory(array $request): void
    {
        $b = $this->body($request);
        Validator::validate($b, [
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:500',
        ]);
        if (ExpenseCategory::findBy('name', trim($b['name']))) {
            throw new ApiException('Category already exists.', 422);
        }
        $id = (int) ExpenseCategory::create($this->filtered($b, ['name', 'description']));
        $this->audit($request, 'EXPENSE_CATEGORY_CREATED', 'expenses', $id);
        Response::success(ExpenseCategory::find($id), 'Expense category created.', 201);
    }

    public function updateCategory(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $old = ExpenseCategory::find($id);
        if (!$old) {
            throw new ApiException('Expense category not found.', 404);
        }
        $b = $this->body($request);
        Validator::validate($b, [
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:500',
        ]);
        ExpenseCategory::update($id, $this->filtered($b, ['name', 'description']));
        $this->audit($request, 'EXPENSE_CATEGORY_UPDATED', 'expenses', $id, $old, $b);
        Response::success(ExpenseCategory::find($id), 'Expense category updated.');
    }

    public function destroyCategory(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $old = ExpenseCategory::find($id);
        if (!$old) {
            throw new ApiException('Expense category not found.', 404);
        }
        $inUse = ExpenseCategory::rawOne('SELECT COUNT(*) AS c FROM expenses WHERE expense_category_id = :id', [':id' => $id])['c'] ?? 0;
        if ((int) $inUse > 0) {
            throw new ApiException('Cannot delete: category has expenses.', 422);
        }
        ExpenseCategory::delete($id);
        $this->audit($request, 'EXPENSE_CATEGORY_DELETED', 'expenses', $id, $old, null);
        Response::success(null, 'Expense category deleted.');
    }

    // ---------------------------------------------------------- internals
    /**
     * The frontend sends `title` + `note`; the schema has one `description`
     * column. Store title on the first line and the note after it, so the
     * read path can split them back apart (Expense::shape).
     */
    private function combineDescription(array $b): string
    {
        $title = trim((string) ($b['title'] ?? ''));
        $note  = trim((string) ($b['note'] ?? $b['notes'] ?? ''));
        if (isset($b['description']) && trim((string) $b['description']) !== '') {
            return trim((string) $b['description']);
        }
        if ($title === '' && $note === '') {
            return '';
        }
        return $note !== '' ? $title . "\n" . $note : $title;
    }
}
