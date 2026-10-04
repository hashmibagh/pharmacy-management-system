import React, { useCallback, useEffect, useState } from 'react';
import { Plus, Upload, Download, Copy, Pencil, Trash2, Search, History, Printer } from 'lucide-react';
import api from '../api/client';
import { ENDPOINTS } from '../api/endpoints';
import { useDebounce } from '../hooks/useDebounce';
import { usePermissions } from '../hooks/usePermissions';
import DataTable from '../components/DataTable';
import { Modal, ConfirmDialog } from '../components/Modal';
import EmptyState from '../components/EmptyState';
import LoadingSkeleton from '../components/LoadingSkeleton';

const emptyForm = {
  name: '', generic_name: '', brand: '', category_id: '', manufacturer_id: '',
  barcode: '', sku: '', unit: 'strip', pack_size: '', purchase_price: '',
  sale_price: '', mrp: '', min_stock: '10', max_stock: '', shelf: '',
  requires_prescription: false, description: '',
};

/** Stock badge helper shared by the medicines table. */
export function StockBadge({ stock, minStock }) {
  const s = Number(stock || 0);
  const min = Number(minStock || 0);
  if (s <= 0) return <span className="rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-bold text-red-700 dark:bg-red-900/40 dark:text-red-300">Out of stock</span>;
  if (s <= min) return <span className="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-bold text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">Low stock</span>;
  return <span className="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-bold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">{s}</span>;
}

export default function Medicines() {
  const { can } = usePermissions();
  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const [category, setCategory] = useState('');
  const [stockFilter, setStockFilter] = useState(''); // '' | low | out | expiring
  const debouncedQ = useDebounce(q, 400);

  const [categories, setCategories] = useState([]);
  const [manufacturers, setManufacturers] = useState([]);

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [formErrors, setFormErrors] = useState({});
  const [saving, setSaving] = useState(false);

  const [deleting, setDeleting] = useState(null);
  const [historyOf, setHistoryOf] = useState(null);
  const [history, setHistory] = useState([]);
  const [importing, setImporting] = useState(false);
  const [toast, setToast] = useState('');

  const fetchRows = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get(ENDPOINTS.medicines.list, {
        params: {
          page,
          q: debouncedQ || undefined,
          category_id: category || undefined,
          stock: stockFilter || undefined,
          per_page: 15,
        },
      });
      const d = res.unwrapped?.data;
      setRows(d?.data || d || []);
      setMeta(d?.meta || null);
    } catch (e) {
      setRows([]);
    } finally {
      setLoading(false);
    }
  }, [page, debouncedQ, category, stockFilter]);

  useEffect(() => {
    fetchRows();
  }, [fetchRows]);

  useEffect(() => {
    setPage(1);
  }, [debouncedQ, category, stockFilter]);

  // Lookups
  useEffect(() => {
    (async () => {
      try {
        const [c, m] = await Promise.all([
          api.get(ENDPOINTS.categories.list, { params: { per_page: 200 } }),
          api.get(ENDPOINTS.manufacturers.list, { params: { per_page: 200 } }),
        ]);
        const cd = c.unwrapped?.data;
        const md = m.unwrapped?.data;
        setCategories(cd?.data || cd || []);
        setManufacturers(md?.data || md || []);
      } catch (e) {
        /* optional */
      }
    })();
  }, []);

  const showToast = (msg) => {
    setToast(msg);
    setTimeout(() => setToast(''), 3000);
  };

  const openCreate = () => {
    setEditing(null);
    setForm(emptyForm);
    setFormErrors({});
    setFormOpen(true);
  };

  const openEdit = (row) => {
    setEditing(row);
    setForm({
      ...emptyForm,
      name: row.name || '', generic_name: row.generic_name || '', brand: row.brand || '',
      category_id: row.category_id || '', manufacturer_id: row.manufacturer_id || '',
      barcode: row.barcode || '', sku: row.sku || '', unit: row.unit || 'strip',
      pack_size: row.pack_size || '', purchase_price: row.purchase_price ?? '',
      sale_price: row.sale_price ?? '', mrp: row.mrp ?? '', min_stock: row.min_stock ?? '10',
      max_stock: row.max_stock ?? '', shelf: row.shelf || '',
      requires_prescription: !!row.requires_prescription, description: row.description || '',
    });
    setFormErrors({});
    setFormOpen(true);
  };

  const validate = () => {
    const errs = {};
    if (!form.name.trim()) errs.name = 'Name is required.';
    if (form.sale_price === '' || Number(form.sale_price) < 0) errs.sale_price = 'Valid sale price required.';
    if (form.purchase_price !== '' && Number(form.purchase_price) < 0) errs.purchase_price = 'Cannot be negative.';
    setFormErrors(errs);
    return Object.keys(errs).length === 0;
  };

  const save = async (e) => {
    e.preventDefault();
    if (!validate()) return;
    setSaving(true);
    try {
      const payload = {
        ...form,
        category_id: form.category_id || null,
        manufacturer_id: form.manufacturer_id || null,
        pack_size: form.pack_size || null,
        purchase_price: form.purchase_price === '' ? null : Number(form.purchase_price),
        sale_price: Number(form.sale_price),
        mrp: form.mrp === '' ? null : Number(form.mrp),
        min_stock: Number(form.min_stock) || 0,
        max_stock: form.max_stock === '' ? null : Number(form.max_stock),
      };
      if (editing) {
        await api.put(ENDPOINTS.medicines.detail(editing.id), payload);
        showToast('Medicine updated.');
      } else {
        await api.post(ENDPOINTS.medicines.create, payload);
        showToast('Medicine created.');
      }
      setFormOpen(false);
      fetchRows();
    } catch (err) {
      setFormErrors(err.errors || {});
      showToast(err.message || 'Save failed.');
    } finally {
      setSaving(false);
    }
  };

  const doDelete = async () => {
    try {
      await api.delete(ENDPOINTS.medicines.detail(deleting.id));
      showToast('Medicine deleted.');
      setDeleting(null);
      fetchRows();
    } catch (e) {
      showToast(e.message || 'Delete failed.');
    }
  };

  const doDuplicate = async (row) => {
    try {
      await api.post(ENDPOINTS.medicines.duplicate(row.id));
      showToast('Medicine duplicated.');
      fetchRows();
    } catch (e) {
      showToast(e.message || 'Duplicate failed.');
    }
  };

  const openHistory = async (row) => {
    setHistoryOf(row);
    setHistory([]);
    try {
      const res = await api.get(`${ENDPOINTS.medicines.detail(row.id)}/history`);
      setHistory(res.unwrapped?.data || []);
    } catch (e) {
      setHistory([]);
    }
  };

  const doExport = async () => {
    try {
      const res = await api.get(ENDPOINTS.medicines.export, { responseType: 'blob' });
      const url = window.URL.createObjectURL(new Blob([res.data]));
      const a = document.createElement('a');
      a.href = url;
      a.download = 'medicines.csv';
      a.click();
      window.URL.revokeObjectURL(url);
    } catch (e) {
      showToast(e.message || 'Export failed.');
    }
  };

  const doImport = async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setImporting(true);
    try {
      const fd = new FormData();
      fd.append('file', file);
      const res = await api.post(ENDPOINTS.medicines.import, fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      showToast(res.unwrapped?.message || 'Import completed.');
      fetchRows();
    } catch (err) {
      showToast(err.message || 'Import failed.');
    } finally {
      setImporting(false);
      e.target.value = '';
    }
  };

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }));

  const columns = [
    { key: 'name', label: 'Medicine', sortable: true, render: (r) => (
      <div>
        <p className="font-semibold">{r.name}</p>
        <p className="text-xs text-slate-500">{r.generic_name || r.brand || ''}</p>
      </div>
    )},
    { key: 'category', label: 'Category', render: (r) => r.category?.name || '—' },
    { key: 'sale_price', label: 'Sale price', sortable: true, render: (r) => Number(r.sale_price ?? 0).toLocaleString() },
    { key: 'stock', label: 'Stock', sortable: true, render: (r) => <StockBadge stock={r.stock} minStock={r.min_stock} /> },
    { key: 'barcode', label: 'Barcode', render: (r) => <span className="font-mono text-xs">{r.barcode || '—'}</span> },
    { key: 'actions', label: '', className: 'text-right whitespace-nowrap', render: (r) => (
      <div className="flex justify-end gap-1">
        {can('medicines.create') && (
          <button onClick={() => doDuplicate(r)} className="rounded-lg p-2 hover:bg-slate-100 dark:hover:bg-slate-800" title="Duplicate">
            <Copy size={16} />
          </button>
        )}
        <button onClick={() => openHistory(r)} className="rounded-lg p-2 hover:bg-slate-100 dark:hover:bg-slate-800" title="History">
          <History size={16} />
        </button>
        {can('medicines.edit') && (
          <button onClick={() => openEdit(r)} className="rounded-lg p-2 hover:bg-slate-100 dark:hover:bg-slate-800" title="Edit">
            <Pencil size={16} />
          </button>
        )}
        {can('medicines.delete') && (
          <button onClick={() => setDeleting(r)} className="rounded-lg p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-950/40" title="Delete">
            <Trash2 size={16} />
          </button>
        )}
      </div>
    )},
  ];

  if (loading && rows.length === 0) return <LoadingSkeleton rows={6} />;

  return (
    <div className="space-y-3">
      <div className="no-print flex flex-wrap items-center gap-2">
        <h1 className="mr-auto text-xl font-extrabold">Medicines</h1>
        {can('medicines.create') && (
          <>
            <label className="flex h-10 cursor-pointer items-center gap-1.5 rounded-xl border border-slate-200 px-3 text-xs font-bold dark:border-slate-700">
              <Upload size={15} /> {importing ? 'Importing…' : 'Import'}
              <input type="file" accept=".csv,.xlsx" className="hidden" onChange={doImport} disabled={importing} />
            </label>
            <button onClick={doExport} className="flex h-10 items-center gap-1.5 rounded-xl border border-slate-200 px-3 text-xs font-bold dark:border-slate-700">
              <Download size={15} /> Export
            </button>
            <button onClick={openCreate} className="flex h-10 items-center gap-1.5 rounded-xl bg-brand-600 px-4 text-xs font-bold text-white">
              <Plus size={15} /> Add medicine
            </button>
          </>
        )}
      </div>

      {/* Filters */}
      <div className="no-print glass flex flex-wrap gap-2 p-3">
        <div className="relative min-w-[200px] flex-1">
          <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search name, generic, barcode…"
            className="h-10 w-full rounded-xl border border-slate-200 bg-white pl-9 pr-3 text-sm dark:border-slate-700 dark:bg-slate-800" />
        </div>
        <select value={category} onChange={(e) => setCategory(e.target.value)}
          className="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800">
          <option value="">All categories</option>
          {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
        <select value={stockFilter} onChange={(e) => setStockFilter(e.target.value)}
          className="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800">
          <option value="">All stock</option>
          <option value="low">Low stock</option>
          <option value="out">Out of stock</option>
          <option value="expiring">Expiring soon</option>
        </select>
      </div>

      <div className="glass p-3">
        <DataTable columns={columns} data={rows} meta={meta} loading={loading} onPage={setPage}
          emptyText="No medicines found. Try adjusting the filters." />
      </div>

      {/* Create / edit */}
      <Modal open={formOpen} onClose={() => setFormOpen(false)} title={editing ? 'Edit medicine' : 'Add medicine'} wide>
        <form onSubmit={save} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label="Name *" error={formErrors.name}>
            <input value={form.name} onChange={set('name')} className={inp} />
          </Field>
          <Field label="Generic name">
            <input value={form.generic_name} onChange={set('generic_name')} className={inp} />
          </Field>
          <Field label="Brand">
            <input value={form.brand} onChange={set('brand')} className={inp} />
          </Field>
          <Field label="Barcode">
            <input value={form.barcode} onChange={set('barcode')} className={inp} />
          </Field>
          <Field label="Category">
            <select value={form.category_id} onChange={set('category_id')} className={inp}>
              <option value="">—</option>
              {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </Field>
          <Field label="Manufacturer">
            <select value={form.manufacturer_id} onChange={set('manufacturer_id')} className={inp}>
              <option value="">—</option>
              {manufacturers.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
            </select>
          </Field>
          <Field label="Unit">
            <select value={form.unit} onChange={set('unit')} className={inp}>
              <option value="strip">Strip</option><option value="box">Box</option>
              <option value="bottle">Bottle</option><option value="vial">Vial</option>
              <option value="tube">Tube</option><option value="piece">Piece</option>
            </select>
          </Field>
          <Field label="Pack size">
            <input value={form.pack_size} onChange={set('pack_size')} placeholder="e.g. 20 tablets" className={inp} />
          </Field>
          <Field label="Purchase price" error={formErrors.purchase_price}>
            <input value={form.purchase_price} onChange={set('purchase_price')} inputMode="decimal" className={inp} />
          </Field>
          <Field label="Sale price *" error={formErrors.sale_price}>
            <input value={form.sale_price} onChange={set('sale_price')} inputMode="decimal" className={inp} />
          </Field>
          <Field label="MRP">
            <input value={form.mrp} onChange={set('mrp')} inputMode="decimal" className={inp} />
          </Field>
          <Field label="Min stock">
            <input value={form.min_stock} onChange={set('min_stock')} inputMode="numeric" className={inp} />
          </Field>
          <Field label="Shelf location">
            <input value={form.shelf} onChange={set('shelf')} placeholder="e.g. A-12" className={inp} />
          </Field>
          <Field label="Requires prescription">
            <label className="flex h-11 items-center gap-2 text-sm">
              <input type="checkbox" checked={form.requires_prescription} onChange={set('requires_prescription')} className="h-5 w-5 accent-brand-600" />
              Yes
            </label>
          </Field>
          <div className="sm:col-span-2">
            <Field label="Description">
              <textarea value={form.description} onChange={set('description')} rows={2} className={inp} />
            </Field>
          </div>
          <div className="flex justify-end gap-2 sm:col-span-2">
            <button type="button" onClick={() => setFormOpen(false)} className="h-11 rounded-xl border border-slate-200 px-5 text-sm font-bold dark:border-slate-700">Cancel</button>
            <button type="submit" disabled={saving} className="h-11 rounded-xl bg-brand-600 px-6 text-sm font-bold text-white disabled:opacity-50">
              {saving ? 'Saving…' : editing ? 'Update' : 'Create'}
            </button>
          </div>
        </form>
      </Modal>

      {/* History drawer */}
      <Modal open={!!historyOf} onClose={() => setHistoryOf(null)} title={`History — ${historyOf?.name || ''}`}>
        {history.length === 0 ? (
          <EmptyState title="No history" hint="Stock movements for this medicine will appear here." />
        ) : (
          <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
            {history.map((h, i) => (
              <li key={i} className="py-2">
                <p className="font-medium">{h.type || h.action}</p>
                <p className="text-xs text-slate-500">{h.created_at} {h.note ? `• ${h.note}` : ''} {h.qty ? `• Qty ${h.qty}` : ''}</p>
              </li>
            ))}
          </ul>
        )}
      </Modal>

      <ConfirmDialog
        open={!!deleting}
        onClose={() => setDeleting(null)}
        onConfirm={doDelete}
        title="Delete medicine"
        message={`Delete “${deleting?.name}”? This cannot be undone.`}
      />

      {toast && (
        <div className="no-print fixed bottom-16 left-1/2 z-[70] -translate-x-1/2 rounded-full bg-slate-900 px-4 py-2 text-xs font-semibold text-white shadow-lg">
          {toast}
        </div>
      )}
    </div>
  );
}

const inp = 'h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-brand-500 dark:border-slate-700 dark:bg-slate-800';

function Field({ label, error, children }) {
  return (
    <label className="block text-xs font-semibold text-slate-600 dark:text-slate-300">
      {label}
      <div className="mt-1">{children}</div>
      {error && <p className="mt-1 font-normal text-red-500">{error}</p>}
    </label>
  );
}
