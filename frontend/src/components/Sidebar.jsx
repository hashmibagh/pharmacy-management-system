import React from 'react';
import { NavLink } from 'react-router-dom';
import {
  LayoutDashboard, Pill, Package, ShoppingCart, ReceiptText,
  Users, Truck, Wallet, UsersRound, FileText, BarChart3,
  Settings, ShieldCheck, Bell, ScrollText, ShoppingBag, X,
} from 'lucide-react';
import { usePermissions } from '../hooks/usePermissions';

/** Sidebar nav entries with the permission that gates each one. */
const NAV = [
  { to: '/dashboard', label: 'Dashboard', icon: LayoutDashboard, perm: 'dashboard.view' },
  { to: '/pos', label: 'POS', icon: ShoppingBag, perm: 'sales.create' },
  { to: '/sales', label: 'Sales', icon: ReceiptText, perm: 'sales.view' },
  { to: '/purchases', label: 'Purchases', icon: ShoppingCart, perm: 'purchases.view' },
  { to: '/medicines', label: 'Medicines', icon: Pill, perm: 'medicines.view' },
  { to: '/inventory', label: 'Inventory', icon: Package, perm: 'inventory.view' },
  { to: '/customers', label: 'Customers', icon: Users, perm: 'customers.view' },
  { to: '/suppliers', label: 'Suppliers', icon: Truck, perm: 'suppliers.view' },
  { to: '/expenses', label: 'Expenses', icon: Wallet, perm: 'expenses.view' },
  { to: '/employees', label: 'Employees', icon: UsersRound, perm: 'employees.view' },
  { to: '/prescriptions', label: 'Prescriptions', icon: FileText, perm: 'prescriptions.view' },
  { to: '/reports', label: 'Reports', icon: BarChart3, perm: 'reports.view' },
  { to: '/users', label: 'Users', icon: ShieldCheck, perm: 'users.view' },
  { to: '/roles', label: 'Roles', icon: ShieldCheck, perm: 'roles.view' },
  { to: '/audit-logs', label: 'Audit Logs', icon: ScrollText, perm: 'audit.view' },
  { to: '/notifications', label: 'Notifications', icon: Bell, perm: null },
  { to: '/settings', label: 'Settings', icon: Settings, perm: 'settings.view' },
];

/**
 * Collapsible sidebar. On mobile it renders as an overlay drawer;
 * on desktop it collapses to icon rail when `collapsed`.
 */
export default function Sidebar({ collapsed, onToggle, mobileOpen, onCloseMobile }) {
  const { can } = usePermissions();
  const items = NAV.filter((n) => can(n.perm));

  const linkCls = ({ isActive }) =>
    `flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors ${
      isActive
        ? 'bg-brand-600 text-white shadow'
        : 'text-slate-600 hover:bg-slate-200/70 dark:text-slate-300 dark:hover:bg-slate-800'
    } ${collapsed ? 'justify-center px-2' : ''}`;

  const body = (
    <>
      <div className={`flex items-center ${collapsed ? 'justify-center' : 'justify-between'} px-3 py-4`}>
        <div className="flex items-center gap-2">
          <span className="grid h-9 w-9 place-items-center rounded-xl bg-brand-600 text-lg text-white">💊</span>
          {!collapsed && (
            <div className="leading-tight">
              <p className="text-sm font-bold text-slate-800 dark:text-white">Pharmacy</p>
              <p className="text-[11px] text-slate-500 dark:text-slate-400">Management</p>
            </div>
          )}
        </div>
        {!collapsed && (
          <button
            onClick={onCloseMobile}
            className="rounded-lg p-1.5 text-slate-500 hover:bg-slate-200 lg:hidden dark:hover:bg-slate-800"
            aria-label="Close menu"
          >
            <X size={18} />
          </button>
        )}
      </div>
      <nav className="scrollbar-thin flex-1 space-y-1 overflow-y-auto px-3 pb-6">
        {items.map((n) => (
          <NavLink key={n.to} to={n.to} className={linkCls} onClick={onCloseMobile} title={collapsed ? n.label : undefined}>
            <n.icon size={19} className="shrink-0" />
            {!collapsed && <span className="truncate">{n.label}</span>}
          </NavLink>
        ))}
      </nav>
    </>
  );

  return (
    <>
      {/* Desktop */}
      <aside
        className={`no-print fixed inset-y-0 left-0 z-40 hidden flex-col border-r border-slate-200 bg-white/80 backdrop-blur transition-all duration-200 lg:flex dark:border-slate-800 dark:bg-slate-900/80 ${
          collapsed ? 'w-[72px]' : 'w-60'
        }`}
      >
        {body}
      </aside>
      {/* Mobile drawer */}
      <div
        className={`fixed inset-0 z-40 bg-black/40 lg:hidden ${mobileOpen ? '' : 'pointer-events-none opacity-0'} transition-opacity`}
        onClick={onCloseMobile}
        aria-hidden
      />
      <aside
        className={`no-print fixed inset-y-0 left-0 z-50 flex w-72 flex-col border-r border-slate-200 bg-white transition-transform duration-200 lg:hidden dark:border-slate-800 dark:bg-slate-900 ${
          mobileOpen ? 'translate-x-0' : '-translate-x-full'
        }`}
      >
        {body}
      </aside>
    </>
  );
}
