<?php
declare(strict_types=1);

namespace Pharmacy\Controllers;

use Pharmacy\Helpers\ApiException;
use Pharmacy\Helpers\FileUpload;
use Pharmacy\Helpers\Response;
use Pharmacy\Helpers\Validator;
use Pharmacy\Models\Prescription;

class PrescriptionController extends BaseController
{
    /**
     * Frontend keys (Prescriptions.jsx): customer_name, customer_phone, doctor_name, notes.
     * Mapped to real columns: customer_name→patient_name, customer_phone→patient_phone.
     */
    public function index(array $request): void
    {
        $q = $this->query($request);
        [$page, $perPage] = Validator::pagination($q);
        $where = ['1=1'];
        $params = [];
        $search = trim((string) ($q['q'] ?? $q['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(pr.patient_name LIKE :s OR pr.doctor_name LIKE :s OR pr.prescription_number LIKE :s)';
            $params[':s'] = '%' . $search . '%';
        }
        $w = implode(' AND ', $where);
        $total = Prescription::rawOne("SELECT COUNT(*) AS c FROM prescriptions pr WHERE {$w}", $params)['c'] ?? 0;
        $offset = ($page - 1) * $perPage;
        $data = Prescription::raw(
            "SELECT pr.*, pr.patient_name AS customer_name, pr.patient_phone AS customer_phone
             FROM prescriptions pr
             WHERE {$w} ORDER BY pr.id DESC
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
            'customer_name'  => 'nullable|string|max:150',
            'patient_name'   => 'nullable|string|max:150',
            'customer_phone' => 'nullable|string|max:30',
            'patient_phone'  => 'nullable|string|max:30',
            'doctor_name'    => 'nullable|string|max:150',
            'notes'          => 'nullable|string|max:2000',
        ]);
        $patientName = trim((string) ($b['patient_name'] ?? $b['customer_name'] ?? ''));
        if ($patientName === '') {
            throw new ApiException('Patient name is required.', 422, ['patient_name' => ['Patient name is required.']]);
        }

        $data = [
            'prescription_number' => $this->generateNumber(),
            'patient_name'        => $patientName,
            'patient_phone'       => trim((string) ($b['patient_phone'] ?? $b['customer_phone'] ?? '')) ?: null,
            'doctor_name'         => isset($b['doctor_name']) ? trim((string) $b['doctor_name']) : null,
            'notes'               => isset($b['notes']) ? trim((string) $b['notes']) : null,
            'created_by'          => $this->uid($request),
            'created_at'          => date('Y-m-d H:i:s'),
        ];

        // Prescription image/PDF → storage/prescriptions/ (via upload root).
        if (!empty($request['files']['file'])) {
            [$exts, $mimes] = FileUpload::documentRules();
            $data['image_path'] = FileUpload::store(
                $request['files']['file'], 'prescription', 'prescriptions', $exts, $mimes
            );
        }

        $id = (int) Prescription::create($data);
        $this->audit($request, 'PRESCRIPTION_CREATED', 'prescriptions', $id, null, ['patient' => $data['patient_name']]);
        Response::success(Prescription::findDetailed($id), 'Prescription saved.', 201);
    }

    public function show(array $request): void
    {
        $p = Prescription::findDetailed((int) $this->param($request, 'id'));
        if (!$p) {
            throw new ApiException('Prescription not found.', 404);
        }
        Response::success($p);
    }

    public function update(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $old = Prescription::find($id);
        if (!$old) {
            throw new ApiException('Prescription not found.', 404);
        }
        $b = $this->body($request);
        Validator::validate($b, [
            'customer_name'  => 'nullable|string|max:150',
            'patient_name'   => 'nullable|string|max:150',
            'customer_phone' => 'nullable|string|max:30',
            'patient_phone'  => 'nullable|string|max:30',
            'doctor_name'    => 'nullable|string|max:150',
            'doctor_phone'   => 'nullable|string|max:30',
            'clinic'         => 'nullable|string|max:150',
            'notes'          => 'nullable|string|max:2000',
        ]);
        $data = [];
        if (isset($b['patient_name']) || isset($b['customer_name'])) {
            $data['patient_name'] = trim((string) ($b['patient_name'] ?? $b['customer_name']));
        }
        if (isset($b['patient_phone']) || isset($b['customer_phone'])) {
            $data['patient_phone'] = trim((string) ($b['patient_phone'] ?? $b['customer_phone'])) ?: null;
        }
        foreach (['doctor_name', 'doctor_phone', 'clinic', 'notes'] as $f) {
            if (array_key_exists($f, $b)) {
                $v = is_string($b[$f]) ? trim($b[$f]) : $b[$f];
                $data[$f] = $v === '' ? null : $v;
            }
        }
        if (!empty($request['files']['file'])) {
            [$exts, $mimes] = FileUpload::documentRules();
            $data['image_path'] = FileUpload::store(
                $request['files']['file'], 'prescription', 'prescriptions', $exts, $mimes
            );
            FileUpload::delete($old['image_path'] ?? null);
        }
        if ($data) {
            $data['updated_at'] = date('Y-m-d H:i:s');
            Prescription::update($id, $data);
        }
        $this->audit($request, 'PRESCRIPTION_UPDATED', 'prescriptions', $id, $old, $data);
        Response::success(Prescription::findDetailed($id), 'Prescription updated.');
    }

    public function destroy(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $old = Prescription::find($id);
        if (!$old) {
            throw new ApiException('Prescription not found.', 404);
        }
        FileUpload::delete($old['image_path'] ?? null);
        FileUpload::delete($old['pdf_path'] ?? null);
        Prescription::delete($id);
        $this->audit($request, 'PRESCRIPTION_DELETED', 'prescriptions', $id, ['patient' => $old['patient_name']], null);
        Response::success(null, 'Prescription deleted.');
    }

    private function generateNumber(): string
    {
        return 'RX-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }
}
