<?php
declare(strict_types=1);

/**
 * Route map: METHOD + path → controller@method.
 *
 * Fields:
 *   method      HTTP verb
 *   path        /api/... with {param} placeholders
 *   handler     [ControllerClass, method]
 *   auth        require authentication (default false)
 *   permissions permission strings checked by PermissionMiddleware
 *               (Super Admin bypasses all)
 *   rate        [maxAttempts, windowSeconds] file/IP-based rate limit
 *   csrf        enforce CSRF token (session mode only; JWT is immune)
 *
 * NOTE: permissions are enforced in middleware, never inline in controllers.
 */

use Pharmacy\Controllers\AuditLogController;
use Pharmacy\Controllers\AuthController;
use Pharmacy\Controllers\CategoryController;
use Pharmacy\Controllers\CustomerController;
use Pharmacy\Controllers\EmployeeController;
use Pharmacy\Controllers\ExpenseController;
use Pharmacy\Controllers\ExportController;
use Pharmacy\Controllers\FileController;
use Pharmacy\Controllers\InventoryController;
use Pharmacy\Controllers\ManufacturerController;
use Pharmacy\Controllers\MedicineController;
use Pharmacy\Controllers\NotificationController;
use Pharmacy\Controllers\PrescriptionController;
use Pharmacy\Controllers\PurchaseController;
use Pharmacy\Controllers\ReportController;
use Pharmacy\Controllers\RoleController;
use Pharmacy\Controllers\SaleController;
use Pharmacy\Controllers\SettingsController;
use Pharmacy\Controllers\SupplierController;
use Pharmacy\Controllers\UserController;

return [
    // ------------------------------------------------------------- auth
    ['method' => 'POST', 'path' => '/api/auth/login',           'handler' => [AuthController::class, 'login'],          'rate' => [5, 60]],
    ['method' => 'POST', 'path' => '/api/auth/logout',          'handler' => [AuthController::class, 'logout'],         'auth' => true],
    ['method' => 'GET',  'path' => '/api/auth/me',              'handler' => [AuthController::class, 'me'],             'auth' => true],
    ['method' => 'POST', 'path' => '/api/auth/refresh',         'handler' => [AuthController::class, 'refresh']],
    ['method' => 'POST', 'path' => '/api/auth/forgot-password', 'handler' => [AuthController::class, 'forgotPassword'], 'rate' => [5, 60]],
    ['method' => 'POST', 'path' => '/api/auth/reset-password',  'handler' => [AuthController::class, 'resetPassword'],  'rate' => [5, 60]],
    ['method' => 'POST', 'path' => '/api/auth/change-password', 'handler' => [AuthController::class, 'changePassword'], 'auth' => true],
    ['method' => 'GET',  'path' => '/api/auth/profile',         'handler' => [AuthController::class, 'getProfile'],     'auth' => true],
    ['method' => 'PUT',  'path' => '/api/auth/profile',         'handler' => [AuthController::class, 'updateProfile'],  'auth' => true],
    ['method' => 'POST', 'path' => '/api/auth/profile-photo',   'handler' => [AuthController::class, 'uploadPhoto'],    'auth' => true],

    // ------------------------------------------------------------- medicines
    ['method' => 'GET',  'path' => '/api/medicines',                 'handler' => [MedicineController::class, 'index'],     'auth' => true, 'permissions' => ['medicines.view']],
    ['method' => 'POST', 'path' => '/api/medicines',                 'handler' => [MedicineController::class, 'store'],     'auth' => true, 'permissions' => ['medicines.create']],
    ['method' => 'POST', 'path' => '/api/medicines/import',          'handler' => [MedicineController::class, 'import'],    'auth' => true, 'permissions' => ['medicines.create']],
    ['method' => 'GET',  'path' => '/api/medicines/export',          'handler' => [MedicineController::class, 'export'],    'auth' => true, 'permissions' => ['medicines.view']],
    ['method' => 'GET',  'path' => '/api/medicines/{id}',            'handler' => [MedicineController::class, 'show'],      'auth' => true, 'permissions' => ['medicines.view']],
    ['method' => 'PUT',  'path' => '/api/medicines/{id}',            'handler' => [MedicineController::class, 'update'],    'auth' => true, 'permissions' => ['medicines.edit']],
    ['method' => 'DELETE', 'path' => '/api/medicines/{id}',          'handler' => [MedicineController::class, 'destroy'],   'auth' => true, 'permissions' => ['medicines.delete']],
    ['method' => 'POST', 'path' => '/api/medicines/{id}/duplicate',  'handler' => [MedicineController::class, 'duplicate'], 'auth' => true, 'permissions' => ['medicines.create']],
    ['method' => 'GET',  'path' => '/api/medicines/{id}/barcode',    'handler' => [MedicineController::class, 'barcode'],   'auth' => true, 'permissions' => ['medicines.view']],
    ['method' => 'GET',  'path' => '/api/medicines/{id}/history',    'handler' => [MedicineController::class, 'history'],   'auth' => true, 'permissions' => ['medicines.view']],

    // ------------------------------------------------------------- categories
    ['method' => 'GET',    'path' => '/api/categories',        'handler' => [CategoryController::class, 'index'],   'auth' => true, 'permissions' => ['medicines.view']],
    ['method' => 'POST',   'path' => '/api/categories',        'handler' => [CategoryController::class, 'store'],   'auth' => true, 'permissions' => ['medicines.create']],
    ['method' => 'GET',    'path' => '/api/categories/{id}',   'handler' => [CategoryController::class, 'show'],    'auth' => true, 'permissions' => ['medicines.view']],
    ['method' => 'PUT',    'path' => '/api/categories/{id}',   'handler' => [CategoryController::class, 'update'],  'auth' => true, 'permissions' => ['medicines.edit']],
    ['method' => 'DELETE', 'path' => '/api/categories/{id}',   'handler' => [CategoryController::class, 'destroy'], 'auth' => true, 'permissions' => ['medicines.delete']],

    // ------------------------------------------------------------- manufacturers
    ['method' => 'GET',    'path' => '/api/manufacturers',      'handler' => [ManufacturerController::class, 'index'],   'auth' => true, 'permissions' => ['medicines.view']],
    ['method' => 'POST',   'path' => '/api/manufacturers',      'handler' => [ManufacturerController::class, 'store'],   'auth' => true, 'permissions' => ['medicines.create']],
    ['method' => 'GET',    'path' => '/api/manufacturers/{id}', 'handler' => [ManufacturerController::class, 'show'],    'auth' => true, 'permissions' => ['medicines.view']],
    ['method' => 'PUT',    'path' => '/api/manufacturers/{id}', 'handler' => [ManufacturerController::class, 'update'],  'auth' => true, 'permissions' => ['medicines.edit']],
    ['method' => 'DELETE', 'path' => '/api/manufacturers/{id}', 'handler' => [ManufacturerController::class, 'destroy'], 'auth' => true, 'permissions' => ['medicines.delete']],

    // ------------------------------------------------------------- inventory
    ['method' => 'GET',  'path' => '/api/inventory',            'handler' => [InventoryController::class, 'index'],        'auth' => true, 'permissions' => ['inventory.view']],
    ['method' => 'POST', 'path' => '/api/inventory/adjust',     'handler' => [InventoryController::class, 'adjust'],       'auth' => true, 'permissions' => ['inventory.adjust']],
    ['method' => 'POST', 'path' => '/api/inventory/transfer',   'handler' => [InventoryController::class, 'transfer'],     'auth' => true, 'permissions' => ['inventory.transfer']],
    ['method' => 'POST', 'path' => '/api/inventory/stock-in',   'handler' => [InventoryController::class, 'stockIn'],      'auth' => true, 'permissions' => ['inventory.adjust']],
    ['method' => 'POST', 'path' => '/api/inventory/stock-out',  'handler' => [InventoryController::class, 'stockOut'],     'auth' => true, 'permissions' => ['inventory.adjust']],
    ['method' => 'POST', 'path' => '/api/inventory/opening-stock', 'handler' => [InventoryController::class, 'openingStock'], 'auth' => true, 'permissions' => ['inventory.adjust']],
    ['method' => 'POST', 'path' => '/api/inventory/verification', 'handler' => [InventoryController::class, 'verification'], 'auth' => true, 'permissions' => ['inventory.adjust']],
    ['method' => 'GET',  'path' => '/api/inventory/valuation',  'handler' => [InventoryController::class, 'valuation'],    'auth' => true, 'permissions' => ['inventory.view']],
    ['method' => 'GET',  'path' => '/api/inventory/low-stock',  'handler' => [InventoryController::class, 'lowStock'],     'auth' => true, 'permissions' => ['inventory.view']],
    ['method' => 'GET',  'path' => '/api/inventory/expiring',   'handler' => [InventoryController::class, 'expiring'],     'auth' => true, 'permissions' => ['inventory.view']],
    ['method' => 'GET',  'path' => '/api/inventory/history',    'handler' => [InventoryController::class, 'history'],      'auth' => true, 'permissions' => ['inventory.view']],

    // ------------------------------------------------------------- purchases
    ['method' => 'GET',    'path' => '/api/purchases',                'handler' => [PurchaseController::class, 'index'],       'auth' => true, 'permissions' => ['purchases.view']],
    ['method' => 'POST',   'path' => '/api/purchases',                'handler' => [PurchaseController::class, 'store'],       'auth' => true, 'permissions' => ['purchases.create']],
    ['method' => 'GET',    'path' => '/api/purchases/{id}',           'handler' => [PurchaseController::class, 'show'],        'auth' => true, 'permissions' => ['purchases.view']],
    ['method' => 'PUT',    'path' => '/api/purchases/{id}',           'handler' => [PurchaseController::class, 'update'],      'auth' => true, 'permissions' => ['purchases.edit']],
    ['method' => 'DELETE', 'path' => '/api/purchases/{id}',           'handler' => [PurchaseController::class, 'destroy'],     'auth' => true, 'permissions' => ['purchases.delete']],
    ['method' => 'POST',   'path' => '/api/purchases/{id}/payments',  'handler' => [PurchaseController::class, 'addPayment'],   'auth' => true, 'permissions' => ['purchases.create']],
    ['method' => 'POST',   'path' => '/api/purchases/{id}/return',    'handler' => [PurchaseController::class, 'returnPurchase'], 'auth' => true, 'permissions' => ['purchases.return']],

    // ------------------------------------------------------------- sales (POS)
    ['method' => 'GET',  'path' => '/api/sales',             'handler' => [SaleController::class, 'index'],      'auth' => true, 'permissions' => ['sales.view']],
    ['method' => 'POST', 'path' => '/api/sales',             'handler' => [SaleController::class, 'store'],      'auth' => true, 'permissions' => ['sales.create']],
    ['method' => 'GET',  'path' => '/api/sales/{id}',         'handler' => [SaleController::class, 'show'],       'auth' => true, 'permissions' => ['sales.view']],
    ['method' => 'POST', 'path' => '/api/sales/{id}/return',  'handler' => [SaleController::class, 'returnSale'], 'auth' => true, 'permissions' => ['sales.return']],

    // ------------------------------------------------------------- customers
    ['method' => 'GET',    'path' => '/api/customers',                        'handler' => [CustomerController::class, 'index'],         'auth' => true, 'permissions' => ['customers.view']],
    ['method' => 'POST',   'path' => '/api/customers',                        'handler' => [CustomerController::class, 'store'],         'auth' => true, 'permissions' => ['customers.create']],
    ['method' => 'GET',    'path' => '/api/customers/{id}',                   'handler' => [CustomerController::class, 'show'],          'auth' => true, 'permissions' => ['customers.view']],
    ['method' => 'PUT',    'path' => '/api/customers/{id}',                   'handler' => [CustomerController::class, 'update'],        'auth' => true, 'permissions' => ['customers.edit']],
    ['method' => 'DELETE', 'path' => '/api/customers/{id}',                   'handler' => [CustomerController::class, 'destroy'],       'auth' => true, 'permissions' => ['customers.delete']],
    ['method' => 'GET',    'path' => '/api/customers/{id}/ledger',            'handler' => [CustomerController::class, 'ledger'],        'auth' => true, 'permissions' => ['customers.view']],
    ['method' => 'GET',    'path' => '/api/customers/{id}/statement',         'handler' => [CustomerController::class, 'statement'],     'auth' => true, 'permissions' => ['customers.view']],
    ['method' => 'POST',   'path' => '/api/customers/{id}/reward-points',     'handler' => [CustomerController::class, 'rewardPoints'],  'auth' => true, 'permissions' => ['customers.edit']],

    // ------------------------------------------------------------- suppliers
    ['method' => 'GET',    'path' => '/api/suppliers',            'handler' => [SupplierController::class, 'index'],   'auth' => true, 'permissions' => ['suppliers.view']],
    ['method' => 'POST',   'path' => '/api/suppliers',            'handler' => [SupplierController::class, 'store'],   'auth' => true, 'permissions' => ['suppliers.create']],
    ['method' => 'GET',    'path' => '/api/suppliers/{id}',       'handler' => [SupplierController::class, 'show'],    'auth' => true, 'permissions' => ['suppliers.view']],
    ['method' => 'PUT',    'path' => '/api/suppliers/{id}',       'handler' => [SupplierController::class, 'update'],  'auth' => true, 'permissions' => ['suppliers.edit']],
    ['method' => 'DELETE', 'path' => '/api/suppliers/{id}',       'handler' => [SupplierController::class, 'destroy'], 'auth' => true, 'permissions' => ['suppliers.delete']],
    ['method' => 'GET',    'path' => '/api/suppliers/{id}/ledger','handler' => [SupplierController::class, 'ledger'],  'auth' => true, 'permissions' => ['suppliers.view']],

    // ------------------------------------------------------------- expenses
    ['method' => 'GET',    'path' => '/api/expenses',             'handler' => [ExpenseController::class, 'index'],   'auth' => true, 'permissions' => ['expenses.view']],
    ['method' => 'POST',   'path' => '/api/expenses',             'handler' => [ExpenseController::class, 'store'],   'auth' => true, 'permissions' => ['expenses.create']],
    ['method' => 'GET',    'path' => '/api/expenses/{id}',        'handler' => [ExpenseController::class, 'show'],    'auth' => true, 'permissions' => ['expenses.view']],
    ['method' => 'PUT',    'path' => '/api/expenses/{id}',        'handler' => [ExpenseController::class, 'update'],  'auth' => true, 'permissions' => ['expenses.edit']],
    ['method' => 'DELETE', 'path' => '/api/expenses/{id}',        'handler' => [ExpenseController::class, 'destroy'], 'auth' => true, 'permissions' => ['expenses.delete']],
    ['method' => 'GET',    'path' => '/api/expense-categories',          'handler' => [ExpenseController::class, 'categories'],      'auth' => true, 'permissions' => ['expenses.view']],
    ['method' => 'POST',   'path' => '/api/expense-categories',          'handler' => [ExpenseController::class, 'storeCategory'],   'auth' => true, 'permissions' => ['expenses.create']],
    ['method' => 'PUT',    'path' => '/api/expense-categories/{id}',     'handler' => [ExpenseController::class, 'updateCategory'],  'auth' => true, 'permissions' => ['expenses.edit']],
    ['method' => 'DELETE', 'path' => '/api/expense-categories/{id}',     'handler' => [ExpenseController::class, 'destroyCategory'], 'auth' => true, 'permissions' => ['expenses.delete']],

    // ------------------------------------------------------------- employees
    ['method' => 'GET',    'path' => '/api/employees',                              'handler' => [EmployeeController::class, 'index'],          'auth' => true, 'permissions' => ['employees.view']],
    ['method' => 'POST',   'path' => '/api/employees',                              'handler' => [EmployeeController::class, 'store'],          'auth' => true, 'permissions' => ['employees.manage']],
    ['method' => 'GET',    'path' => '/api/employees/{id}',                         'handler' => [EmployeeController::class, 'show'],           'auth' => true, 'permissions' => ['employees.view']],
    ['method' => 'PUT',    'path' => '/api/employees/{id}',                         'handler' => [EmployeeController::class, 'update'],         'auth' => true, 'permissions' => ['employees.manage']],
    ['method' => 'DELETE', 'path' => '/api/employees/{id}',                         'handler' => [EmployeeController::class, 'destroy'],        'auth' => true, 'permissions' => ['employees.manage']],
    ['method' => 'POST',   'path' => '/api/employees/{id}/attendance',              'handler' => [EmployeeController::class, 'markAttendance'], 'auth' => true, 'permissions' => ['employees.manage']],
    ['method' => 'GET',    'path' => '/api/employees/{id}/attendance',              'handler' => [EmployeeController::class, 'attendance'],     'auth' => true, 'permissions' => ['employees.view']],
    ['method' => 'POST',   'path' => '/api/employees/{id}/salary',                  'handler' => [EmployeeController::class, 'paySalary'],      'auth' => true, 'permissions' => ['employees.manage']],
    ['method' => 'GET',    'path' => '/api/employees/{id}/salary',                  'handler' => [EmployeeController::class, 'salaries'],       'auth' => true, 'permissions' => ['employees.view']],
    ['method' => 'POST',   'path' => '/api/employees/{id}/leaves',                  'handler' => [EmployeeController::class, 'requestLeave'],   'auth' => true, 'permissions' => ['employees.manage']],
    ['method' => 'GET',    'path' => '/api/employees/{id}/leaves',                  'handler' => [EmployeeController::class, 'leaves'],         'auth' => true, 'permissions' => ['employees.view']],
    ['method' => 'PUT',    'path' => '/api/employees/{id}/leaves/{leaveId}',        'handler' => [EmployeeController::class, 'decideLeave'],    'auth' => true, 'permissions' => ['employees.manage']],

    // ------------------------------------------------------------- prescriptions
    ['method' => 'GET',    'path' => '/api/prescriptions',        'handler' => [PrescriptionController::class, 'index'],   'auth' => true, 'permissions' => ['prescriptions.view']],
    ['method' => 'POST',   'path' => '/api/prescriptions',        'handler' => [PrescriptionController::class, 'store'],   'auth' => true, 'permissions' => ['prescriptions.create']],
    ['method' => 'GET',    'path' => '/api/prescriptions/{id}',   'handler' => [PrescriptionController::class, 'show'],    'auth' => true, 'permissions' => ['prescriptions.view']],
    ['method' => 'PUT',    'path' => '/api/prescriptions/{id}',   'handler' => [PrescriptionController::class, 'update'],  'auth' => true, 'permissions' => ['prescriptions.create']],
    ['method' => 'DELETE', 'path' => '/api/prescriptions/{id}',   'handler' => [PrescriptionController::class, 'destroy'], 'auth' => true, 'permissions' => ['prescriptions.create']],

    // ------------------------------------------------------------- reports
    ['method' => 'GET', 'path' => '/api/dashboard',         'handler' => [ReportController::class, 'dashboard'],   'auth' => true, 'permissions' => ['dashboard.view']],
    ['method' => 'GET', 'path' => '/api/reports/sales',     'handler' => [ReportController::class, 'sales'],     'auth' => true, 'permissions' => ['reports.view']],
    ['method' => 'GET', 'path' => '/api/reports/purchases', 'handler' => [ReportController::class, 'purchases'], 'auth' => true, 'permissions' => ['reports.view']],
    ['method' => 'GET', 'path' => '/api/reports/profit',    'handler' => [ReportController::class, 'profit'],    'auth' => true, 'permissions' => ['reports.view']],
    ['method' => 'GET', 'path' => '/api/reports/inventory', 'handler' => [ReportController::class, 'inventory'], 'auth' => true, 'permissions' => ['reports.view']],
    ['method' => 'GET', 'path' => '/api/reports/expiry',    'handler' => [ReportController::class, 'expiry'],    'auth' => true, 'permissions' => ['reports.view']],
    ['method' => 'GET', 'path' => '/api/reports/expenses',  'handler' => [ReportController::class, 'expenses'],  'auth' => true, 'permissions' => ['reports.view']],
    ['method' => 'GET', 'path' => '/api/reports/custom',    'handler' => [ReportController::class, 'custom'],    'auth' => true, 'permissions' => ['reports.view']],

    // ------------------------------------------------------------- exports
    ['method' => 'GET', 'path' => '/api/export/pdf',   'handler' => [ExportController::class, 'pdf'],   'auth' => true, 'permissions' => ['reports.export']],
    ['method' => 'GET', 'path' => '/api/export/excel', 'handler' => [ExportController::class, 'excel'], 'auth' => true, 'permissions' => ['reports.export']],

    // ------------------------------------------------------------- settings
    ['method' => 'GET', 'path' => '/api/settings', 'handler' => [SettingsController::class, 'index'],  'auth' => true, 'permissions' => ['settings.view']],
    ['method' => 'PUT', 'path' => '/api/settings', 'handler' => [SettingsController::class, 'update'], 'auth' => true, 'permissions' => ['settings.manage']],

    // ------------------------------------------------------------- notifications
    ['method' => 'GET', 'path' => '/api/notifications',            'handler' => [NotificationController::class, 'index'],       'auth' => true],
    ['method' => 'POST', 'path' => '/api/notifications/read-all',  'handler' => [NotificationController::class, 'markAllRead'], 'auth' => true],
    ['method' => 'PUT', 'path' => '/api/notifications/{id}/read',  'handler' => [NotificationController::class, 'markRead'],    'auth' => true],

    // ------------------------------------------------------------- audit logs (read-only)
    ['method' => 'GET', 'path' => '/api/audit-logs', 'handler' => [AuditLogController::class, 'index'], 'auth' => true, 'permissions' => ['audit_logs.view']],

    // ------------------------------------------------------------- users & roles
    ['method' => 'GET',    'path' => '/api/users',                      'handler' => [UserController::class, 'index'],             'auth' => true, 'permissions' => ['users.view']],
    ['method' => 'POST',   'path' => '/api/users',                      'handler' => [UserController::class, 'store'],             'auth' => true, 'permissions' => ['users.manage']],
    ['method' => 'GET',    'path' => '/api/users/{id}',                 'handler' => [UserController::class, 'show'],              'auth' => true, 'permissions' => ['users.view']],
    ['method' => 'PUT',    'path' => '/api/users/{id}',                 'handler' => [UserController::class, 'update'],            'auth' => true, 'permissions' => ['users.manage']],
    ['method' => 'DELETE', 'path' => '/api/users/{id}',                 'handler' => [UserController::class, 'destroy'],           'auth' => true, 'permissions' => ['users.manage']],
    ['method' => 'PUT',    'path' => '/api/users/{id}/permissions',     'handler' => [UserController::class, 'updatePermissions'], 'auth' => true, 'permissions' => ['users.manage']],

    ['method' => 'GET',    'path' => '/api/roles',                      'handler' => [RoleController::class, 'index'],             'auth' => true, 'permissions' => ['users.view']],
    ['method' => 'GET',    'path' => '/api/roles/permissions/catalog',  'handler' => [RoleController::class, 'catalog'],           'auth' => true, 'permissions' => ['users.view']],
    ['method' => 'POST',   'path' => '/api/roles',                      'handler' => [RoleController::class, 'store'],             'auth' => true, 'permissions' => ['users.manage']],
    ['method' => 'GET',    'path' => '/api/roles/{id}',                 'handler' => [RoleController::class, 'show'],              'auth' => true, 'permissions' => ['users.view']],
    ['method' => 'PUT',    'path' => '/api/roles/{id}',                 'handler' => [RoleController::class, 'update'],            'auth' => true, 'permissions' => ['users.manage']],
    ['method' => 'DELETE', 'path' => '/api/roles/{id}',                 'handler' => [RoleController::class, 'destroy'],           'auth' => true, 'permissions' => ['users.manage']],
    ['method' => 'PUT',    'path' => '/api/roles/{id}/permissions',     'handler' => [RoleController::class, 'updatePermissions'], 'auth' => true, 'permissions' => ['users.manage']],

    // ------------------------------------------------------------- authenticated file serving
    ['method' => 'GET', 'path' => '/api/files/{type}/{name}', 'handler' => [FileController::class, 'show'], 'auth' => true],
];
