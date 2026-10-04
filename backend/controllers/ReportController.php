<?php
declare(strict_types=1);

namespace Pharmacy\Controllers;

use Pharmacy\Helpers\ApiException;
use Pharmacy\Helpers\Response;
use Pharmacy\Helpers\Validator;
use Pharmacy\Services\ReportService;

/**
 * Read-only aggregate reports. All accept ?from&to&search&page&per_page.
 */
class ReportController extends BaseController
{
    public function sales(array $request): void
    {
        $this->validateDates($request);
        Response::success(ReportService::sales($this->query($request)));
    }

    public function purchases(array $request): void
    {
        $this->validateDates($request);
        Response::success(ReportService::purchases($this->query($request)));
    }

    public function profit(array $request): void
    {
        $this->validateDates($request);
        Response::success(ReportService::profit($this->query($request)));
    }

    public function inventory(array $request): void
    {
        Response::success(ReportService::inventory($this->query($request)));
    }

    public function expiry(array $request): void
    {
        Response::success(ReportService::expiry($this->query($request)));
    }

    public function expenses(array $request): void
    {
        $this->validateDates($request);
        Response::success(ReportService::expenses($this->query($request)));
    }

    /** /reports/custom?entity=sales|purchases|inventory|expiry|expenses
     *  (or ?summary=dashboard — the Dashboard page's fallback). */
    public function custom(array $request): void
    {
        $q = $this->query($request);
        if (($q['summary'] ?? '') === 'dashboard') {
            Response::success(ReportService::dashboard());
            return;
        }
        $entity = strtolower(trim((string) ($q['entity'] ?? '')));
        if ($entity === '') {
            throw new ApiException('Query param "entity" is required.', 422);
        }
        $this->validateDates($request);
        Response::success([
            'entity' => $entity,
            'report' => ReportService::custom($entity, $q),
        ]);
    }

    /** GET /api/dashboard — every KPI block at once for the Dashboard page. */
    public function dashboard(array $request): void
    {
        Response::success(ReportService::dashboard());
    }

    private function validateDates(array $request): void
    {
        $q = $this->query($request);
        Validator::validate($q, [
            'from' => 'nullable|date',
            'to'   => 'nullable|date',
        ]);
        if (!empty($q['from']) && !empty($q['to']) && $q['from'] > $q['to']) {
            throw new ApiException('"from" date cannot be after "to" date.', 422);
        }
    }
}
