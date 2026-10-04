import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Plus } from 'lucide-react';
import api from '../api/client';
import { ENDPOINTS } from '../api/endpoints';
import { usePermissions } from '../hooks/usePermissions';
import { useDebounce } from '../hooks/useDebounce';
import DataTable from '../components/DataTable';
import LoadingSkeleton from '../components/LoadingSkeleton';
import PurchaseEditor from '../components/PurchaseEditor';

export function StatusPill({ status }) {
  const s = String(status || '').toLowerCase();
  const color =
    s === 'paid' || s === 'completed' || s === 'received'
      ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
      : s === 'partial'
        ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'
        : s === 'unpaid' || s === 'pending' || s === 'overdue'
          ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'
          : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300';
  return <span className={`rounded-full px-2.5 py-0.5 text-xs font-bold capitalize ${color}`}>{status || '—'}</span>;
}

export default function Purchases() {
  const { can } = usePermissions();
  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const debouncedQ = useDebounce(q, 400);

  const fetchRows = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get(ENDPOINTS.purchases.list, {
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
  }, [fetchRows]);
  useEffect(() => {
    setPage(1);
  }, [debouncedQ]);

  const columns = [
    { key: 'invoice', label: 'Invoice', sortable: true, render: (r) => (
      <Link to={`/purchases/${r.id}`} className="font-mono font-semibold text-brand-600">
        {r.invoice_no || `#${r.id}`}
      </Link>
    )},
    { key: 'supplier', label: 'Supplier', render: (r) => r.supplier?.name || '—' },
    { key: 'date', label: 'Date', sortable: true, render: (r) => r.date || r.created_at?.slice(0, 10) || '—' },
    { key: 'total', label: 'Total', sortable: true, render: (r) => Number(r.total ?? r.grand_total ?? 0).toLocaleString(), className: 'text-right' },
    { key: 'paid', label: 'Paid', render: (r) => Number(r.paid_amount ?? 0).toLocaleString(), className: 'text-right' },
    { key: 'status', label: 'Status', render: (r) => <StatusPill status={r.payment_status || r.status} /> },
  ];

  if (loading && rows.length === 0) return <LoadingSkeleton rows={6} />;

  return (
    <div className="space-y-3">
      <div className="no-print flex flex-wrap items-center gap-2">
        <h1 className="mr-auto text-xl font-extrabold">Purchases</h1>
        {can('purchases.create') && (
          <NewPurchaseButton onCreated={fetchRows} />
        )}
      </div>
      <div className="no-print glass p-3">
        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search invoice no / supplier…"
          className="h-10 w-full max-w-sm rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800" />
      </div>
      <div className="glass p-3">
        <DataTable columns={columns} data={rows} meta={meta} loading={loading} onPage={setPage} emptyText="No purchase invoices yet." />
      </div>
    </div>
  );
}

/**
 * Inline "new purchase" launcher — opens the same invoice editor used
 * inline here to keep the flow on one page.
 */
function NewPurchaseButton({ onCreated }) {
  const [open, setOpen] = React.useState(false);
  return (
    <>
      <button onClick={() => setOpen(true)} className="flex h-10 items-center gap-1.5 rounded-xl bg-brand-600 px-4 text-xs font-bold text-white">
        <Plus size={15} /> New purchase
      </button>
      <PurchaseEditor open={open} onClose={() => setOpen(false)} onSaved={onCreated} />
    </>
  );
}
