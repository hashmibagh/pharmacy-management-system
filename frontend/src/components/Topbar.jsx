import React, { useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Menu, Search, Plus, Bell, WifiOff, LogOut, ChevronsLeft, ChevronsRight } from 'lucide-react';
import api from '../api/client';
import { ENDPOINTS } from '../api/endpoints';
import { useDebounce } from '../hooks/useDebounce';
import { useOnlineStatus } from '../hooks/useOnlineStatus';
import { useAuth } from '../context/AuthContext';
import { usePermissions } from '../hooks/usePermissions';
import ThemeToggle from './ThemeToggle';

/**
 * Topbar: hamburger (mobile drawer / desktop collapse), global search with
 * debounce hitting /api/search?q= (server-side, no full table download),
 * quick actions, notification bell, offline badge, user menu.
 */
export default function Topbar({ collapsed, onToggleCollapse, onOpenMobile }) {
  const { user, logout } = useAuth();
  const { can } = usePermissions();
  const online = useOnlineStatus();
  const navigate = useNavigate();

  const [q, setQ] = useState('');
  const [results, setResults] = useState(null);
  const [searching, setSearching] = useState(false);
  const [showResults, setShowResults] = useState(false);
  const [unread, setUnread] = useState(0);
  const [menuOpen, setMenuOpen] = useState(false);
  const debouncedQ = useDebounce(q, 400);
  const boxRef = useRef(null);

  // Server-side global search
  useEffect(() => {
    if (!debouncedQ || debouncedQ.trim().length < 2) {
      setResults(null);
      return;
    }
    let cancelled = false;
    (async () => {
      setSearching(true);
      try {
        const res = await api.get(ENDPOINTS.search, { params: { q: debouncedQ.trim() } });
        if (!cancelled) {
          setResults(res.unwrapped?.data || {});
          setShowResults(true);
        }
      } catch (e) {
        if (!cancelled) setResults(null);
      } finally {
        if (!cancelled) setSearching(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [debouncedQ]);

  // Unread notification count
  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const res = await api.get(ENDPOINTS.notifications.list, { params: { unread: 1, per_page: 1 } });
        const d = res.unwrapped?.data;
        if (!cancelled) setUnread(d?.unread_count ?? d?.meta?.unread ?? 0);
      } catch (e) {
        /* not fatal */
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  // Close dropdowns on outside click
  useEffect(() => {
    const onClick = (e) => {
      if (boxRef.current && !boxRef.current.contains(e.target)) {
        setShowResults(false);
        setMenuOpen(false);
      }
    };
    document.addEventListener('mousedown', onClick);
    return () => document.removeEventListener('mousedown', onClick);
  }, []);

  const groups = [
    { key: 'medicines', label: 'Medicines', to: (r) => `/medicines?highlight=${r.id}` },
    { key: 'customers', label: 'Customers', to: (r) => `/customers/${r.id}` },
    { key: 'suppliers', label: 'Suppliers', to: (r) => `/suppliers/${r.id}` },
    { key: 'sales', label: 'Sales', to: (r) => `/sales/${r.id}` },
    { key: 'purchases', label: 'Purchases', to: (r) => `/purchases/${r.id}` },
  ];

  return (
    <header className="no-print sticky top-0 z-30 flex h-16 items-center gap-2 border-b border-slate-200 bg-white/80 px-3 backdrop-blur md:px-5 dark:border-slate-800 dark:bg-slate-900/80">
      <button
        onClick={onOpenMobile}
        className="rounded-xl p-2.5 text-slate-600 hover:bg-slate-200 lg:hidden dark:text-slate-300 dark:hover:bg-slate-800"
        aria-label="Open menu"
      >
        <Menu size={20} />
      </button>
      <button
        onClick={onToggleCollapse}
        className="hidden rounded-xl p-2.5 text-slate-600 hover:bg-slate-200 lg:block dark:text-slate-300 dark:hover:bg-slate-800"
        aria-label="Toggle sidebar"
      >
        {collapsed ? <ChevronsRight size={20} /> : <ChevronsLeft size={20} />}
      </button>

      {/* Global search */}
      <div ref={boxRef} className="relative mx-auto w-full max-w-xl flex-1">
        <div className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400">
          <Search size={18} />
        </div>
        <input
          value={q}
          onChange={(e) => setQ(e.target.value)}
          onFocus={() => results && setShowResults(true)}
          placeholder="Search medicines, customers, suppliers, invoices…"
          className="h-11 w-full rounded-xl border border-slate-200 bg-slate-100 pl-10 pr-4 text-sm outline-none focus:border-brand-500 focus:bg-white dark:border-slate-700 dark:bg-slate-800 dark:focus:bg-slate-900"
        />
        {showResults && results && (
          <div className="absolute inset-x-0 top-12 z-50 max-h-[60vh] overflow-y-auto rounded-xl border border-slate-200 bg-white p-2 shadow-xl dark:border-slate-700 dark:bg-slate-900">
            {groups.every((g) => !(results[g.key] || []).length) && (
              <p className="px-3 py-4 text-center text-sm text-slate-500">No results for “{q}”.</p>
            )}
            {groups.map(
              (g) =>
                (results[g.key] || []).length > 0 && (
                  <div key={g.key} className="mb-1">
                    <p className="px-3 py-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                      {g.label}
                    </p>
                    {(results[g.key] || []).slice(0, 5).map((r) => (
                      <Link
                        key={`${g.key}-${r.id}`}
                        to={g.to(r)}
                        onClick={() => {
                          setShowResults(false);
                          setQ('');
                        }}
                        className="block rounded-lg px-3 py-2 text-sm hover:bg-slate-100 dark:hover:bg-slate-800"
                      >
                        <span className="font-medium">{r.name || r.invoice_no || r.title || `#${r.id}`}</span>
                        {r.phone && <span className="ml-2 text-xs text-slate-500">{r.phone}</span>}
                      </Link>
                    ))}
                  </div>
                ),
            )}
          </div>
        )}
        {searching && (
          <div className="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-400">Searching…</div>
        )}
      </div>

      {/* Quick actions */}
      <div className="flex items-center gap-1 md:gap-2">
        {!online && (
          <span className="flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
            <WifiOff size={14} /> Offline
          </span>
        )}
        {can('sales.create') && (
          <button
            onClick={() => navigate('/pos')}
            className="hidden h-10 items-center gap-1.5 rounded-xl bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700 sm:flex"
          >
            <Plus size={16} /> New Sale
          </button>
        )}
        <ThemeToggle />
        <Link
          to="/notifications"
          className="relative rounded-xl p-2.5 text-slate-600 hover:bg-slate-200 dark:text-slate-300 dark:hover:bg-slate-800"
          aria-label="Notifications"
        >
          <Bell size={20} />
          {unread > 0 && (
            <span className="absolute -right-0.5 -top-0.5 grid h-5 min-w-[20px] place-items-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">
              {unread > 99 ? '99+' : unread}
            </span>
          )}
        </Link>
        <div className="relative">
          <button
            onClick={() => setMenuOpen((v) => !v)}
            className="grid h-10 w-10 place-items-center rounded-full bg-brand-100 text-sm font-bold text-brand-700 dark:bg-brand-900/50 dark:text-brand-300"
            aria-label="User menu"
          >
            {(user?.name || 'U').charAt(0).toUpperCase()}
          </button>
          {menuOpen && (
            <div className="absolute right-0 top-12 z-50 w-52 rounded-xl border border-slate-200 bg-white p-2 shadow-xl dark:border-slate-700 dark:bg-slate-900">
              <div className="px-3 py-2">
                <p className="truncate text-sm font-semibold">{user?.name}</p>
                <p className="truncate text-xs text-slate-500">{user?.role}</p>
              </div>
              <hr className="my-1 border-slate-200 dark:border-slate-700" />
              <Link
                to="/settings"
                onClick={() => setMenuOpen(false)}
                className="block rounded-lg px-3 py-2 text-sm hover:bg-slate-100 dark:hover:bg-slate-800"
              >
                Settings
              </Link>
              <button
                onClick={logout}
                className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-950/40"
              >
                <LogOut size={16} /> Sign out
              </button>
            </div>
          )}
        </div>
      </div>
    </header>
  );
}
