/**
 * Every /api/* route exposed by the PHP backend, as constants.
 * Used together with the axios instance in api/client.js.
 * Query params (page, q, from/to, filters) are passed per call site.
 */
export const ENDPOINTS = {
  auth: {
    login: '/auth/login',
    logout: '/auth/logout',
    me: '/auth/me',
    refresh: '/auth/refresh',
    profile: '/auth/profile',
    changePassword: '/auth/change-password',
  },
  medicines: {
    list: '/medicines',
    create: '/medicines',
    detail: (id) => `/medicines/${id}`,
    duplicate: (id) => `/medicines/${id}/duplicate`,
    import: '/medicines/import',
    export: '/medicines/export',
  },
  categories: {
    list: '/categories',
    create: '/categories',
    detail: (id) => `/categories/${id}`,
  },
  manufacturers: {
    list: '/manufacturers',
    create: '/manufacturers',
    detail: (id) => `/manufacturers/${id}`,
  },
  inventory: {
    list: '/inventory',
    adjust: '/inventory/adjust',
    transfer: '/inventory/transfer',
    valuation: '/inventory/valuation',
    lowStock: '/inventory/low-stock',
    expiring: '/inventory/expiring',
  },
  purchases: {
    list: '/purchases',
    create: '/purchases',
    detail: (id) => `/purchases/${id}`,
    payment: (id) => `/purchases/${id}/payments`,
    return: (id) => `/purchases/${id}/return`,
  },
  sales: {
    list: '/sales',
    create: '/sales',
    detail: (id) => `/sales/${id}`,
    return: (id) => `/sales/${id}/return`,
  },
  customers: {
    list: '/customers',
    create: '/customers',
    detail: (id) => `/customers/${id}`,
    ledger: (id) => `/customers/${id}/ledger`,
    statement: (id) => `/customers/${id}/statement`,
  },
  suppliers: {
    list: '/suppliers',
    create: '/suppliers',
    detail: (id) => `/suppliers/${id}`,
    ledger: (id) => `/suppliers/${id}/ledger`,
  },
  expenses: {
    list: '/expenses',
    create: '/expenses',
    detail: (id) => `/expenses/${id}`,
  },
  expenseCategories: {
    list: '/expense-categories',
    create: '/expense-categories',
    detail: (id) => `/expense-categories/${id}`,
  },
  employees: {
    list: '/employees',
    create: '/employees',
    detail: (id) => `/employees/${id}`,
    attendance: (id) => `/employees/${id}/attendance`,
    salary: (id) => `/employees/${id}/salary`,
    leaves: (id) => `/employees/${id}/leaves`,
  },
  prescriptions: {
    list: '/prescriptions',
    create: '/prescriptions',
    detail: (id) => `/prescriptions/${id}`,
  },
  reports: {
    sales: '/reports/sales',
    purchases: '/reports/purchases',
    profit: '/reports/profit',
    inventory: '/reports/inventory',
    expiry: '/reports/expiry',
    expenses: '/reports/expenses',
    custom: '/reports/custom',
  },
  export: {
    pdf: '/export/pdf',
    excel: '/export/excel',
  },
  settings: '/settings',
  notifications: {
    list: '/notifications',
    read: (id) => `/notifications/${id}/read`,
  },
  auditLogs: '/audit-logs',
  users: {
    list: '/users',
    create: '/users',
    detail: (id) => `/users/${id}`,
  },
  roles: {
    list: '/roles',
    create: '/roles',
    detail: (id) => `/roles/${id}`,
    permissions: (id) => `/roles/${id}/permissions`,
  },
  search: '/search', // GET ?q=
};

/** Known permission strings used to gate routes & UI. */
export const PERMISSIONS = [
  'dashboard.view',
  'medicines.view', 'medicines.create', 'medicines.edit', 'medicines.delete',
  'inventory.view', 'inventory.manage',
  'purchases.view', 'purchases.create', 'purchases.edit', 'purchases.delete',
  'sales.view', 'sales.create', 'sales.edit', 'sales.delete',
  'customers.view', 'customers.create', 'customers.edit', 'customers.delete',
  'suppliers.view', 'suppliers.create', 'suppliers.edit', 'suppliers.delete',
  'expenses.view', 'expenses.create', 'expenses.edit', 'expenses.delete',
  'employees.view', 'employees.create', 'employees.edit', 'employees.delete',
  'prescriptions.view', 'prescriptions.create', 'prescriptions.edit', 'prescriptions.delete',
  'reports.view',
  'settings.view', 'settings.edit',
  'users.view', 'users.create', 'users.edit', 'users.delete',
  'roles.view', 'roles.create', 'roles.edit', 'roles.delete',
  'audit.view',
];
