<?php
declare(strict_types=1);

namespace Pharmacy\Controllers;

use Pharmacy\Helpers\ApiException;
use Pharmacy\Helpers\FileUpload;
use Pharmacy\Helpers\Response;
use Pharmacy\Helpers\Validator;
use Pharmacy\Models\Attendance;
use Pharmacy\Models\Employee;
use Pharmacy\Models\Leave;
use Pharmacy\Models\Salary;

class EmployeeController extends BaseController
{
    /** Frontend sends `status` (and legacy `is_active`); `notes` has no column. */
    private const FILLABLE = [
        'name', 'phone', 'email', 'address', 'cnic', 'designation',
        'joining_date', 'salary', 'commission', 'status',
    ];

    public function index(array $request): void
    {
        $q = $this->query($request);
        [$page, $perPage] = Validator::pagination($q);
        $where = ['deleted_at IS NULL'];
        $params = [];
        $search = trim((string) ($q['q'] ?? $q['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(name LIKE :s OR phone LIKE :s OR designation LIKE :s)';
            $params[':s'] = '%' . $search . '%';
        }
        $w = implode(' AND ', $where);
        $total = Employee::rawOne("SELECT COUNT(*) AS c FROM employees WHERE {$w}", $params)['c'] ?? 0;
        $offset = ($page - 1) * $perPage;
        $data = Employee::where($w, $params, 'name ASC', $perPage, $offset);
        foreach ($data as &$e) {
            $e = Employee::public($e);
        }
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
            'email'        => 'nullable|email|max:190',
            'designation'  => 'nullable|string|max:100',
            'salary'       => 'nullable|numeric',
            'joining_date' => 'nullable|date',
            'address'      => 'nullable|string|max:500',
            'status'       => 'nullable|in:active,inactive',
            'is_active'    => 'nullable|boolean',
        ]);
        $data = $this->employeeData($b);
        $data['created_at'] = date('Y-m-d H:i:s');

        // Optional photo on create (multipart).
        if (!empty($request['files']['photo'])) {
            [$exts, $mimes] = FileUpload::imageRules();
            $data['photo'] = FileUpload::store($request['files']['photo'], 'employee', 'employees', $exts, $mimes);
        }

        $id = (int) Employee::create($data);
        $this->audit($request, 'EMPLOYEE_CREATED', 'employees', $id, null, ['name' => $data['name']]);
        Response::success(Employee::public(Employee::find($id) ?? []), 'Employee added.', 201);
    }

    public function show(array $request): void
    {
        $e = Employee::find((int) $this->param($request, 'id'));
        if (!$e) {
            throw new ApiException('Employee not found.', 404);
        }
        Response::success(Employee::public($e));
    }

    public function update(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $old = Employee::find($id);
        if (!$old) {
            throw new ApiException('Employee not found.', 404);
        }
        $b = $this->body($request);
        Validator::validate($b, [
            'name'         => 'required|string|max:150',
            'phone'        => 'nullable|string|max:30',
            'email'        => 'nullable|email|max:190',
            'designation'  => 'nullable|string|max:100',
            'salary'       => 'nullable|numeric',
            'joining_date' => 'nullable|date',
            'address'      => 'nullable|string|max:500',
            'status'       => 'nullable|in:active,inactive',
            'is_active'    => 'nullable|boolean',
        ]);
        $data = $this->employeeData($b, true);
        if (!empty($request['files']['photo'])) {
            [$exts, $mimes] = FileUpload::imageRules();
            $data['photo'] = FileUpload::store($request['files']['photo'], 'employee', 'employees', $exts, $mimes);
            FileUpload::delete($old['photo'] ?? null);
        }
        Employee::update($id, $data);
        $this->audit($request, 'EMPLOYEE_UPDATED', 'employees', $id, Employee::public($old), $data);
        Response::success(Employee::public(Employee::find($id) ?? []), 'Employee updated.');
    }

    public function destroy(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $old = Employee::find($id);
        if (!$old) {
            throw new ApiException('Employee not found.', 404);
        }
        FileUpload::delete($old['photo'] ?? null);
        Employee::delete($id);
        $this->audit($request, 'EMPLOYEE_DELETED', 'employees', $id, Employee::public($old), null);
        Response::success(null, 'Employee removed.');
    }

    // ------------------------------------------------------------ attendance
    public function markAttendance(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        if (!Employee::find($id)) {
            throw new ApiException('Employee not found.', 404);
        }
        $b = $this->body($request);
        Validator::validate($b, [
            'date'            => 'nullable|date',
            'attendance_date' => 'nullable|date',
            'status'          => 'required|string|max:20',
            'check_in'        => 'nullable|string|max:8',
            'check_out'       => 'nullable|string|max:8',
            'notes'           => 'nullable|string|max:500',
        ]);
        $date = $b['attendance_date'] ?? $b['date'] ?? null;
        if (!$date) {
            throw new ApiException('Attendance date is required.', 422);
        }
        // Schema enum: present, absent, leave, half_day ('holiday' maps to leave).
        $status = strtolower(trim((string) $b['status']));
        if ($status === 'holiday') {
            $status = 'leave';
        }
        if (!in_array($status, ['present', 'absent', 'leave', 'half_day'], true)) {
            throw new ApiException('Invalid attendance status.', 422);
        }
        // Upsert for (employee_id, attendance_date).
        $existing = Attendance::rawOne(
            'SELECT id FROM attendance WHERE employee_id = :eid AND attendance_date = :d LIMIT 1',
            [':eid' => $id, ':d' => $date]
        );
        $data = [
            'employee_id'     => $id,
            'attendance_date' => $date,
            'status'          => $status,
            'check_in'        => $b['check_in'] ?? null,
            'check_out'       => $b['check_out'] ?? null,
            'notes'           => isset($b['notes']) ? trim((string) $b['notes']) : null,
        ];
        if ($existing) {
            Attendance::update((int) $existing['id'], $data);
            $attId = (int) $existing['id'];
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $attId = (int) Attendance::create($data);
        }
        $this->audit($request, 'ATTENDANCE_MARKED', 'employees', $attId, null, $data);
        Response::success(Attendance::find($attId), 'Attendance saved.');
    }

    public function attendance(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        if (!Employee::find($id)) {
            throw new ApiException('Employee not found.', 404);
        }
        $q = $this->query($request);
        [$page, $perPage] = Validator::pagination($q);
        $where = 'employee_id = :eid';
        $params = [':eid' => $id];
        if (!empty($q['month'])) { // YYYY-MM
            Validator::validate(['month' => $q['month']], ['month' => 'regex:/^\d{4}-\d{2}$/']);
            $where .= ' AND attendance_date LIKE :m';
            $params[':m'] = $q['month'] . '%';
        }
        $total = Attendance::rawOne("SELECT COUNT(*) AS c FROM attendance WHERE {$where}", $params)['c'] ?? 0;
        $offset = ($page - 1) * $perPage;
        $data = Attendance::raw(
            "SELECT *, attendance_date AS date FROM attendance WHERE {$where}
             ORDER BY attendance_date DESC LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );
        $result = [
            'data' => $data,
            'meta' => [
                'current_page' => $page, 'per_page' => $perPage,
                'total' => (int) $total, 'last_page' => (int) max(1, ceil($total / $perPage)),
            ],
        ];
        $result['summary'] = Attendance::raw(
            'SELECT status, COUNT(*) AS days FROM attendance WHERE ' . $where . ' GROUP BY status',
            $params
        );
        Response::success($result);
    }

    // ------------------------------------------------------------ salary
    public function paySalary(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $emp = Employee::find($id);
        if (!$emp) {
            throw new ApiException('Employee not found.', 404);
        }
        $b = $this->body($request);
        Validator::validate($b, [
            'month'        => 'required|regex:/^\d{4}-\d{2}$/',
            'basic'        => 'required|numeric',
            'basic_salary' => 'nullable|numeric',
            'allowances'   => 'nullable|numeric',
            'deductions'   => 'nullable|numeric',
            'payment_date' => 'nullable|date',
        ]);
        $basic = (float) ($b['basic'] ?? $b['basic_salary'] ?? 0);
        $allow = (float) ($b['allowances'] ?? 0);
        $deduct = (float) ($b['deductions'] ?? 0);
        $net = round($basic + $allow - $deduct, 2);
        if ($net < 0) {
            throw new ApiException('Net salary cannot be negative.', 422);
        }
        $dup = Salary::rawOne(
            'SELECT id FROM employee_salaries WHERE employee_id = :eid AND month = :m LIMIT 1',
            [':eid' => $id, ':m' => $b['month']]
        );
        if ($dup) {
            throw new ApiException('Salary for this month is already recorded.', 422);
        }
        $data = [
            'employee_id'    => $id,
            'month'          => $b['month'],
            'basic_salary'   => $basic,
            'allowances'     => $allow,
            'deductions'     => $deduct,
            'net_salary'     => $net,
            'paid_amount'    => $net,
            'payment_status' => 'paid',
            'payment_date'   => $b['payment_date'] ?? date('Y-m-d'),
            'created_at'     => date('Y-m-d H:i:s'),
        ];
        $salaryId = (int) Salary::create($data);
        $this->audit($request, 'SALARY_PAID', 'employees', $salaryId, null, $data);
        $row = Salary::find($salaryId) ?? [];
        $row['amount'] = $row['net_salary'] ?? 0;
        Response::success($row, 'Salary recorded.', 201);
    }

    public function salaries(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        if (!Employee::find($id)) {
            throw new ApiException('Employee not found.', 404);
        }
        $q = $this->query($request);
        [$page, $perPage] = Validator::pagination($q);
        $result = Salary::paginate($page, $perPage, 'employee_id = :eid', [':eid' => $id], 'month DESC');
        foreach ($result['data'] as &$s) {
            $s['amount'] = $s['net_salary'] ?? 0; // key the frontend reads
        }
        Response::success($result);
    }

    // ------------------------------------------------------------ leaves
    public function requestLeave(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        if (!Employee::find($id)) {
            throw new ApiException('Employee not found.', 404);
        }
        $b = $this->body($request);
        Validator::validate($b, [
            'from_date'  => 'nullable|date',
            'to_date'    => 'nullable|date',
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date',
            'leave_type' => 'required|string|max:50',
            'reason'     => 'nullable|string|max:1000',
        ]);
        $from = $b['start_date'] ?? $b['from_date'] ?? null;
        $to   = $b['end_date'] ?? $b['to_date'] ?? null;
        if (!$from || !$to) {
            throw new ApiException('Start and end dates are required.', 422);
        }
        if ($to < $from) {
            throw new ApiException('To-date cannot be before from-date.', 422);
        }
        $data = [
            'employee_id' => $id,
            'start_date'  => $from,
            'end_date'    => $to,
            'leave_type'  => trim((string) $b['leave_type']),
            'reason'      => isset($b['reason']) ? trim((string) $b['reason']) : null,
            'status'      => 'pending',
            'created_at'  => date('Y-m-d H:i:s'),
        ];
        $leaveId = (int) Leave::create($data);
        $this->audit($request, 'LEAVE_REQUESTED', 'employees', $leaveId, null, $data);
        Response::success(Leave::find($leaveId), 'Leave request submitted.', 201);
    }

    public function leaves(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        if (!Employee::find($id)) {
            throw new ApiException('Employee not found.', 404);
        }
        $q = $this->query($request);
        [$page, $perPage] = Validator::pagination($q);
        $where = 'employee_id = :eid';
        $params = [':eid' => $id];
        if (!empty($q['status'])) {
            $where .= ' AND status = :st';
            $params[':st'] = $q['status'];
        }
        Response::success(Leave::paginate($page, $perPage, $where, $params, 'start_date DESC'));
    }

    /** Approve / reject a leave request. */
    public function decideLeave(array $request): void
    {
        $id = (int) $this->param($request, 'id');
        $leaveId = (int) $this->param($request, 'leaveId');
        $leave = Leave::find($leaveId);
        if (!$leave || (int) $leave['employee_id'] !== $id) {
            throw new ApiException('Leave request not found.', 404);
        }
        $b = $this->body($request);
        Validator::validate($b, ['status' => 'required|in:approved,rejected']);
        Leave::update($leaveId, [
            'status'      => $b['status'],
            'approved_by' => $this->uid($request),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        $this->audit($request, 'LEAVE_' . strtoupper($b['status']), 'employees', $leaveId, $leave, $b);
        Response::success(Leave::find($leaveId), 'Leave ' . $b['status'] . '.');
    }

    // ---------------------------------------------------------- internals
    /** Map frontend keys to real `employees` columns. */
    private function employeeData(array $b, bool $isUpdate = false): array
    {
        $data = $this->filtered($b, self::FILLABLE);
        if (isset($b['is_active']) && !isset($b['status'])) {
            $data['status'] = ((int) $b['is_active'] === 1) ? 'active' : 'inactive';
        }
        unset($data['is_active']);
        if (!$isUpdate && !isset($data['status'])) {
            $data['status'] = 'active';
        }
        if (isset($data['salary'])) {
            $data['salary'] = $data['salary'] === '' ? null : (float) $data['salary'];
        }
        if (isset($data['joining_date']) && $data['joining_date'] === '') {
            $data['joining_date'] = null;
        }
        return $data;
    }
}
