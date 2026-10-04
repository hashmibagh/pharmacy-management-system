import React, { useEffect, useState } from 'react';
import { Plus, Minus, ArrowLeftRight, AlertTriangle, Hourglass, Wallet } from 'lucide-react';
import api from '../api/client';
import { ENDPOINTS } from '../api/endpoints';
import { usePermissions } from '../hooks/usePermissions';
import { useDebounce } from '../hooks/useDebounce';
import DataTable from '../components/DataTable';
import { Modal } from '../components/Modal';
import EmptyState from '../components/EmptyState';
import LoadingSkeleton from '../components/LoadingSkeleton';
import { StockBadge } from './Medicines';
import StatCard from '../components/StatCard';

const TABS = [
  { id: 'overview', label: 'Overview' },
  { id: 'low', label: 'Low stock' },
  { id: 'expiry', label: 'Expiry alerts' },
  { id: 'history', label: 'Stock history' },
];

/**
 * Inventory: stock overview, adjust (+/-), transfer between batches,
 * low-stock and expiry alerts, valuation summary, movement history.
 */
export default function Inventory() {
  const { can } = usePermissions();
  const [tab, setTab] = useState('overview');
  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const debouncedQ = useDebounce(q, 400);
  const [valuation, setValuation] = useState(null);
  const [history, setHistory] = useState([]);

  const [adjustOpen, setAdjustOpen] = useState(false);
  const [adjustFor, setAdjustFor] = useState(null);
  const [adjust, setAdjust] = useState({ type: 'in', qty: '', reason: '' });
  const [transferOpen, setTransferOpen] = useState(false);
  const [transfer, setTransfer] = useState({ from_batch_id: '', to_batch_id: '', qty: '', note: '' });
  const [busy, setBusy] = useState(false);
  const [toast, setToast] = useState('');

  const showToast = (m) => {
    setToast(m);
    setTimeout(() => setToast(''), 3000);
  };

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      try {
        let res;
        if (tab === 'low') res = await api.get(ENDPOINTS.inventory.lowStock, { params: { page, q: debouncedQ || undefined } });
        else if (tab === 'expiry') res = await api.get(ENDPOINTS.inventory.expiring, { params: { page, q: debouncedQ || undefined } });
        else res = await api.get(ENDPOINTS.inventory.list, { params: { page, q: debouncedQ || undefined, per_page: 15 } });
        const d = res.unwrapped?.data;
        if (!cancelled) {
          setRows(d?.data || d || []);
          setMeta(d?.meta || null);
        }
      } catch (e) {
        if (!cancelled) setRows([]);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [tab, page, debouncedQ]);

  useEffect(() => {
    setPage(1);
  }, [tab, debouncedQ]);

  // Valuation + history
  useEffect(() => {
    (async () => {
      try {
        const [v, h] = await Promise.all([
          api.get(ENDPOINTS.inventory.valuation),
          api.get(ENDPOINTS.inventory.list, { params: { history: 1, per_page: 50 } }),
        ]);
        setValuation(v.unwrapped?.data || null);
        const hd = h.unwrapped?.data;
        setHistory(hd?.history || hd?.data || []);
      } catch (e) {
        /* optional */
      }
    })();
  }, []);

  const openAdjust = (row) => {
    setAdjustFor(row);
    setAdjust({ type: 'in', qty: '', reason: '' });
    setAdjustOpen(true);
  };

  const submitAdjust = async (e) => {
    e.preventDefault();
    const qty = Number(adjust.qty);
    if (!qty || qty <= 0) return showToast('Enter a valid quantity.');
    setBusy(true);
    try {
      await api.post(ENDPOINTS.inventory.adjust, {
        medicine_id: adjustFor.medicine_id || adjustFor.id,
        batch_id: adjustFor.batch_id || null,
        type: adjust.type,
        qty,
        reason: adjust.reason,
      });
      showToast('Stock adjusted.');
      setAdjustOpen(false);
      setTab('overview');
      setPage(1);
    } catch (err) {
      showToast(err.message || 'Adjustment failed.');
    } finally {
      setBusy(false);
    }
  };

  const submitTransfer = async (e) => {
    e.preventDefault();
    const qty = Number(transfer.qty);
    if (!transfer.from_batch_id || !transfer.to_batch_id || !qty || qty <= 0) {
      return showToast('Select both batches and a valid quantity.');
    }
    setBusy(true);
    try {
      await api.post(ENDPOINTS.inventory.transfer, {
        from_batch_id: Number(transfer.from_batch_id),
        to_batch_id: Number(transfer.to_batch_id),
        qty,
        note: transfer.note,
      });
      showToast('Stock transferred.');
      setTransferOpen(false);
    } catch (err) {
      showToast(err.message || 'Transfer failed.');
    } finally {
      setBusy(false);
    }
  };

  const columns = [
    { key: 'medicine', label: 'Medicine', sortable: true, render: (r) => (
      <div>
        <p className="font-semibold">{r.medicine_name || r.name}</p>
        <p className="text-xs text-slate-500">{r.batch_no ? `Batch ${r.batch_no}` : ''} {r.expiry_date ? `• Exp ${r.expiry_date}` : ''}</p>
      </div>
    )},
    { key: 'stock', label: 'Stock', sortable: true, render: (r) => <StockBadge stock={r.qty ?? r.stock} minStock={r.min_stock} /> },
    { key: 'value', label: 'Value', render: (r) => Number((r.qty ?? r.stock ?? 0) * (r.purchase_price ?? 0)).toLocaleString() },
    { key: 'actions', label: '', className: 'text-right whitespace-nowrap', render: (r) => (
      can('inventory.manage') ? (
        <div className="flex justify-end gap-1">
          <button onClick={() => openAdjust(r)} className="flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-bold dark:border-slate-700">
            <ArrowLeftRight size={13} /> Adjust
          </button>
        </div>
      ) : null
    )},
  ];

  return (
    <div className="space-y-3">
      <div className="no-print flex flex-wrap items-center gap-2">
        <h1 className="mr-auto text-xl font-extrabold">Inventory</h1>
        {can('inventory.manage') && (
          <button onClick={() => { setTransfer({ from_batch_id: '', to_batch_id: '', qty: '', note: '' }); setTransferOpen(true); }}
            className="flex h-10 items-center gap-1.5 rounded-xl border border-slate-200 px-4 text-xs font-bold dark:border-slate-700">
            <ArrowLeftRight size={15} /> Transfer stock
          </button>
        )}
      </div>

      {/* Valuation summary */}
      <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <StatCard icon={Wallet} label="Total stock value" value={Number(valuation?.total_value || 0).toLocaleString()} tone="brand" />
        <StatCard icon={Plus} label="Total units" value={Number(valuation?.total_qty || 0).toLocaleString()} tone="green" />
        <StatCard icon={AlertTriangle} label="Low stock items" value={valuation?.low_stock_count ?? '—'} tone="amber" />
        <StatCard icon={Hourglass} label="Expiring ≤ 90 days" value={valuation?.expiring_count ?? '—'} tone="red" />
      </div>

      {/* Tabs */}
      <div className="no-print flex gap-1 overflow-x-auto">
        {TABS.map((t) => (
          <button
            key={t.id}
            onClick={() => setTab(t.id)}
            className={`h-10 whitespace-nowrap rounded-xl px-4 text-sm font-bold ${
              tab === t.id ? 'bg-brand-600 text-white' : 'bg-white text-slate-600 dark:bg-slate-900 dark:text-slate-300'
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      {tab === 'history' ? (
        <div className="glass p-3">
          {history.length === 0 ? (
            <EmptyState title="No movements yet" hint="Stock in/out/adjust/transfer entries will appear here." />
          ) : (
            <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
              {history.map((h, i) => (
                <li key={i} className="flex items-center justify-between py-2.5">
                  <div>
                    <p className="font-medium">{h.medicine_name || h.description || h.type}</p>
                    <p className="text-xs text-slate-500">{h.created_at} {h.note ? `• ${h.note}` : ''}</p>
                  </div>
                  <span className={`rounded-full px-2.5 py-0.5 text-xs font-bold ${String(h.type).includes('in') ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600'} dark:bg-slate-800 dark:text-slate-300`}>
                    {h.qty ? `${h.qty > 0 ? '+' : ''}${h.qty}` : h.type}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </div>
      ) : (
        <>
          <div className="no-print glass p-3">
            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search medicine / batch…"
              className="h-10 w-full max-w-sm rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800" />
          </div>
          <div className="glass p-3">
            {loading && rows.length === 0 ? <LoadingSkeleton rows={5} /> : (
              <DataTable columns={columns} data={rows} meta={meta} onPage={setPage} emptyText="No inventory records." />
            )}
          </div>
        </>
      )}

      {/* Adjust stock */}
      <Modal open={adjustOpen} onClose={() => setAdjustOpen(false)} title={`Adjust stock — ${adjustFor?.medicine_name || adjustFor?.name || ''}`}>
        <form onSubmit={submitAdjust} className="space-y-3">
          <div className="grid grid-cols-2 gap-2">
            {['in', 'out'].map((t) => (
              <button type="button" key={t} onClick={() => setAdjust((a) => ({ ...a, type: t }))}
                className={`flex h-12 items-center justify-center gap-1.5 rounded-xl border text-sm font-bold capitalize ${
                  adjust.type === t ? (t === 'in' ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-red-600 bg-red-600 text-white') : 'border-slate-200 dark:border-slate-700'
                }`}>
                {t === 'in' ? <Plus size={16} /> : <Minus size={16} />} Stock {t}
              </button>
            ))}
          </div>
          <label className="block text-xs font-semibold">Quantity
            <input value={adjust.qty} onChange={(e) => setAdjust((a) => ({ ...a, qty: e.target.value }))} inputMode="numeric"
              className="mt-1 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800" />
          </label>
          <label className="block text-xs font-semibold">Reason
            <input value={adjust.reason} onChange={(e) => setAdjust((a) => ({ ...a, reason: e.target.value }))} placeholder="e.g. damaged, recount"
              className="mt-1 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800" />
          </label>
          <div className="flex justify-end gap-2">
            <button type="button" onClick={() => setAdjustOpen(false)} className="h-11 rounded-xl border border-slate-200 px-5 text-sm font-bold dark:border-slate-700">Cancel</button>
            <button type="submit" disabled={busy} className="h-11 rounded-xl bg-brand-600 px-6 text-sm font-bold text-white disabled:opacity-50">
              {busy ? 'Saving…' : 'Apply'}
            </button>
          </div>
        </form>
      </Modal>

      {/* Transfer stock */}
      <Modal open={transferOpen} onClose={() => setTransferOpen(false)} title="Transfer stock between batches">
        <form onSubmit={submitTransfer} className="space-y-3">
          <label className="block text-xs font-semibold">From batch ID
            <input value={transfer.from_batch_id} onChange={(e) => setTransfer((t) => ({ ...t, from_batch_id: e.target.value }))} inputMode="numeric"
              className="mt-1 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800" />
          </label>
          <label className="block text-xs font-semibold">To batch ID
            <input value={transfer.to_batch_id} onChange={(e) => setTransfer((t) => ({ ...t, to_batch_id: e.target.value }))} inputMode="numeric"
              className="mt-1 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800" />
          </label>
          <label className="block text-xs font-semibold">Quantity
            <input value={transfer.qty} onChange={(e) => setTransfer((t) => ({ ...t, qty: e.target.value }))} inputMode="numeric"
              className="mt-1 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800" />
          </label>
          <label className="block text-xs font-semibold">Note
            <input value={transfer.note} onChange={(e) => setTransfer((t) => ({ ...t, note: e.target.value }))}
              className="mt-1 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800" />
          </label>
          <div className="flex justify-end gap-2">
            <button type="button" onClick={() => setTransferOpen(false)} className="h-11 rounded-xl border border-slate-200 px-5 text-sm font-bold dark:border-slate-700">Cancel</button>
            <button type="submit" disabled={busy} className="h-11 rounded-xl bg-brand-600 px-6 text-sm font-bold text-white disabled:opacity-50">
              {busy ? 'Saving…' : 'Transfer'}
            </button>
          </div>
        </form>
      </Modal>

      {toast && (
        <div className="no-print fixed bottom-16 left-1/2 z-[70] -translate-x-1/2 rounded-full bg-slate-900 px-4 py-2 text-xs font-semibold text-white shadow-lg">
          {toast}
        </div>
      )}
    </div>
  );
}
