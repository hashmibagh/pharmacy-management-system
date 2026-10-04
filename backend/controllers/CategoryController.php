<?php
declare(strict_types=1);

namespace Pharmacy\Controllers;

use Pharmacy\Helpers\ApiException;
use Pharmacy\Helpers\Response;
use Pharmacy\Helpers\Validator;
use Pharmacy\Models\Category;

class CategoryController extends BaseController
{
    public function index(array $request): void
    {
        $q = $this->query($request);
        [$page, $perPage] = Validator::pagination($q);
        $where = '1=1';
        $params = [];
        if (!empty($q['search'])) {
            $where .= ' AND name LIKE :s';
            $params[':s'] = '%' . trim((string) $q['search']) . '%';
        }
        Response::success(Category::paginate($page, $perPage, $where, $params, 'name ASC'));
    }

    public function store(array $request): void
    {
        $b = $this->body($request);
        Validator::validate($b, [
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:500',
        ]);
        if (Category::findBy('name', trim($b['name']))) {
            throw new ApiException('Category already exists.', 422, ['name' => ['Category already exists.']]);
        }
        $id = (int) Category::create($this->filtered($b, ['name', 'description']));
        $this->audit($request, 'CATEGORY_CREATED', 'categories', $id);
        Response::success(Category::find($id), 'Category created.', 201);
    }

    public function show(array $request): void
    {
        $cat = Category::find((int) $this->param($request, 'id'));
        if (!$cat) {
            throw new ApiException('Category not found.', 404);
        }
        Response::success($cat);
    }

    public function update(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $old = Category::find($id);
        if (!$old) {
            throw new ApiException('Category not found.', 404);
        }
        $b = $this->body($request);
        Validator::validate($b, [
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:500',
        ]);
        $dup = Category::findBy('name', trim($b['name']));
        if ($dup && (int) $dup['id'] !== $id) {
            throw new ApiException('Category already exists.', 422);
        }
        Category::update($id, $this->filtered($b, ['name', 'description']));
        $this->audit($request, 'CATEGORY_UPDATED', 'categories', $id, $old, $b);
        Response::success(Category::find($id), 'Category updated.');
    }

    public function destroy(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $old = Category::find($id);
        if (!$old) {
            throw new ApiException('Category not found.', 404);
        }
        $inUse = Category::rawOne('SELECT COUNT(*) AS c FROM medicines WHERE category_id = :id', [':id' => $id])['c'] ?? 0;
        if ((int) $inUse > 0) {
            throw new ApiException('Cannot delete: category is used by medicines.', 422);
        }
        Category::delete($id);
        $this->audit($request, 'CATEGORY_DELETED', 'categories', $id, $old, null);
        Response::success(null, 'Category deleted.');
    }
}
