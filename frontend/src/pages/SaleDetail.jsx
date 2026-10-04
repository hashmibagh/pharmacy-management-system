import React, { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Printer, RotateCcw } from 'lucide-react';
import api from '../api/client';
import { ENDPOINTS } from '../api/endpoints';
import { usePermissions } from '../hooks/usePermissions';
import { Modal } from '../components/Modal';
import EmptyState from '../components/EmptyState';
import LoadingSkeleton from '../components/LoadingSkeleton';
import { StatusPill } from './Purchases';
import { SyncBadge } from './Sales';

/** Receipt view for one sale: printable (thermal 80mm + A4), returns. */
export default function SaleDetail() {
  const { id } = useParams();
  const { can } = usePermissions();
  const [sale, setSale] = useState(null);
  const [settings, setSettings] = useState({});
  const [loading, setLoading] = useState(true);
  const [returnOpen, setReturnOpen] = useState(false);
  const [returnItems, setReturnItems] = useState({});
  const [busy, setBusy] = useState(false);
  const [toast, setToast] = useState('');

  const showToast = (m) => {
    setToast(m);
    setTimeout(() => setToast(''), 3000);
  };

  const fetchSale = useCallback(async () => {
    setLoading(true);
    try {
      const [s, st] = await Promise.all([
        api.get(ENDPOINTS.sales.detail(id)),
        api.get(ENDPOINTS.settings).catch(() => null),
      ]);
      setSale(s.unwrapped?.data || null);
      setSettings(st?.unwrapped?.data || {});
    } catch (e) {
      setSale(null);
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => {
    fetchSale();
  }, [fetchSale]);

  const submitReturn = async () => {
    const items = Object.entries(returnItems)
      .filter(([, qty]) => Number(qty) > 0)
      .map(([key, qty]) => ({ item_id: key, qty: Number(qty) }));
    if (items.length === 0) return showToast('Enter a return quantity for at least one item.');
    setBusy(true);
    try {
      await api.post(ENDPOINTS.sales.return(id), { items });
      showToast('Return recorded.');
      setReturnOpen(false);
      setReturnItems({});
      fetchSale();
    } catch (err) {
      showToast(err.message || 'Return failed.');
    } finally {
      setBusy(false);
    }
  };

  if (loading) return <LoadingSkeleton rows={6} />;
  if (!sale) return <EmptyState title="Sale not found" hint="It may have been deleted." />;

  const items = sale.items || [];
  const shop = settings.pharmacy || {};
  const thermal = settings.invoice?.paper === 'thermal';

  return (
    <div className={`space-y-3 ${thermal ? 'print-thermal' : ''}`}>
      <div className="no-print flex flex-wrap items-center gap-2">
        <Link to="/sales" className="rounded-xl p-2 hover:bg-slate-200 dark:hover:bg-slate-800" aria-label="Back to sales">
          <ArrowLeft size={18} />
        </Link>
        <h1 className="mr-auto font-mono text-xl font-extrabold">{sale.invoice_no || `#${sale.id}`}</h1>
        <SyncBadge status="confirmed" />
        <StatusPill status={sale.payment_status || sale.status} />
        {can('sales.create') && (
          <button onClick={() => setReturnOpen(true)} className="flex h-10 items-center gap-1.5 rounded-xl border border-slate-200 px-4 text-xs font-bold dark:border-slate-700">
            <RotateCcw size={15} /> Return
          </button>
        )}
        <button onClick={() => window.print()} className="flex h-10 items-center gap-1.5 rounded-xl bg-brand-600 px-4 text-xs font-bold text-white">
          <Printer size={15} /> Print
        </button>
      </div>

      {/* Printable receipt */}
      <div className="print-area glass mx-auto max-w-xl p-6">
        <div className="text-center">
          <h2 className="text-lg font-extrabold">{shop.name || 'Pharmacy'}</h2>
          <p className="text-xs text-slate-500">{shop.address || ''} {shop.phone ? `• ${shop.phone}` : ''}</p>
          <p className="mt-2 font-mono text-sm font-bold">Receipt {sale.invoice_no || `#${sale.id}`}</p>
          <p className="text-xs text-slate-500">
            {String(sale.date || sale.created_at || '').slice(0, 16).replace('T', ' ')}
            {' • '}Cashier: {sale.user?.name || sale.cashier || '—'}
          </p>
          <p className="text-xs text-slate-500">Customer: {sale.customer?.name || 'Walk-in'}</p>
        </div>
        <hr className="my-3 border-dashed" />
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-xs uppercase text-slate-500">
              <th className="py-1">Item</th><th className="py-1 text-right">Qty</th>
              <th className="py-1 text-right">Price</th><th className="py-1 text-right">Amount</th>
            </tr>
          </thead>
          <tbody>
            {items.map((it, i) => (
              <tr key={i} className="border-t border-dashed border-slate-200">
                <td className="py-1.5">
                  <p className="font-medium">{it.medicine_name || it.name}</p>
                  {it.discount_percent > 0 && <p className="text-xs text-emerald-600">{it.discount_percent}% off</p>}
                </td>
                <td className="py-1.5 text-right">{it.qty}</td>
                <td className="py-1.5 text-right">{Number(it.unit_price ?? 0).toLocaleString()}</td>
                <td className="py-1.5 text-right font-semibold">{Number(it.amount ?? it.qty * (it.unit_price ?? 0)).toLocaleString()}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <hr className="my-3 border-dashed" />
        <div className="space-y-1 text-sm">
          <div className="flex justify-between"><span>Subtotal</span><span>{Number(sale.subtotal ?? 0).toLocaleString()}</span></div>
          <div className="flex justify-between"><span>Discount</span><span>−{Number(sale.discount ?? 0).toLocaleString()}</span></div>
          <div className="flex justify-between"><span>Tax</span><span>+{Number(sale.tax_amount ?? 0).toLocaleString()}</span></div>
          <div className="flex justify-between text-base font-extrabold"><span>Total</span><span>{Number(sale.total ?? 0).toLocaleString()}</span></div>
          <div className="flex justify-between"><span>Paid ({String(sale.payment_method || 'cash').replace('_', ' ')})</span><span>{Number(sale.paid_amount ?? 0).toLocaleString()}</span></div>
          <div className="flex justify-between"><span>Change</span><span>{Number(sale.change_amount ?? 0).toLocaleString()}</span></div>
        </div>
        <hr className="my-3 border-dashed" />
        <p className="text-center text-xs text-slate-500">{shop.receipt_footer || 'Thank you — get well soon!'}</p>
      </div>

      {/* Return modal */}
      <Modal open={returnOpen} onClose={() => setReturnOpen(false)} title="Return items">
        <ul className="space-y-2">
          {items.map((it, i) => (
            <li key={i} className="flex items-center justify-between gap-2 rounded-xl border border-slate-200 p-2.5 dark:border-slate-700">
              <span className="text-sm font-medium">{it.medicine_name || it.name} <span className="text-xs text-slate-500">(sold {it.qty})</span></span>
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

      {toast && (
        <div className="no-print fixed bottom-16 left-1/2 z-[70] -translate-x-1/2 rounded-full bg-slate-900 px-4 py-2 text-xs font-semibold text-white shadow-lg">
          {toast}
        </div>
      )}
    </div>
  );
}
