# API Reference

Base URL: `{APP_URL}/api` (e.g. `http://localhost:8000/api`). All responses use a JSON envelope.

## Conventions

**Success envelope**

```json
{
  "success": true,
  "message": "Medicine created",
  "data": { "id": 42, "name": "Paracetamol 500mg", "...": "..." }
}
```

List endpoints return `data` as an object with items + pagination (see below).

**Error envelope**

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": { "name": ["The name field is required."] }
}
```

Common HTTP statuses: `200` OK · `201` Created · `204` No content · `400` Bad request · `401` Unauthorized (missing/expired token) · `403` Forbidden (missing permission) · `404` Not found · `422` Validation failed · `429` Rate limited · `500` Server error.

**Pagination** — list endpoints accept `?page=1&per_page=20&search=&sort=&order=desc`:

```json
{
  "success": true,
  "message": "OK",
  "data": {
    "items": [ { "...": "..." } ],
    "pagination": { "page": 1, "per_page": 20, "total": 137, "total_pages": 7 }
  }
}
```

**Filtering** — reports and most lists accept `?from=2026-01-01&to=2026-01-31` (inclusive dates) and `?search=`.

## Authentication

- **Access token:** short-lived JWT (default 15 min, `JWT_ACCESS_TTL`). Send as:
  ```
  Authorization: Bearer <access_token>
  ```
- **Refresh:** on login the server sets an `HttpOnly; Secure; SameSite` cookie holding the refresh token (default 7 days, `JWT_REFRESH_TTL`). Call `POST /api/auth/refresh` (no body, cookie is sent automatically) to get a new access token. JavaScript never sees the refresh token.
- Login and password-reset request endpoints are rate-limited (see Rate limits).

## Permission catalog

Every protected route requires the logged-in user's role to hold the listed permission (strings come from the `permissions` table seeded in `database/seed.sql`):

| Module | Permissions |
|---|---|
| dashboard | `dashboard.view` |
| medicines | `medicines.view`, `medicines.create`, `medicines.edit`, `medicines.delete` |
| inventory | `inventory.view`, `inventory.adjust`, `inventory.transfer` |
| purchases | `purchases.view`, `purchases.create`, `purchases.edit`, `purchases.delete`, `purchases.return` |
| sales | `sales.view`, `sales.create`, `sales.edit`, `sales.return` |
| customers | `customers.view`, `customers.create`, `customers.edit`, `customers.delete` |
| suppliers | `suppliers.view`, `suppliers.create`, `suppliers.edit`, `suppliers.delete` |
| expenses | `expenses.view`, `expenses.create`, `expenses.edit`, `expenses.delete` |
| employees | `employees.view`, `employees.manage` |
| prescriptions | `prescriptions.view`, `prescriptions.create` |
| reports | `reports.view`, `reports.export` |
| settings | `settings.view`, `settings.manage` |
| users | `users.view`, `users.manage` |
| audit_logs | `audit_logs.view` |

`users.manage` also governs roles/permissions management. Admins hold all permissions.

---

## Auth

| Method | Path | Auth | Description |
|---|---|---|---|
| GET | `/api/health` | none | Liveness check |
| POST | `/api/auth/login` | none (rate-limited) | Log in; returns access token + sets refresh cookie |
| POST | `/api/auth/logout` | Bearer | Revoke refresh token, clear cookie |
| GET | `/api/auth/me` | Bearer | Current user + roles + permissions |
| POST | `/api/auth/refresh` | refresh cookie | New access token |
| POST | `/api/auth/forgot-password` | none (rate-limited) | Send reset link/token |
| POST | `/api/auth/reset-password` | reset token | Set new password |
| POST | `/api/auth/change-password` | Bearer | Change own password (needs current password) |
| GET | `/api/auth/profile` | Bearer | Own profile |
| PUT | `/api/auth/profile` | Bearer | Update own profile (name, phone) |
| POST | `/api/auth/profile-photo` | Bearer | Upload profile photo (multipart) |

**Login example**

```http
POST /api/auth/login
Content-Type: application/json

{ "email": "admin@pharmacy.local", "password": "password123" }
```

```json
{
  "success": true,
  "message": "Logged in",
  "data": {
    "access_token": "eyJ0eXAiOiJKV1QiLCJh...",
    "token_type": "Bearer",
    "expires_in": 900,
    "user": { "id": 1, "name": "Admin User", "email": "admin@pharmacy.local", "roles": ["admin"] }
  }
}
```

## Medicines

Permission: `medicines.*`

| Method | Path | Description |
|---|---|---|
| GET | `/api/medicines` | List (search, category, manufacturer, low-stock filters) |
| POST | `/api/medicines` | Create (`medicines.create`) |
| GET | `/api/medicines/{id}` | Detail with batches |
| PUT | `/api/medicines/{id}` | Update (`medicines.edit`) |
| DELETE | `/api/medicines/{id}` | Delete (`medicines.delete`) |
| GET | `/api/medicines/{id}/batches` | Batch list for a medicine |
| GET | `/api/medicines/{id}/barcode` | Barcode/QR label data (print view) |
| GET | `/api/medicines/{id}/history` | Stock movement history (newest first) |
| POST | `/api/medicines/import` | Excel import (`medicines.create`) |

Create params: `name`, `generic_name`, `category_id`, `manufacturer_id`, `strength`, `pack_size`, `unit`, `purchase_price`, `sale_price`, `reorder_level`, `barcode`, `requires_prescription` (bool), photo (multipart `image` → `storage/medicines/`).

## Categories

Permission: `medicines.view` (write ops need `medicines.create/edit/delete`)

| Method | Path | Description |
|---|---|---|
| GET | `/api/categories` | List |
| POST | `/api/categories` | Create (`name`) |
| PUT | `/api/categories/{id}` | Update |
| DELETE | `/api/categories/{id}` | Delete |

## Manufacturers

Same pattern as categories under `/api/manufacturers` (`name`, `contact`, `address`).

## Inventory

Permission: `inventory.*`

| Method | Path | Description |
|---|---|---|
| GET | `/api/inventory` | Stock levels per medicine/batch |
| GET | `/api/inventory/low-stock` | Items at/below reorder level |
| GET | `/api/inventory/expiring?days=90` | Batches expiring within N days |
| GET | `/api/inventory/valuation` | Stock valuation (qty × cost) |
| GET | `/api/inventory/history?medicine_id=` | Stock movement history |
| POST | `/api/inventory/adjust` | Manual adjustment (`inventory.adjust`) |
| POST | `/api/inventory/transfer` | Transfer between locations (`inventory.transfer`) |

**Adjust example**

```http
POST /api/inventory/adjust
Authorization: Bearer <token>
Content-Type: application/json

{ "medicine_id": 42, "batch_id": 7, "quantity": -10, "reason": "expired-write-off", "note": "Damaged in storage" }
```

```json
{ "success": true, "message": "Stock adjusted", "data": { "medicine_id": 42, "new_quantity": 130 } }
```

## Purchases

Permission: `purchases.*`

| Method | Path | Description |
|---|---|---|
| GET | `/api/purchases` | List (supplier/date filters) |
| POST | `/api/purchases` | Create with items + batches (`purchases.create`) |
| GET | `/api/purchases/{id}` | Detail |
| PUT | `/api/purchases/{id}` | Update draft (`purchases.edit`) |
| DELETE | `/api/purchases/{id}` | Delete draft (`purchases.delete`) |
| POST | `/api/purchases/{id}/payments` | Record supplier payment (`purchases.create`) |
| GET | `/api/purchases/{id}/payments` | Payment history |
| POST | `/api/purchases/{id}/return` | Return items to supplier (`purchases.return`) |

Create params: `supplier_id`, `invoice_no`, `invoice_date`, `items[]` (`medicine_id`, `batch_no`, `expiry_date`, `quantity`, `purchase_price`, `sale_price`), `discount`, `paid_amount`, `note`.

## Sales (POS)

Permission: `sales.*`

| Method | Path | Description |
|---|---|---|
| GET | `/api/sales` | List (date/customer/payment filters) |
| POST | `/api/sales` | Create POS sale (`sales.create`) |
| GET | `/api/sales/{id}` | Detail |
| POST | `/api/sales/{id}/return` | Sales return, restocks batches (`sales.return`) |
| GET | `/api/sales/{id}/receipt` | Receipt/invoice data (for print + PDF) |

Create params: `customer_id` (nullable = walk-in), `items[]` (`medicine_id`, `batch_id?`, `quantity`, `price`, `discount`), `discount`, `tax`, `payment_method` (`cash|card|credit`), `paid_amount`, `note`. Batch auto-selected FIFO when `batch_id` omitted.

```json
{
  "success": true,
  "message": "Sale completed",
  "data": { "id": 501, "invoice_no": "INV-2026-0501", "total": 2450.00, "change": 50.00 }
}
```

## Customers

Permission: `customers.*`

| Method | Path | Description |
|---|---|---|
| GET | `/api/customers` | List (search) |
| POST | `/api/customers` | Create (`customers.create`) |
| GET | `/api/customers/{id}` | Detail |
| PUT | `/api/customers/{id}` | Update (`customers.edit`) |
| DELETE | `/api/customers/{id}` | Delete (`customers.delete`) |
| GET | `/api/customers/{id}/ledger` | Full ledger (sales, payments, returns) |
| GET | `/api/customers/{id}/statement?from&to` | Printable statement of account |

## Suppliers

Permission: `suppliers.*` — `/api/suppliers`, `/api/suppliers/{id}`, plus:

| Method | Path | Description |
|---|---|---|
| GET | `/api/suppliers/{id}/ledger` | Purchases, payments, returns ledger |

## Expenses

Permission: `expenses.*`

| Method | Path | Description |
|---|---|---|
| GET | `/api/expenses` | List (category/date filters) |
| POST | `/api/expenses` | Create with optional receipt attachment (`expenses.create`) |
| GET | `/api/expenses/{id}` | Detail |
| PUT | `/api/expenses/{id}` | Update (`expenses.edit`) |
| DELETE | `/api/expenses/{id}` | Delete (`expenses.delete`) |
| GET | `/api/expense-categories` | List categories |
| POST | `/api/expense-categories` | Create category |

## Employees

Permission: `employees.view` / `employees.manage` for writes

| Method | Path | Description |
|---|---|---|
| GET | `/api/employees` | List |
| POST | `/api/employees` | Create (photo → `storage/employees/`) |
| GET/PUT/DELETE | `/api/employees/{id}` | Detail / update / delete |
| GET/POST | `/api/employees/attendance?date=` | View / mark attendance |
| GET/POST | `/api/employees/salary` | Salary records / process payroll |
| GET/POST | `/api/employees/leaves` | Leave list / request |
| PUT | `/api/employees/leaves/{id}` | Approve/reject leave |

## Prescriptions

Permission: `prescriptions.view` / `prescriptions.create`

| Method | Path | Description |
|---|---|---|
| GET | `/api/prescriptions` | List (customer/date filters) |
| POST | `/api/prescriptions` | Create, image upload → `storage/prescriptions/` |
| GET | `/api/prescriptions/{id}` | Detail |
| DELETE | `/api/prescriptions/{id}` | Delete (`prescriptions.create` holder or admin) |

## Lab module (optional, disabled by default)

Enable in **Settings → Lab module**. When enabled, these routes become active under the same auth/RBAC model:

| Method | Path | Description |
|---|---|---|
| GET/POST | `/api/lab/tests` | Test catalog |
| GET/POST | `/api/lab/bookings` | Bookings (patient, tests, date) |
| PUT | `/api/lab/bookings/{id}` | Update status / record results |

## Reports

Permission: `reports.view` (exports additionally need `reports.export`). All accept `?from&to&search&page&per_page`.

| Method | Path | Description |
|---|---|---|
| GET | `/api/dashboard` | Dashboard aggregates: sales/inventory/customer/supplier KPIs, 30-day sales+profit, monthly comparison, sales by category & payment method (permission: `dashboard.view`) |
| GET | `/api/reports/sales` | Sales summary + lines |
| GET | `/api/reports/purchases` | Purchase summary + lines |
| GET | `/api/reports/profit` | Revenue, COGS, gross profit |
| GET | `/api/reports/inventory` | Stock snapshot + valuation |
| GET | `/api/reports/expiry` | Expiring batches |
| GET | `/api/reports/expenses` | Expenses by category |
| GET | `/api/reports/custom?type=sales&from&to` | Custom report builder |
| GET | `/api/reports/custom?summary=dashboard` | Same payload as `/api/dashboard` (fallback) |

## Export (PDF / Excel)

Permission: `reports.export`. Requires optional Composer packages (`dompdf/dompdf`, `phpoffice/phpspreadsheet`); without them the API returns `501` with a clear message.

| Method | Path | Description |
|---|---|---|
| GET | `/api/export/pdf?type=sale&id=501` | Invoice/receipt PDF |
| GET | `/api/export/pdf?type=report&report=sales&from&to` | Report PDF |
| GET | `/api/export/pdf?type=statement&customer_id=3&from&to` | Customer statement PDF |
| GET | `/api/export/excel?type=medicines` | Medicines spreadsheet |
| GET | `/api/export/excel?type=sales&from&to` | Sales spreadsheet |
| GET | `/api/export/excel?type=purchases&from&to` | Purchases spreadsheet |

PDF responses return `application/pdf` (download); Excel returns the `.xlsx` file. Validation errors still use the JSON error envelope.

## Settings

Permission: `settings.view` / `settings.manage`

| Method | Path | Description |
|---|---|---|
| GET | `/api/settings` | All settings (pharmacy profile, tax, invoice format, thresholds) |
| PUT | `/api/settings` | Update (`settings.manage`); logo upload → `storage/logos/` |

## Notifications

Authenticated users see their own (broadcasts go to all).

| Method | Path | Description |
|---|---|---|
| GET | `/api/notifications` | List (unread first) |
| PUT | `/api/notifications/{id}/read` | Mark read |
| POST | `/api/notifications/read-all` | Mark all read |

Generated by the scheduled CLI script (`backend/cli/generate-notifications.php`) — see INSTALLATION.md §9.

## Audit logs

Permission: `audit_logs.view`

| Method | Path | Description |
|---|---|---|
| GET | `/api/audit-logs` | Filterable by user, action, date (`?from&to&search`) |

## Users & Roles

Permission: `users.view` / `users.manage`

| Method | Path | Description |
|---|---|---|
| GET/POST | `/api/users` | List / create |
| GET/PUT/DELETE | `/api/users/{id}` | Detail / update / delete |
| GET/POST | `/api/roles` | List / create |
| GET/PUT/DELETE | `/api/roles/{id}` | Detail / update / delete |
| GET | `/api/roles/permissions` | Full permission catalog |
| PUT | `/api/roles/{id}/permissions` | Assign permission names array |

## Search

| Method | Path | Auth | Description |
|---|---|---|---|
| GET | `/api/search?q=` | Bearer | Global search across medicines, customers, suppliers, invoices |

Respects the caller's module permissions — e.g. without `customers.view`, customer hits are omitted.

---

## Rate limits

Enforced by `backend/middleware/RateLimit.php`:

| Scope | Limit |
|---|---|
| `POST /api/auth/login`, `POST /api/auth/forgot-password` | 10 attempts / minute / IP |
| Authenticated API | 300 requests / minute / token |
| Password reset token use | 5 attempts / 10 minutes |

`429` responses use the error envelope with `message: "Too many requests"`.

## Error format

```json
{
  "success": false,
  "message": "The given data was invalid.",
  "errors": {
    "quantity": ["Quantity must be greater than 0."],
    "batch_id": ["Selected batch is out of stock."]
  }
}
```

- `401` — missing, malformed, or expired access token → log in / refresh.
- `403` — valid token but the role lacks the required permission string.
- `422` — validation failure; details in `errors`.
