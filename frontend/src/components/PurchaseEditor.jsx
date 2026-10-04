import React, { useEffect, useState } from 'react';
import { Search, Trash2, Plus } from 'lucide-react';
import api from '../api/client';
import { ENDPOINTS } from '../api/endpoints';
import { useDebounce } from '../hooks/useDebounce';
import { Modal } from './Modal';

/**
 * Purchase invoice editor (create or edit): supplier select, medicine
 * line search, batch fields (batch no, expiry, mfg), qty, purchase price,
 * discount, totals, paid amount. Used by Purchases list and PurchaseDetail.
 */
export default function PurchaseEditor({ open, onClose, initial = null, onSaved }) {
  const [suppliers, setSuppliers] = useState([]);
  const [supplierId, setSupplierId] = useState('');
  const [invoiceNo, setInvoiceNo] = useState('');
  const [date, setDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [notes, setNotes] = useState('');
  const [lines, setLines] = useState([]);
  const [paid, setPaid] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const [q, setQ] = useState('');
  const [results, setResults] = useState([]);
  const debouncedQ = useDebounce(q, 350);

  useEffect(() => {
    if (!open) return;
    (async () => {
      try {
        const res = await api.get(ENDPOINTS.suppliers.list, { params: { per_page: 200 } });
        const d = res.unwrapped?.data;
        setSuppliers(d?.data || d || []);
      } catch (e) {
        /* offline */
      }
    })();
    if (initial) {
      setSupplierId(initial.supplier_id || '');
      setInvoiceNo(initial.invoice_no || '');
      setDate((initial.date || initial.created_at || '').slice(0, 10) || new Date().toISOString().slice(0, 10));
      setNotes(initial.notes || '');
      setLines((initial.items || []).map((it, i) => ({
        key: `l${i}`, medicine_id: it.medicine_id, name: it.medicine_name || it.name || '',
        batch_no: it.batch_no || '', expiry_date: (it.expiry_date || '').slice(0, 10),
        mfg_date: (it.mfg_date || '').slice(0, 10), qty: it.qty || 1,
        purchase_price: it.purchase_price ?? it.unit_price ?? 0, discount_pct: it.discount_percent || 0,
      })));
      setPaid(initial.paid_amount ?? '');
    } else {
      setSupplierId(''); setInvoiceNo('');
      setDate(new Date().toISOString().slice(0, 10));
      setNotes(''); setLines([]); setPaid('');
    }
    setError('');
  }, [open, initial]);

  useEffect(() => {
    if (!debouncedQ || debouncedQ.trim().length < 2) {
      setResults([]);
      return;
    }
    let cancelled = false;
    (async () => {
      try {
        const res = await api.get(ENDPOINTS.medicines.list, { params: { q: debouncedQ.trim(), per_page: 10 } });
        const d = res.unwrapped?.data;
        if (!cancelled) setResults(d?.data || d || []);
      } catch (e) {
        if (!cancelled) setResults([]);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [debouncedQ]);

  const addLine = (med) => {
    setLines((prev) => [
      ...prev,
      {
        key: `l${Date.now()}`, medicine_id: med.id, name: med.name,
        batch_no: '', expiry_date: '', mfg_date: '', qty: 1,
        purchase_price: Number(med.purchase_price ?? 0), discount_pct: 0,
      },
    ]);
    setQ('');
    setResults([]);
  };

  const setLine = (key, patch) =>
    setLines((prev) => prev.map((l) => (l.key === key ? { ...l, ...patch } : l)));

  const subtotal = lines.reduce((s, l) => s + l.qty * l.purchase_price * (1 - (l.discount_pct || 0) / 100), 0);
  const paidAmt = Number(paid) || 0;

  const save = async () => {
    setError('');
    if (!supplierId) return setError('Select a supplier.');
    if (lines.length === 0) return setError('Add at least one item.');
    setSaving(true);
    const payload = {
      supplier_id: Number(supplierId),
      invoice_no: invoiceNo || undefined,
      date,
      notes,
      items: lines.map((l) => ({
        medicine_id: l.medicine_id,
        batch_no: l.batch_no || undefined,
        expiry_date: l.expiry_date || undefined,
        mfg_date: l.mfg_date || undefined,
        qty: Number(l.qty),
        purchase_price: Number(l.purchase_price),
        discount_percent: Number(l.discount_pct) || 0,
      })),
      total: subtotal,
      paid_amount: paidAmt,
    };
    try {
      if (initial?.id) await api.put(ENDPOINTS.purchases.detail(initial.id), payload);
      else await api.post(ENDPOINTS.purchases.create, payload);
      onSaved?.();
      onClose();
    } catch (e) {
      setError(e.message || 'Save failed.');
    } finally {
      setSaving(false);
    }
  };

  const inp = 'h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800';

  return (
    <Modal open={open} onClose={onClose} title={initial ? 'Edit purchase' : 'New purchase'} wide>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <label className="text-xs font-semibold">Supplier *
          <select value={supplierId} onChange={(e) => setSupplierId(e.target.value)} className={`${inp} mt-1`}>
            <option value="">— Select —</option>
            {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </select>
        </label>
        <label className="text-xs font-semibold">Invoice no
          <input value={invoiceNo} onChange={(e) => setInvoiceNo(e.target.value)} placeholder="Auto if blank" className={`${inp} mt-1`} />
        </label>
        <label className="text-xs font-semibold">Date
          <input type="date" value={date} onChange={(e) => setDate(e.target.value)} className={`${inp} mt-1`} />
        </label>
      </div>

      <div className="mt-3">
        <p className="mb-1 text-xs font-semibold">Add items</p>
        <div className="relative">
          <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search medicine…"
            className="h-11 w-full rounded-xl border border-slate-200 bg-white pl-9 pr-3 text-sm dark:border-slate-700 dark:bg-slate-800" />
        </div>
        {results.length > 0 && (
          <ul className="mt-1 max-h-48 divide-y divide-slate-100 overflow-y-auto rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-700">
            {results.map((m) => (
              <li key={m.id}>
                <button onClick={() => addLine(m)} className="flex w-full items-center justify-between px-3 py-2 text-left text-sm">
                  <span className="font-medium">{m.name}</span>
                  <Plus size={15} className="text-brand-600" />
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>

      <ul className="mt-3 space-y-2">
        {lines.map((l) => (
          <li key={l.key} className="rounded-xl border border-slate-200 p-2.5 dark:border-slate-700">
            <div className="flex items-center justify-between">
              <p className="text-sm font-bold">{l.name}</p>
              <button onClick={() => setLines((p) => p.filter((x) => x.key !== l.key))} className="rounded-lg p-1.5 text-red-500" aria-label="Remove">
                <Trash2 size={15} />
              </button>
            </div>
            <div className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-6">
              <label className="text-[11px] font-semibold">Batch no
                <input value={l.batch_no} onChange={(e) => setLine(l.key, { batch_no: e.target.value })} className="mt-0.5 h-10 w-full rounded-lg border border-slate-200 px-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
              </label>
              <label className="text-[11px] font-semibold">Expiry
                <input type="date" value={l.expiry_date} onChange={(e) => setLine(l.key, { expiry_date: e.target.value })} className="mt-0.5 h-10 w-full rounded-lg border border-slate-200 px-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
              </label>
              <label className="text-[11px] font-semibold">Qty
                <input value={l.qty} inputMode="numeric" onChange={(e) => setLine(l.key, { qty: Number(e.target.value) || 0 })} className="mt-0.5 h-10 w-full rounded-lg border border-slate-200 px-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
              </label>
              <label className="text-[11px] font-semibold">Price
                <input value={l.purchase_price} inputMode="decimal" onChange={(e) => setLine(l.key, { purchase_price: Number(e.target.value) || 0 })} className="mt-0.5 h-10 w-full rounded-lg border border-slate-200 px-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
              </label>
              <label className="text-[11px] font-semibold">Disc %
                <input value={l.discount_pct} inputMode="decimal" onChange={(e) => setLine(l.key, { discount_pct: Number(e.target.value) || 0 })} className="mt-0.5 h-10 w-full rounded-lg border border-slate-200 px-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
              </label>
              <div className="flex items-end pb-1 text-sm font-extrabold">
                {(l.qty * l.purchase_price * (1 - (l.discount_pct || 0) / 100)).toLocaleString(undefined, { maximumFractionDigits: 2 })}
              </div>
            </div>
          </li>
        ))}
        {lines.length === 0 && <li className="py-4 text-center text-xs text-slate-500">No items yet.</li>}
      </ul>

      <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <label className="text-xs font-semibold">Paid amount
          <input value={paid} inputMode="decimal" onChange={(e) => setPaid(e.target.value)} className={`${inp} mt-1`} />
        </label>
        <div className="text-sm">
          <p className="text-xs font-semibold text-slate-500">Total</p>
          <p className="text-xl font-extrabold">{subtotal.toLocaleString(undefined, { maximumFractionDigits: 2 })}</p>
        </div>
        <div className="text-sm">
          <p className="text-xs font-semibold text-slate-500">Balance due</p>
          <p className="text-xl font-extrabold text-amber-600">{Math.max(0, subtotal - paidAmt).toLocaleString(undefined, { maximumFractionDigits: 2 })}</p>
        </div>
      </div>

      {error && <p className="mt-2 rounded-xl bg-red-50 px-3 py-2 text-xs text-red-600 dark:bg-red-950/50">{error}</p>}

      <div className="mt-4 flex justify-end gap-2">
        <button onClick={onClose} className="h-11 rounded-xl border border-slate-200 px-5 text-sm font-bold dark:border-slate-700">Cancel</button>
        <button onClick={save} disabled={saving} className="h-11 rounded-xl bg-brand-600 px-6 text-sm font-bold text-white disabled:opacity-50">
          {saving ? 'Saving…' : initial ? 'Update purchase' : 'Save purchase'}
        </button>
      </div>
    </Modal>
  );
}
