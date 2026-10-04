import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  Search, Plus, Minus, Trash2, User, Pause, Play, CheckCircle2,
  CloudOff, CloudUpload, X, Printer,
} from 'lucide-react';
import api, { isOnline } from '../api/client';
import { ENDPOINTS } from '../api/endpoints';
import { useDebounce } from '../hooks/useDebounce';
import { useOnlineStatus } from '../hooks/useOnlineStatus';
import { usePermissions } from '../hooks/usePermissions';
import { ScanButton } from '../components/BarcodeScanner';
import { Modal } from '../components/Modal';
import EmptyState from '../components/EmptyState';
import { enqueueOutbox } from '../offline/db';
import { flushOutbox, registerBackgroundSync } from '../offline/sync';

const PAYMENT_METHODS = ['cash', 'card', 'bank', 'mobile_wallet', 'credit', 'partial'];

function money(n) {
  return Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
}

/**
 * Fast pharmacy POS, optimized for Android phones:
 * - big touch targets, numeric keypads (inputMode), single-column flow
 * - debounced server-side medicine search, barcode scan input
 * - batch selection per line, customer select, discount/tax
 * - payment methods: cash/card/bank/mobile wallet/credit/partial
 * - hold/resume tickets; offline sales queued with clear status badges
 */
export default function POS() {
  const navigate = useNavigate();
  const online = useOnlineStatus();
  const { can } = usePermissions();

  const [query, setQuery] = useState('');
  const [results, setResults] = useState([]);
  const [searching, setSearching] = useState(false);
  const debouncedQ = useDebounce(query, 350);

  const [cart, setCart] = useState([]); // {key, medicine_id, name, batch_id, batch_no, expiry, stock, qty, unit_price, discount_pct}
  const [customers, setCustomers] = useState([]);
  const [customerId, setCustomerId] = useState('');
  const [billDiscount, setBillDiscount] = useState(0);
  const [taxPct, setTaxPct] = useState(0);
  const [paymentMethod, setPaymentMethod] = useState('cash');
  const [paid, setPaid] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const [batchPick, setBatchPick] = useState(null); // medicine awaiting batch choice
  const [held, setHeld] = useState([]);
  const [heldOpen, setHeldOpen] = useState(false);
  const [receipt, setReceipt] = useState(null); // {status:'confirmed'|'queued', ref, total, ...}
  const searchRef = useRef(null);

  // Medicine search (server-side)
  useEffect(() => {
    if (!debouncedQ || debouncedQ.trim().length < 2) {
      setResults([]);
      return;
    }
    let cancelled = false;
    (async () => {
      setSearching(true);
      try {
        const res = await api.get(ENDPOINTS.medicines.list, {
          params: { q: debouncedQ.trim(), per_page: 12, in_stock: 1 },
        });
        if (!cancelled) {
          const d = res.unwrapped?.data;
          setResults(d?.data || d || []);
        }
      } catch (e) {
        if (!cancelled) setResults([]);
      } finally {
        if (!cancelled) setSearching(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [debouncedQ]);

  // Customers for the dropdown
  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const res = await api.get(ENDPOINTS.customers.list, { params: { per_page: 100 } });
        const d = res.unwrapped?.data;
        if (!cancelled) setCustomers(d?.data || d || []);
      } catch (e) {
        /* offline: keep walk-in */
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  // Held tickets (device-local)
  useEffect(() => {
    (async () => {
      const { getHeldSales } = await import('../offline/db');
      setHeld(await getHeldSales());
    })();
  }, []);

  const addMedicine = (med) => {
    const batches = med.batches || [];
    const inStock = batches.filter((b) => Number(b.qty ?? b.stock ?? 0) > 0);
    if (inStock.length > 1) {
      setBatchPick({ med, batches: inStock });
      return;
    }
    const batch = inStock[0] || batches[0] || {};
    pushLine(med, batch);
  };

  const pushLine = (med, batch = {}) => {
    const key = `${med.id}:${batch.id || 'nobatch'}`;
    setCart((prev) => {
      const existing = prev.find((l) => l.key === key);
      if (existing) {
        return prev.map((l) => (l.key === key ? { ...l, qty: l.qty + 1 } : l));
      }
      return [
        ...prev,
        {
          key,
          medicine_id: med.id,
          name: med.name,
          batch_id: batch.id || null,
          batch_no: batch.batch_no || batch.batch_number || '',
          expiry: batch.expiry_date || '',
          stock: Number(batch.qty ?? batch.stock ?? med.stock ?? 0),
          qty: 1,
          unit_price: Number(batch.sale_price ?? med.sale_price ?? med.price ?? 0),
          discount_pct: 0,
        },
      ];
    });
    setBatchPick(null);
    setQuery('');
    setResults([]);
    searchRef.current?.focus();
  };

  const setQty = (key, qty) => {
    const n = Math.max(0, Number(qty) || 0);
    setCart((prev) => prev.map((l) => (l.key === key ? { ...l, qty: n } : l)).filter((l) => l.qty > 0));
  };

  const setLine = (key, patch) => {
    setCart((prev) => prev.map((l) => (l.key === key ? { ...l, ...patch } : l)));
  };

  // Totals
  const subtotal = useMemo(
    () => cart.reduce((s, l) => s + l.qty * l.unit_price * (1 - (l.discount_pct || 0) / 100), 0),
    [cart],
  );
  const discountAmt = Math.min(Number(billDiscount) || 0, subtotal);
  const taxAmt = ((subtotal - discountAmt) * (Number(taxPct) || 0)) / 100;
  const total = Math.max(0, subtotal - discountAmt + taxAmt);
  const paidAmt = Number(paid) || 0;
  const balance = paidAmt - total;

  const buildPayload = () => ({
    customer_id: customerId || null,
    items: cart.map((l) => ({
      medicine_id: l.medicine_id,
      batch_id: l.batch_id,
      qty: l.qty,
      unit_price: l.unit_price,
      discount_percent: l.discount_pct || 0,
    })),
    discount: discountAmt,
    tax_percent: taxPct,
    tax_amount: taxAmt,
    total,
    payment_method: paymentMethod,
    paid_amount: paymentMethod === 'credit' ? 0 : paidAmt,
    change_amount: Math.max(0, balance),
  });

  const resetSale = () => {
    setCart([]);
    setCustomerId('');
    setBillDiscount(0);
    setTaxPct(0);
    setPaymentMethod('cash');
    setPaid('');
  };

  const submitSale = async () => {
    if (cart.length === 0 || submitting) return;
    if (paymentMethod !== 'credit' && paidAmt < total) {
      if (!window.confirm(`Paid amount (${money(paidAmt)}) is less than total (${money(total)}). Record as partial payment?`)) return;
    }
    setSubmitting(true);
    const payload = buildPayload();
    try {
      if (!isOnline()) throw new Error('offline');
      const res = await api.post(ENDPOINTS.sales.create, payload);
      const data = res.unwrapped?.data || {};
      setReceipt({
        status: 'confirmed',
        ref: data.invoice_no || data.id || '—',
        total,
        method: paymentMethod,
        paid: paymentMethod === 'credit' ? 0 : paidAmt,
        change: Math.max(0, balance),
      });
      resetSale();
    } catch (e) {
      // Offline (or network failure): queue for background sync.
      const item = await enqueueOutbox({
        kind: 'sale',
        method: 'POST',
        url: ENDPOINTS.sales.create,
        payload,
      });
      registerBackgroundSync();
      flushOutbox().catch(() => {});
      setReceipt({
        status: 'queued',
        ref: `OFFLINE-${item.localId.slice(-6).toUpperCase()}`,
        total,
        method: paymentMethod,
        paid: paymentMethod === 'credit' ? 0 : paidAmt,
        change: 0,
        outboxId: item.id,
      });
      resetSale();
    } finally {
      setSubmitting(false);
    }
  };

  const holdSale = async () => {
    if (cart.length === 0) return;
    const { saveHeldSale, getHeldSales } = await import('../offline/db');
    await saveHeldSale({
      cart, customerId, billDiscount, taxPct, paymentMethod, paid,
    });
    setHeld(await getHeldSales());
    resetSale();
  };

  const resumeHeld = async (ticket) => {
    setCart(ticket.cart || []);
    setCustomerId(ticket.customerId || '');
    setBillDiscount(ticket.billDiscount || 0);
    setTaxPct(ticket.taxPct || 0);
    setPaymentMethod(ticket.paymentMethod || 'cash');
    setPaid(ticket.paid || '');
    const { removeHeldSale, getHeldSales } = await import('../offline/db');
    await removeHeldSale(ticket.id);
    setHeld(await getHeldSales());
    setHeldOpen(false);
  };

  const deleteHeld = async (id) => {
    const { removeHeldSale, getHeldSales } = await import('../offline/db');
    await removeHeldSale(id);
    setHeld(await getHeldSales());
  };

  return (
    <div className="grid grid-cols-1 gap-3 lg:grid-cols-5">
      {/* Left: search + results */}
      <div className="space-y-3 lg:col-span-3">
        <div className="glass p-3">
          <div className="flex gap-2">
            <div className="relative flex-1">
              <Search size={18} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
              <input
                ref={searchRef}
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder="Search medicine name / generic…"
                className="h-12 w-full rounded-xl border border-slate-200 bg-white pl-10 pr-3 text-base dark:border-slate-700 dark:bg-slate-800"
              />
            </div>
            <ScanButton onScan={(code) => { setQuery(code); }} />
          </div>

          {searching && <p className="mt-2 text-xs text-slate-500">Searching…</p>}
          {results.length > 0 && (
            <ul className="mt-2 max-h-72 divide-y divide-slate-100 overflow-y-auto rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-700">
              {results.map((m) => (
                <li key={m.id}>
                  <button
                    onClick={() => addMedicine(m)}
                    className="flex w-full items-center justify-between gap-2 px-3 py-3 text-left active:bg-brand-50 dark:active:bg-slate-800"
                  >
                    <span>
                      <span className="block text-sm font-semibold">{m.name}</span>
                      <span className="block text-xs text-slate-500">
                        {m.generic_name || m.manufacturer?.name || ''} • Stock {m.stock ?? '—'}
                      </span>
                    </span>
                    <span className="text-sm font-bold text-brand-600">{money(m.sale_price ?? m.price)}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
          {debouncedQ.trim().length >= 2 && !searching && results.length === 0 && (
            <p className="mt-2 text-xs text-slate-500">No medicines found for “{debouncedQ}”.</p>
          )}
        </div>

        {/* Cart lines */}
        <div className="glass p-3">
          <h3 className="mb-2 text-sm font-bold">Items ({cart.length})</h3>
          {cart.length === 0 ? (
            <EmptyState title="Cart is empty" hint="Search a medicine above or scan a barcode to add it." />
          ) : (
            <ul className="space-y-2">
              {cart.map((l) => (
                <li key={l.key} className="rounded-xl border border-slate-200 p-2.5 dark:border-slate-700">
                  <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                      <p className="truncate text-sm font-semibold">{l.name}</p>
                      <p className="text-[11px] text-slate-500">
                        {l.batch_no ? `Batch ${l.batch_no}` : 'No batch'} {l.expiry ? `• Exp ${l.expiry}` : ''}
                      </p>
                    </div>
                    <button onClick={() => setCart((p) => p.filter((x) => x.key !== l.key))} className="rounded-lg p-1.5 text-red-500" aria-label="Remove line">
                      <Trash2 size={17} />
                    </button>
                  </div>
                  <div className="mt-2 flex flex-wrap items-center gap-2">
                    <div className="flex items-center rounded-xl border border-slate-200 dark:border-slate-700">
                      <button onClick={() => setQty(l.key, l.qty - 1)} className="grid h-10 w-10 place-items-center" aria-label="Decrease qty">
                        <Minus size={16} />
                      </button>
                      <input
                        value={l.qty}
                        inputMode="decimal"
                        onChange={(e) => setQty(l.key, e.target.value)}
                        className="h-10 w-14 bg-transparent text-center text-base font-bold"
                        aria-label="Quantity"
                      />
                      <button onClick={() => setQty(l.key, l.qty + 1)} className="grid h-10 w-10 place-items-center" aria-label="Increase qty">
                        <Plus size={16} />
                      </button>
                    </div>
                    <div className="flex items-center gap-1">
                      <span className="text-xs text-slate-500">Rs</span>
                      <input
                        value={l.unit_price}
                        inputMode="decimal"
                        onChange={(e) => setLine(l.key, { unit_price: Number(e.target.value) || 0 })}
                        className="h-10 w-24 rounded-xl border border-slate-200 bg-transparent px-2 text-sm font-semibold dark:border-slate-700"
                        aria-label="Unit price"
                      />
                    </div>
                    <div className="flex items-center gap-1">
                      <input
                        value={l.discount_pct}
                        inputMode="decimal"
                        onChange={(e) => setLine(l.key, { discount_pct: Math.min(100, Number(e.target.value) || 0) })}
                        className="h-10 w-16 rounded-xl border border-slate-200 bg-transparent px-2 text-center text-sm dark:border-slate-700"
                        aria-label="Line discount %"
                      />
                      <span className="text-xs text-slate-500">% off</span>
                    </div>
                    <span className="ml-auto text-sm font-extrabold">
                      {money(l.qty * l.unit_price * (1 - (l.discount_pct || 0) / 100))}
                    </span>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>

      {/* Right: customer, totals, payment */}
      <div className="space-y-3 lg:col-span-2">
        <div className="glass p-4">
          <label className="mb-1 flex items-center gap-1.5 text-xs font-semibold text-slate-600 dark:text-slate-300">
            <User size={14} /> Customer (optional)
          </label>
          <select
            value={customerId}
            onChange={(e) => setCustomerId(e.target.value)}
            className="h-12 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
          >
            <option value="">Walk-in customer</option>
            {customers.map((c) => (
              <option key={c.id} value={c.id}>{c.name}{c.phone ? ` — ${c.phone}` : ''}</option>
            ))}
          </select>
        </div>

        <div className="glass space-y-3 p-4">
          <div className="grid grid-cols-2 gap-2">
            <label className="text-xs font-semibold">
              Bill discount (Rs)
              <input value={billDiscount} inputMode="decimal" onChange={(e) => setBillDiscount(e.target.value)}
                className="mt-1 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800" />
            </label>
            <label className="text-xs font-semibold">
              Tax %
              <input value={taxPct} inputMode="decimal" onChange={(e) => setTaxPct(e.target.value)}
                className="mt-1 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800" />
            </label>
          </div>

          <div>
            <p className="mb-1 text-xs font-semibold">Payment method</p>
            <div className="grid grid-cols-3 gap-2">
              {PAYMENT_METHODS.map((m) => (
                <button
                  key={m}
                  onClick={() => setPaymentMethod(m)}
                  className={`h-11 rounded-xl border text-xs font-bold capitalize ${
                    paymentMethod === m
                      ? 'border-brand-600 bg-brand-600 text-white'
                      : 'border-slate-200 text-slate-600 dark:border-slate-700 dark:text-slate-300'
                  }`}
                >
                  {m.replace('_', ' ')}
                </button>
              ))}
            </div>
          </div>

          {paymentMethod !== 'credit' && (
            <label className="block text-xs font-semibold">
              Amount paid (Rs)
              <input value={paid} inputMode="decimal" onChange={(e) => setPaid(e.target.value)}
                placeholder={money(total)}
                className="mt-1 h-12 w-full rounded-xl border border-slate-200 bg-white px-3 text-lg font-bold dark:border-slate-700 dark:bg-slate-800" />
            </label>
          )}

          <dl className="space-y-1 border-t border-slate-200 pt-3 text-sm dark:border-slate-700">
            <div className="flex justify-between"><dt className="text-slate-500">Subtotal</dt><dd className="font-semibold">{money(subtotal)}</dd></div>
            <div className="flex justify-between"><dt className="text-slate-500">Discount</dt><dd className="font-semibold text-emerald-600">−{money(discountAmt)}</dd></div>
            <div className="flex justify-between"><dt className="text-slate-500">Tax</dt><dd className="font-semibold">+{money(taxAmt)}</dd></div>
            <div className="flex justify-between text-lg font-extrabold"><dt>Total</dt><dd>{money(total)}</dd></div>
            {paymentMethod !== 'credit' && paid !== '' && (
              <div className={`flex justify-between font-bold ${balance < 0 ? 'text-red-500' : 'text-emerald-600'}`}>
                <dt>{balance < 0 ? 'Still due' : 'Change'}</dt><dd>{money(Math.abs(balance))}</dd></div>
            )}
          </dl>

          {!online && (
            <p className="flex items-center gap-2 rounded-xl bg-amber-100 px-3 py-2 text-xs font-semibold text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
              <CloudOff size={15} /> Offline — this sale will be queued and synced later.
            </p>
          )}

          <div className="flex gap-2">
            <button
              onClick={holdSale}
              disabled={cart.length === 0}
              className="flex h-12 flex-1 items-center justify-center gap-1.5 rounded-xl border border-slate-200 text-sm font-bold disabled:opacity-40 dark:border-slate-700"
            >
              <Pause size={16} /> Hold
            </button>
            <button
              onClick={() => setHeldOpen(true)}
              className="flex h-12 flex-1 items-center justify-center gap-1.5 rounded-xl border border-slate-200 text-sm font-bold dark:border-slate-700"
            >
              <Play size={16} /> Resume ({held.length})
            </button>
          </div>
          <button
            onClick={submitSale}
            disabled={cart.length === 0 || submitting}
            className="h-14 w-full rounded-xl bg-brand-600 text-base font-extrabold text-white hover:bg-brand-700 disabled:opacity-50"
          >
            {submitting ? 'Saving…' : `Complete sale • Rs ${money(total)}`}
          </button>
          {can('sales.view') && (
            <button onClick={() => navigate('/sales')} className="w-full text-center text-xs font-semibold text-brand-600">
              View sales history
            </button>
          )}
        </div>
      </div>

      {/* Batch picker */}
      <Modal open={!!batchPick} onClose={() => setBatchPick(null)} title="Select batch">
        <ul className="space-y-2">
          {(batchPick?.batches || []).map((b) => (
            <li key={b.id}>
              <button
                onClick={() => pushLine(batchPick.med, b)}
                className="flex w-full items-center justify-between rounded-xl border border-slate-200 px-3 py-3 text-left active:bg-brand-50 dark:border-slate-700 dark:active:bg-slate-800"
              >
                <span>
                  <span className="block text-sm font-semibold">{b.batch_no || b.batch_number || 'No number'}</span>
                  <span className="block text-xs text-slate-500">
                    Exp {b.expiry_date || '—'} • Stock {b.qty ?? b.stock ?? 0}
                  </span>
                </span>
                <span className="text-sm font-bold">{money(b.sale_price ?? batchPick.med.sale_price)}</span>
              </button>
            </li>
          ))}
        </ul>
      </Modal>

      {/* Held tickets */}
      <Modal open={heldOpen} onClose={() => setHeldOpen(false)} title="Held sales">
        {held.length === 0 ? (
          <EmptyState title="No held sales" hint="Use Hold to park the current sale and resume it later." />
        ) : (
          <ul className="space-y-2">
            {held.map((t) => (
              <li key={t.id} className="flex items-center justify-between rounded-xl border border-slate-200 px-3 py-2.5 dark:border-slate-700">
                <div>
                  <p className="text-sm font-semibold">{(t.cart || []).length} items</p>
                  <p className="text-xs text-slate-500">Held {new Date(t.heldAt).toLocaleString()}</p>
                </div>
                <div className="flex gap-2">
                  <button onClick={() => resumeHeld(t)} className="h-10 rounded-xl bg-brand-600 px-4 text-xs font-bold text-white">Resume</button>
                  <button onClick={() => deleteHeld(t.id)} className="grid h-10 w-10 place-items-center rounded-xl border border-slate-200 text-red-500 dark:border-slate-700" aria-label="Discard held sale">
                    <X size={16} />
                  </button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Modal>

      {/* Receipt / status */}
      <Modal open={!!receipt} onClose={() => setReceipt(null)} title="Sale complete">
        {receipt && (
          <div className="text-center">
            {receipt.status === 'confirmed' ? (
              <CheckCircle2 size={52} className="mx-auto text-emerald-500" />
            ) : (
              <CloudUpload size={52} className="mx-auto text-amber-500" />
            )}
            <h3 className="mt-2 text-lg font-extrabold">
              {receipt.status === 'confirmed' ? 'Server confirmed' : 'Queued offline'}
            </h3>
            <p className="mt-1 text-sm text-slate-500">
              Ref <span className="font-mono font-bold text-slate-800 dark:text-slate-200">{receipt.ref}</span>
            </p>
            <dl className="mx-auto mt-4 max-w-xs space-y-1 text-sm">
              <div className="flex justify-between"><dt>Total</dt><dd className="font-extrabold">Rs {money(receipt.total)}</dd></div>
              <div className="flex justify-between"><dt>Payment</dt><dd className="capitalize">{receipt.method.replace('_', ' ')}</dd></div>
              <div className="flex justify-between"><dt>Paid</dt><dd>Rs {money(receipt.paid)}</dd></div>
              {receipt.status === 'confirmed' && (
                <div className="flex justify-between"><dt>Change</dt><dd>Rs {money(receipt.change)}</dd></div>
              )}
            </dl>
            {receipt.status === 'queued' && (
              <p className="mt-3 rounded-xl bg-amber-100 px-3 py-2 text-xs text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                This sale is saved on this device and will sync automatically when you're back online.
              </p>
            )}
            <div className="mt-5 flex justify-center gap-2">
              <button onClick={() => window.print()} className="flex h-11 items-center gap-1.5 rounded-xl border border-slate-200 px-5 text-sm font-bold dark:border-slate-700">
                <Printer size={16} /> Print receipt
              </button>
              <button onClick={() => setReceipt(null)} className="h-11 rounded-xl bg-brand-600 px-6 text-sm font-bold text-white">
                New sale
              </button>
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
}
