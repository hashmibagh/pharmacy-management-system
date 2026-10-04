<?php
declare(strict_types=1);

namespace Pharmacy\Controllers;

use Pharmacy\Helpers\ApiException;
use Pharmacy\Helpers\Response;
use Pharmacy\Helpers\Validator;
use Pharmacy\Models\Manufacturer;

class ManufacturerController extends BaseController
{
    public function index(array $request): void
    {
        $q = $this->query($request);
        [$page, $perPage] = Validator::pagination($q);
        $where = '1=1';
        $params = [];
        if (!empty($q['search'])) {
            $where .= ' AND (name LIKE :s OR country LIKE :s)';
            $params[':s'] = '%' . trim((string) $q['search']) . '%';
        }
        Response::success(Manufacturer::paginate($page, $perPage, $where, $params, 'name ASC'));
    }

    public function store(array $request): void
    {
        $b = $this->body($request);
        Validator::validate($b, [
            'name'    => 'required|string|max:150',
            'country' => 'nullable|string|max:100',
            'phone'   => 'nullable|string|max:30',
            'email'   => 'nullable|email|max:190',
            'address' => 'nullable|string|max:500',
        ]);
        $id = (int) Manufacturer::create($this->filtered($b, ['name', 'country', 'phone', 'email', 'address']));
        $this->audit($request, 'MANUFACTURER_CREATED', 'manufacturers', $id);
        Response::success(Manufacturer::find($id), 'Manufacturer created.', 201);
    }

    public function show(array $request): void
    {
        $m = Manufacturer::find((int) $this->param($request, 'id'));
        if (!$m) {
            throw new ApiException('Manufacturer not found.', 404);
        }
        Response::success($m);
    }

    public function update(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $old = Manufacturer::find($id);
        if (!$old) {
            throw new ApiException('Manufacturer not found.', 404);
        }
        $b = $this->body($request);
        Validator::validate($b, [
            'name'    => 'required|string|max:150',
            'country' => 'nullable|string|max:100',
            'phone'   => 'nullable|string|max:30',
            'email'   => 'nullable|email|max:190',
            'address' => 'nullable|string|max:500',
        ]);
        Manufacturer::update($id, $this->filtered($b, ['name', 'country', 'phone', 'email', 'address']));
        $this->audit($request, 'MANUFACTURER_UPDATED', 'manufacturers', $id, $old, $b);
        Response::success(Manufacturer::find($id), 'Manufacturer updated.');
    }

    public function destroy(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $old = Manufacturer::find($id);
        if (!$old) {
            throw new ApiException('Manufacturer not found.', 404);
        }
        $inUse = Manufacturer::rawOne('SELECT COUNT(*) AS c FROM medicines WHERE manufacturer_id = :id', [':id' => $id])['c'] ?? 0;
        if ((int) $inUse > 0) {
            throw new ApiException('Cannot delete: manufacturer is used by medicines.', 422);
        }
        Manufacturer::delete($id);
        $this->audit($request, 'MANUFACTURER_DELETED', 'manufacturers', $id, $old, null);
        Response::success(null, 'Manufacturer deleted.');
    }
}
