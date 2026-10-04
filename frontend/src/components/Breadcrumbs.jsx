import React from 'react';
import { Link, useLocation } from 'react-router-dom';
import { ChevronRight, Home } from 'lucide-react';

/** Human-readable crumb labels for route segments. */
const LABELS = {
  dashboard: 'Dashboard',
  pos: 'POS',
  medicines: 'Medicines',
  inventory: 'Inventory',
  purchases: 'Purchases',
  sales: 'Sales',
  customers: 'Customers',
  suppliers: 'Suppliers',
  expenses: 'Expenses',
  employees: 'Employees',
  prescriptions: 'Prescriptions',
  reports: 'Reports',
  settings: 'Settings',
  users: 'Users',
  roles: 'Roles',
  'audit-logs': 'Audit Logs',
  notifications: 'Notifications',
};

export default function Breadcrumbs() {
  const { pathname } = useLocation();
  const segments = pathname.split('/').filter(Boolean);
  if (segments.length === 0) return null;

  let href = '';
  return (
    <nav aria-label="Breadcrumb" className="no-print flex items-center gap-1 text-xs text-slate-500 dark:text-slate-400">
      <Link to="/dashboard" className="flex items-center gap-1 hover:text-brand-600">
        <Home size={13} />
      </Link>
      {segments.map((seg, i) => {
        href += `/${seg}`;
        const isLast = i === segments.length - 1;
        const label = LABELS[seg] || (/^\d+$/.test(seg) ? `#${seg}` : seg);
        return (
          <span key={href} className="flex items-center gap-1">
            <ChevronRight size={13} className="text-slate-300 dark:text-slate-600" />
            {isLast ? (
              <span className="font-medium text-slate-800 dark:text-slate-200">{label}</span>
            ) : (
              <Link to={href} className="hover:text-brand-600">
                {label}
              </Link>
            )}
          </span>
        );
      })}
    </nav>
  );
}
