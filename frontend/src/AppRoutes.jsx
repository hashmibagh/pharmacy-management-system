import React, { Suspense, lazy } from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import ProtectedRoute from './components/ProtectedRoute';
import AppLayout from './components/AppLayout';
import Login from './pages/Login';
import LoadingSkeleton from './components/LoadingSkeleton';

// Lazy-load pages so the initial bundle (and the SW precache) stays small
// on low-end Android devices.
const Dashboard = lazy(() => import('./pages/Dashboard'));
const POS = lazy(() => import('./pages/POS'));
const Medicines = lazy(() => import('./pages/Medicines'));
const Inventory = lazy(() => import('./pages/Inventory'));
const Purchases = lazy(() => import('./pages/Purchases'));
const PurchaseDetail = lazy(() => import('./pages/PurchaseDetail'));
const Sales = lazy(() => import('./pages/Sales'));
const SaleDetail = lazy(() => import('./pages/SaleDetail'));
const Customers = lazy(() => import('./pages/Customers'));
const CustomerDetail = lazy(() => import('./pages/CustomerDetail'));
const Suppliers = lazy(() => import('./pages/Suppliers'));
const SupplierDetail = lazy(() => import('./pages/SupplierDetail'));
const Expenses = lazy(() => import('./pages/Expenses'));
const Employees = lazy(() => import('./pages/Employees'));
const Prescriptions = lazy(() => import('./pages/Prescriptions'));
const Reports = lazy(() => import('./pages/Reports'));
const Settings = lazy(() => import('./pages/Settings'));
const Users = lazy(() => import('./pages/Users'));
const Roles = lazy(() => import('./pages/Roles'));
const AuditLogs = lazy(() => import('./pages/AuditLogs'));
const Notifications = lazy(() => import('./pages/Notifications'));

function PageFallback() {
  return (
    <div className="p-4 md:p-6">
      <LoadingSkeleton rows={6} />
    </div>
  );
}

/**
 * Route table with permission-gated routes. Permission strings come from
 * the user object's permissions array (auth/me).
 */
export default function AppRoutes() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route
        path="/"
        element={
          <ProtectedRoute>
            <AppLayout />
          </ProtectedRoute>
        }
      >
        <Route index element={<Navigate to="/dashboard" replace />} />
        <Route
          path="dashboard"
          element={
            <ProtectedRoute permission="dashboard.view">
              <Suspense fallback={<PageFallback />}><Dashboard /></Suspense>
            </ProtectedRoute>
          }
        />
        <Route
          path="pos"
          element={
            <ProtectedRoute permission="sales.create">
              <Suspense fallback={<PageFallback />}><POS /></Suspense>
            </ProtectedRoute>
          }
        />
        <Route
          path="medicines"
          element={
            <ProtectedRoute permission="medicines.view">
              <Suspense fallback={<PageFallback />}><Medicines /></Suspense>
            </ProtectedRoute>
          }
        />
        <Route
          path="inventory"
          element={
            <ProtectedRoute permission="inventory.view">
              <Suspense fallback={<PageFallback />}><Inventory /></Suspense>
            </ProtectedRoute>
          }
        />
        <Route
          path="purchases"
          element={
            <ProtectedRoute permission="purchases.view">
              <Suspense fallback={<PageFallback />}><Purchases /></Suspense>
            </ProtectedRoute>
          }
        />
        <Route
          path="purchases/:id"
          element={
            <ProtectedRoute permission="purchases.view">
              <Suspense fallback={<PageFallback />}><PurchaseDetail /></Suspense>
            </ProtectedRoute>
          }
        />
        <Route
          path="sales"
          element={
            <ProtectedRoute permission="sales.view">
              <Suspense fallback={<PageFallback />}><Sales /></Suspense>
            </ProtectedRoute>
          }
        />
        <Route
          path="sales/:id"
          element={
            <ProtectedRoute permission="sales.view">
              <Suspense fallback={<PageFallback />}><SaleDetail /></Suspense>
            </ProtectedRoute>
          }
        />
        <Route
          path="customers"
          element={
            <ProtectedRoute permission="customers.view">
              <Suspense fallback={<PageFallback />}><Customers /></Suspense>
            </ProtectedRoute>
          }
        />
        <Route
          path="customers/:id"
          element={
            <ProtectedRoute permission="customers.view">
              <Suspense fallback={<PageFallback />}><CustomerDetail /></Suspense>
            </ProtectedRoute>
          }
        />
        <Route
          path="suppliers"
          element={
            <ProtectedRoute permission="suppliers.view">
              <Suspense fallback={<PageFallback />}><Suppliers /></Suspense>
            </ProtectedRoute>
          }
        />
        <Route
          path="suppliers/:id"
          element={
            <ProtectedRoute permission="suppliers.view">
              <Suspense fallback={<PageFallback />}><SupplierDetail /></Suspense>
            </ProtectedRoute>
          }
        />
        <Route
          path="expenses"
          element={
            <ProtectedRoute permission="expenses.view">
              <Suspense fallback={<PageFallback />}><Expenses /></Suspense>
            </ProtectedRoute>
          }
        />
        <Route
          path="employees"
          element={
            <ProtectedRoute permission="employees.view">
              <Suspense fallback={<PageFallback />}><Employees /></Suspense>
            </ProtectedRoute>
          }
        />
        <Route
          path="prescriptions"
          element={
            <ProtectedRoute permission="prescriptions.view">
              <Suspense fallback={<PageFallback />}><Prescriptions /></Suspense>
            </ProtectedRoute>
          }
        />
        <Route
          path="reports"
          element={
            <ProtectedRoute permission="reports.view">
              <Suspense fallback={<PageFallback />}><Reports /></Suspense>
            </ProtectedRoute>
          }
        />
        <Route
          path="settings"
          element={
            <ProtectedRoute permission="settings.view">
              <Suspense fallback={<PageFallback />}><Settings /></Suspense>
            </ProtectedRoute>
          }
        />
        <Route
          path="users"
          element={
            <ProtectedRoute permission="users.view">
              <Suspense fallback={<PageFallback />}><Users /></Suspense>
            </ProtectedRoute>
          }
        />
        <Route
          path="roles"
          element={
            <ProtectedRoute permission="roles.view">
              <Suspense fallback={<PageFallback />}><Roles /></Suspense>
            </ProtectedRoute>
          }
        />
        <Route
          path="audit-logs"
          element={
            <ProtectedRoute permission="audit.view">
              <Suspense fallback={<PageFallback />}><AuditLogs /></Suspense>
            </ProtectedRoute>
          }
        />
        <Route
          path="notifications"
          element={
            <Suspense fallback={<PageFallback />}><Notifications /></Suspense>
          }
        />
        <Route path="*" element={<Navigate to="/dashboard" replace />} />
      </Route>
    </Routes>
  );
}
