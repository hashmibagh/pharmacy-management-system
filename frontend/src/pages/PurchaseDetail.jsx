import React, { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Pencil, Trash2, Printer, Wallet, RotateCcw } from 'lucide-react';
import api from '../api/client';
import { ENDPOINTS } from '../api/endpoints';
import { usePermissions } from '../hooks/usePermissions';
import { Modal, ConfirmDialog } from '../components/Modal';
import EmptyState from '../components/EmptyState';
import LoadingSkeleton from '../components/LoadingSkeleton';
import { StatusPill } from './Purchases';
import PurchaseEditor from '../components/PurchaseEditor';

/** Detail view for one purchase invoice: items/batches, payments, returns. */
export default function PurchaseDetail() {
  const { id } = useParams();
  const { can } = usePermissions();
  const [inv, setInv] = useState(null);
  const [loading, setLoading] = useState(true);
  const [editOpen, setEditOpen] = useState(false);
  const [payOpen, setPayOpen] = useState(false);
  const [payForm, setPayForm] = useState({ amount: '', method: 'cash', note: '' });
  const [returnOpen, setReturnOpen] = useState(false);
  const [returnItems, setReturnItems] = useState({});
  const [deleting, setDeleting] = useState(false);
  const [busy, setBusy] = useState(false);
  const [toast, setToast] = useState('');

  const showToast = (m) => {
    setToast(m);
    setTimeout(() => setToast(''), 3000);
  };

  const fetchInv = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get(ENDPOINTS.purchases.detail(id));
      setInv(res.unwrapped?.data || null);
    } catch (e) {
      setInv(null);
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => {
    fetchInv();
  }, [fetchInv]);

  const submitPayment = async (e) => {
    e.preventDefault();
    const amount = Number(payForm.amount);
    if (!amount || amount <= 0) return showToast('Enter a valid amount.');
    setBusy(true);
    try {
      await api.post(ENDPOINTS.purchases.payment(id), {
        amount, payment_method: payForm.method, note: payForm.note,
      });
      showToast('Payment recorded.');
      setPayOpen(false);
      setPayForm({ amount: '', method: 'cash', note: '' });
      fetchInv();
    } catch (err) {
      showToast(err.message || 'Payment failed.');
    } finally {
      setBusy(false);
    }
  };

  const submitReturn = async () => {
    const items = Object.entries(returnItems)
      .filter(([, qty]) => Number(qty) > 0)
      .map(([key, qty]) => ({ item_id: key, qty: Number(qty) }));
    if (items.length === 0) return showToast('Enter a return quantity for at least one item.');
    setBusy(true);
    try {
      await api.post(ENDPOINTS.purchases.return(id), { items });
      showToast('Return recorded.');
      setReturnOpen(false);
      setReturnItems({});
      fetchInv();
    } catch (err) {
      showToast(err.message || 'Return failed.');
    } finally {
      setBusy(false);
    }
  };

  const doDelete = async () => {
    setBusy(true);
    try {
      await api.delete(ENDPOINTS.purchases.detail(id));
      showToast('Purchase deleted.');
      window.history.back();
    } catch (e) {
      showToast(e.message || 'Delete failed.');
    } finally {
      setBusy(false);
      setDeleting(false);
    }
  };

  if (loading) return <LoadingSkeleton rows={6} />;
  if (!inv) return <EmptyState title="Purchase not found" hint="It may have been deleted." />;

  const items = inv.items || [];
  const payments = inv.payments || [];
  const total = Number(inv.total ?? inv.grand_total ?? 0);
  const paid = Number(inv.paid_amount ?? 0);

  return (
    <div className="space-y-3">
      <div className="no-print flex flex-wrap items-center gap-2">
        <Link to="/purchases" className="rounded-xl p-2 hover:bg-slate-200 dark:hover:bg-slate-800" aria-label="Back to purchases">
          <ArrowLeft size={18} />
        </Link>
        <h1 className="mr-auto font-mono text-xl font-extrabold">{inv.invoice_no || `#${inv.id}`}</h1>
        <StatusPill status={inv.payment_status || inv.status} />
        {can('purchases.create') && (
          <button onClick={() => setPayOpen(true)} className="flex h-10 items-center gap-1.5 rounded-xl bg-emerald-600 px-4 text-xs font-bold text-white">
            <Wallet size={15} /> Add payment
          </button>
        )}
        {can('purchases.edit') && (
          <button onClick={() => setEditOpen(true)} className="flex h-10 items-center gap-1.5 rounded-xl border border-slate-200 px-4 text-xs font-bold dark:border-slate-700">
            <Pencil size={15} /> Edit
          </button>
        )}
        {can('purchases.create') && (
          <button onClick={() => setReturnOpen(true)} className="flex h-10 items-center gap-1.5 rounded-xl border border-slate-200 px-4 text-xs font-bold dark:border-slate-700">
            <RotateCcw size={15} /> Return
          </button>
        )}
        <button onClick={() => window.print()} className="flex h-10 items-center gap-1.5 rounded-xl border border-slate-200 px-4 text-xs font-bold dark:border-slate-700">
          <Printer size={15} /> Print
        </button>
        {can('purchases.delete') && (
          <button onClick={() => setDeleting(true)} className="grid h-10 w-10 place-items-center rounded-xl border border-red-200 text-red-500 dark:border-red-900" aria-label="Delete">
            <Trash2 size={16} />
          </button>
        )}
      </div>

      <div className="print-area glass p-4">
        <div className="flex flex-wrap justify-between gap-2 text-sm">
          <div>
            <p className="text-xs text-slate-500">Supplier</p>
            <p className="font-bold">{inv.supplier?.name || '—'}</p>
            <p className="text-xs text-slate-500">{inv.supplier?.phone || ''}</p>
          </div>
          <div className="text-right">
            <p className="text-xs text-slate-500">Date</p>
            <p className="font-bold">{(inv.date || inv.created_at || '').slice(0, 10)}</p>
          </div>
        </div>
        <table className="mt-4 w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-left text-xs uppercase text-slate-500 dark:border-slate-700">
              <th className="py-2">Item</th><th className="py-2">Batch</th><th className="py-2 text-right">Qty</th>
              <th className="py-2 text-right">Price</th><th className="py-2 text-right">Amount</th>
            </tr>
          </thead>
          <tbody>
            {items.map((it, i) => (
              <tr key={i} className="border-b border-slate-100 dark:border-slate-800">
                <td className="py-2 font-medium">{it.medicine_name || it.name}</td>
                <td className="py-2 text-xs">{it.batch_no || '—'} {it.expiry_date ? `• Exp ${String(it.expiry_date).slice(0, 10)}` : ''}</td>
                <td className="py-2 text-right">{it.qty}</td>
                <td className="py-2 text-right">{Number(it.purchase_price ?? it.unit_price ?? 0).toLocaleString()}</td>
                <td className="py-2 text-right font-semibold">{Number(it.amount ?? it.qty * (it.purchase_price ?? 0)).toLocaleString()}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <div className="mt-3 space-y-1 text-sm">
          <div className="flex justify-between"><span className="text-slate-500">Total</span><span className="font-extrabold">{total.toLocaleString()}</span></div>
          <div className="flex justify-between"><span className="text-slate-500">Paid</span><span className="font-semibold text-emerald-600">{paid.toLocaleString()}</span></div>
          <div className="flex justify-between"><span className="text-slate-500">Balance due</span><span className="font-bold text-amber-600">{Math.max(0, total - paid).toLocaleString()}</span></div>
        </div>
        {inv.notes && <p className="mt-3 text-xs text-slate-500">Note: {inv.notes}</p>}
      </div>

      <div className="glass p-4">
        <h3 className="mb-2 text-sm font-bold">Payments ({payments.length})</h3>
        {payments.length === 0 ? (
          <p className="text-xs text-slate-500">No payments recorded yet.</p>
        ) : (
          <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
            {payments.map((p, i) => (
              <li key={i} className="flex items-center justify-between py-2">
                <span>{p.payment_method || p.method || 'Payment'} <span className="text-xs text-slate-500">• {String(p.date || p.created_at || '').slice(0, 10)}</span></span>
                <span className="font-bold text-emerald-600">{Number(p.amount || 0).toLocaleString()}</span>
              </li>
            ))}
          </ul>
        )}
      </div>

      {/* Payment modal */}
      <Modal open={payOpen} onClose={() => setPayOpen(false)} title="Record payment">
        <form onSubmit={submitPayment} className="space-y-3">
          <label className="block text-xs font-semibold">Amount
            <input value={payForm.amount} inputMode="decimal" onChange={(e) => setPayForm((f) => ({ ...f, amount: e.target.value }))}
              className="mt-1 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800" />
          </label>
          <label className="block text-xs font-semibold">Method
            <select value={payForm.method} onChange={(e) => setPayForm((f) => ({ ...f, method: e.target.value }))}
              className="mt-1 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800">
              <option value="cash">Cash</option><option value="bank">Bank</option>
              <option value="card">Card</option><option value="mobile_wallet">Mobile wallet</option>
            </select>
          </label>
          <label className="block text-xs font-semibold">Note
            <input value={payForm.note} onChange={(e) => setPayForm((f) => ({ ...f, note: e.target.value }))}
              className="mt-1 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800" />
          </label>
          <div className="flex justify-end gap-2">
            <button type="button" onClick={() => setPayOpen(false)} className="h-11 rounded-xl border border-slate-200 px-5 text-sm font-bold dark:border-slate-700">Cancel</button>
            <button type="submit" disabled={busy} className="h-11 rounded-xl bg-emerald-600 px-6 text-sm font-bold text-white disabled:opacity-50">
              {busy ? 'Saving…' : 'Record'}
            </button>
          </div>
        </form>
      </Modal>

      {/* Return modal */}
      <Modal open={returnOpen} onClose={() => setReturnOpen(false)} title="Return items to supplier">
        <ul className="space-y-2">
          {items.map((it, i) => (
            <li key={i} className="flex items-center justify-between gap-2 rounded-xl border border-slate-200 p-2.5 dark:border-slate-700">
              <span className="text-sm font-medium">{it.medicine_name || it.name} <span className="text-xs text-slate-500">(bought {it.qty})</span></span>
              <input value={returnItems[it.id || i] || ''} inputMode="numeric" placeholder="Qty"
                onChange={(e) => setReturnItems((r) => ({ ...r, [it.id || i]: e.target.value }))}
                className="h-10 w-20 rounded-lg border border-slate-200 px-2 text-center text-sm dark:border-slate-700 dark:bg-slate-800" />
            </li>
          ))}
        </ul>
        <div className="mt-4 flex justify-end gap-2">
          <button onClick={() => setReturnOpen(false)} className="h-11 rounded-xl border border-slate-200 px-5 text-sm font-bold dark:border-slate-700">Cancel</button>
          <button onClick={submitReturn} disabled={busy} className="h-11 rounded-xl bg-amber-600 px-6 text-sm font-bold text-white disabled:opacity-50">
            {busy ? 'Saving…' : 'Submit return'}
          </button>
        </div>
      </Modal>

      <PurchaseEditor open={editOpen} onClose={() => setEditOpen(false)} initial={inv} onSaved={fetchInv} />

      <ConfirmDialog open={deleting} onClose={() => setDeleting(false)} onConfirm={doDelete}
        title="Delete purchase" message={`Delete invoice “${inv.invoice_no || inv.id}”? Stock will be adjusted back.`} busy={busy} />

      {toast && (
        <div className="no-print fixed bottom-16 left-1/2 z-[70] -translate-x-1/2 rounded-full bg-slate-900 px-4 py-2 text-xs font-semibold text-white shadow-lg">
          {toast}
        </div>
      )}
    </div>
  );
}
