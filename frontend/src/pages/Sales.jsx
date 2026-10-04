import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Plus, CloudOff, CheckCircle2, AlertTriangle, RefreshCw } from 'lucide-react';
import api from '../api/client';
import { ENDPOINTS } from '../api/endpoints';
import { usePermissions } from '../hooks/usePermissions';
import { useDebounce } from '../hooks/useDebounce';
import DataTable from '../components/DataTable';
import LoadingSkeleton from '../components/LoadingSkeleton';
import { StatusPill } from './Purchases';
import { listOutbox } from '../offline/db';
import { onOutboxChange, flushOutbox } from '../offline/sync';

/** Offline outbox status badge for sales created while offline. */
export function SyncBadge({ status }) {
  if (status === 'synced' || status === 'confirmed')
    return <span className="flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-bold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300"><CheckCircle2 size={12} /> Server confirmed</span>;
  if (status === 'failed')
    return <span className="flex items-center gap-1 rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-bold text-red-700 dark:bg-red-900/40 dark:text-red-300"><AlertTriangle size={12} /> Sync failed</span>;
  return <span className="flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-bold text-amber-700 dark:bg-amber-900/40 dark:text-amber-300"><CloudOff size={12} /> Queued offline</span>;
}

export default function Sales() {
  const { can } = usePermissions();
  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const debouncedQ = useDebounce(q, 400);
  const [queued, setQueued] = useState([]);
  const [syncing, setSyncing] = useState(false);

  const refreshQueued = useCallback(async () => {
    const items = await listOutbox();
    setQueued(items.filter((i) => i.kind === 'sale' && i.status !== 'synced'));
  }, []);

  const fetchRows = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get(ENDPOINTS.sales.list, {
        params: { page, q: debouncedQ || undefined, per_page: 15 },
      });
      const d = res.unwrapped?.data;
      setRows(d?.data || d || []);
      setMeta(d?.meta || null);
    } catch (e) {
      setRows([]);
    } finally {
      setLoading(false);
    }
  }, [page, debouncedQ]);

  useEffect(() => {
    fetchRows();
    refreshQueued();
    return onOutboxChange(refreshQueued);
  }, [fetchRows, refreshQueued]);

  useEffect(() => {
    setPage(1);
  }, [debouncedQ]);

  const retrySync = async () => {
    setSyncing(true);
    try {
      await flushOutbox();
      await refreshQueued();
      fetchRows();
    } finally {
      setSyncing(false);
    }
  };

  const columns = [
    { key: 'invoice', label: 'Receipt', sortable: true, render: (r) => (
      <Link to={`/sales/${r.id}`} className="font-mono font-semibold text-brand-600">
        {r.invoice_no || `#${r.id}`}
      </Link>
    )},
    { key: 'customer', label: 'Customer', render: (r) => r.customer?.name || 'Walk-in' },
    { key: 'date', label: 'Date', sortable: true, render: (r) => String(r.date || r.created_at || '').slice(0, 16).replace('T', ' ') },
    { key: 'total', label: 'Total', sortable: true, render: (r) => Number(r.total ?? 0).toLocaleString(), className: 'text-right' },
    { key: 'method', label: 'Payment', render: (r) => <span className="capitalize">{String(r.payment_method || 'cash').replace('_', ' ')}</span> },
    { key: 'status', label: 'Status', render: (r) => (
      <div className="flex flex-col gap-1">
        <StatusPill status={r.payment_status || r.status} />
        <SyncBadge status="confirmed" />
      </div>
    )},
  ];

  if (loading && rows.length === 0) return <LoadingSkeleton rows={6} />;

  return (
    <div className="space-y-3">
      <div className="no-print flex flex-wrap items-center gap-2">
        <h1 className="mr-auto text-xl font-extrabold">Sales</h1>
        {queued.length > 0 && (
          <button onClick={retrySync} disabled={syncing}
            className="flex h-10 items-center gap-1.5 rounded-xl bg-amber-100 px-3 text-xs font-bold text-amber-800 disabled:opacity-50 dark:bg-amber-900/40 dark:text-amber-300">
            <RefreshCw size={15} className={syncing ? 'animate-spin' : ''} />
            {syncing ? 'Syncing…' : `Retry sync (${queued.length})`}
          </button>
        )}
        {can('sales.create') && (
          <Link to="/pos" className="flex h-10 items-center gap-1.5 rounded-xl bg-brand-600 px-4 text-xs font-bold text-white">
            <Plus size={15} /> New sale
          </Link>
        )}
      </div>

      {/* Offline-queued sales */}
      {queued.length > 0 && (
        <div className="glass p-3">
          <h3 className="mb-2 flex items-center gap-2 text-sm font-bold">
            <CloudOff size={16} className="text-amber-500" /> Offline sales ({queued.length})
          </h3>
          <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
            {queued.map((item) => (
              <li key={item.id} className="flex items-center justify-between py-2">
                <div>
                  <p className="font-mono font-semibold">OFFLINE-{item.localId.slice(-6).toUpperCase()}</p>
                  <p className="text-xs text-slate-500">{new Date(item.createdAt).toLocaleString()} • {(item.payload?.items || []).length} items</p>
                  {item.lastError && <p className="text-xs text-red-500">{item.lastError}</p>}
                </div>
                <div className="flex flex-col items-end gap-1">
                  <span className="font-bold">{Number(item.payload?.total || 0).toLocaleString()}</span>
                  <SyncBadge status={item.status} />
                </div>
              </li>
            ))}
          </ul>
        </div>
      )}

      <div className="no-print glass p-3">
        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search receipt no / customer…"
          className="h-10 w-full max-w-sm rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800" />
      </div>

      <div className="glass p-3">
        <DataTable columns={columns} data={rows} meta={meta} loading={loading} onPage={setPage} emptyText="No sales yet." />
      </div>
    </div>
  );
}
