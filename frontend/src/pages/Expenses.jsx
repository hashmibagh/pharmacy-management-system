import React, { useCallback, useEffect, useState } from 'react';
import { Plus, Pencil, Trash2, Settings2 } from 'lucide-react';
import api from '../api/client';
import { ENDPOINTS } from '../api/endpoints';
import { usePermissions } from '../hooks/usePermissions';
import { useDebounce } from '../hooks/useDebounce';
import DataTable from '../components/DataTable';
import { Modal, ConfirmDialog } from '../components/Modal';
import LoadingSkeleton from '../components/LoadingSkeleton';

const emptyForm = { title: '', expense_category_id: '', amount: '', date: '', payment_method: 'cash', note: '' };

export default function Expenses() {
  const { can } = usePermissions();
  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const debouncedQ = useDebounce(q, 400);
  const [categories, setCategories] = useState([]);
  const [catFilter, setCatFilter] = useState('');

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(null);
  const [catOpen, setCatOpen] = useState(false);
  const [newCat, setNewCat] = useState('');
  const [toast, setToast] = useState('');

  const showToast = (m) => {
    setToast(m);
    setTimeout(() => setToast(''), 3000);
  };

  const fetchCats = useCallback(async () => {
    try {
      const res = await api.get(ENDPOINTS.expenseCategories.list, { params: { per_page: 200 } });
      const d = res.unwrapped?.data;
      setCategories(d?.data || d || []);
    } catch (e) {
      /* optional */
    }
  }, []);

  const fetchRows = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get(ENDPOINTS.expenses.list, {
        params: { page, q: debouncedQ || undefined, expense_category_id: catFilter || undefined, per_page: 15 },
      });
      const d = res.unwrapped?.data;
      setRows(d?.data || d || []);
      setMeta(d?.meta || null);
    } catch (e) {
      setRows([]);
    } finally {
      setLoading(false);
    }
  }, [page, debouncedQ, catFilter]);

  useEffect(() => {
    fetchCats();
  }, [fetchCats]);
  useEffect(() => {
    fetchRows();
  }, [fetchRows]);
  useEffect(() => {
    setPage(1);
  }, [debouncedQ, catFilter]);

  const openCreate = () => {
    setEditing(null);
    setForm({ ...emptyForm, date: new Date().toISOString().slice(0, 10) });
    setErrors({});
    setFormOpen(true);
  };
  const openEdit = (r) => {
    setEditing(r);
    setForm({
      title: r.title || '', expense_category_id: r.expense_category_id || '',
      amount: r.amount ?? '', date: String(r.date || r.created_at || '').slice(0, 10),
      payment_method: r.payment_method || 'cash', note: r.note || '',
    });
    setErrors({});
    setFormOpen(true);
  };

  const save = async (e) => {
    e.preventDefault();
    const errs = {};
    if (!form.title.trim()) errs.title = 'Title is required.';
    if (!form.amount || Number(form.amount) <= 0) errs.amount = 'Enter a valid amount.';
    setErrors(errs);
    if (Object.keys(errs).length) return;
    setSaving(true);
    try {
      const payload = {
        ...form,
        expense_category_id: form.expense_category_id || null,
        amount: Number(form.amount),
      };
      if (editing) await api.put(ENDPOINTS.expenses.detail(editing.id), payload);
      else await api.post(ENDPOINTS.expenses.create, payload);
      showToast(editing ? 'Expense updated.' : 'Expense recorded.');
      setFormOpen(false);
      fetchRows();
    } catch (err) {
      setErrors(err.errors || {});
      showToast(err.message || 'Save failed.');
    } finally {
      setSaving(false);
    }
  };

  const doDelete = async () => {
    try {
      await api.delete(ENDPOINTS.expenses.detail(deleting.id));
      showToast('Expense deleted.');
      setDeleting(null);
      fetchRows();
    } catch (e) {
      showToast(e.message || 'Delete failed.');
    }
  };

  const addCategory = async (e) => {
    e.preventDefault();
    if (!newCat.trim()) return;
    try {
      await api.post(ENDPOINTS.expenseCategories.create, { name: newCat.trim() });
      setNewCat('');
      fetchCats();
      showToast('Category added.');
    } catch (err) {
      showToast(err.message || 'Could not add category.');
    }
  };

  const deleteCategory = async (id) => {
    try {
      await api.delete(ENDPOINTS.expenseCategories.detail(id));
      fetchCats();
    } catch (e) {
      showToast(e.message || 'Could not delete category.');
    }
  };

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
  const inp = 'h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800';

  const columns = [
    { key: 'title', label: 'Expense', sortable: true, render: (r) => (
      <div><p className="font-semibold">{r.title}</p><p className="text-xs text-slate-500">{r.note || ''}</p></div>
    )},
    { key: 'category', label: 'Category', render: (r) => r.category?.name || '—' },
    { key: 'date', label: 'Date', sortable: true, render: (r) => String(r.date || r.created_at || '').slice(0, 10) },
    { key: 'method', label: 'Method', render: (r) => <span className="capitalize">{String(r.payment_method || 'cash').replace('_', ' ')}</span> },
    { key: 'amount', label: 'Amount', sortable: true, render: (r) => <span className="font-bold text-red-600">{Number(r.amount || 0).toLocaleString()}</span>, className: 'text-right' },
    { key: 'actions', label: '', className: 'text-right whitespace-nowrap', render: (r) => (
      <div className="flex justify-end gap-1">
        {can('expenses.edit') && <button onClick={() => openEdit(r)} className="rounded-lg p-2 hover:bg-slate-100 dark:hover:bg-slate-800" title="Edit"><Pencil size={16} /></button>}
        {can('expenses.delete') && <button onClick={() => setDeleting(r)} className="rounded-lg p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-950/40" title="Delete"><Trash2 size={16} /></button>}
      </div>
    )},
  ];

  const total = rows.reduce((s, r) => s + Number(r.amount || 0), 0);

  if (loading && rows.length === 0) return <LoadingSkeleton rows={6} />;

  return (
    <div className="space-y-3">
      <div className="no-print flex flex-wrap items-center gap-2">
        <h1 className="mr-auto text-xl font-extrabold">Expenses</h1>
        <span className="text-sm font-bold text-red-600">Page total: {total.toLocaleString()}</span>
        <button onClick={() => setCatOpen(true)} className="flex h-10 items-center gap-1.5 rounded-xl border border-slate-200 px-3 text-xs font-bold dark:border-slate-700">
          <Settings2 size={15} /> Categories
        </button>
        {can('expenses.create') && (
          <button onClick={openCreate} className="flex h-10 items-center gap-1.5 rounded-xl bg-brand-600 px-4 text-xs font-bold text-white">
            <Plus size={15} /> Add expense
          </button>
        )}
      </div>

      <div className="no-print glass flex flex-wrap gap-2 p-3">
        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search expenses…"
          className="h-10 min-w-[200px] flex-1 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800" />
        <select value={catFilter} onChange={(e) => setCatFilter(e.target.value)}
          className="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800">
          <option value="">All categories</option>
          {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
      </div>

      <div className="glass p-3">
        <DataTable columns={columns} data={rows} meta={meta} loading={loading} onPage={setPage} emptyText="No expenses recorded." />
      </div>

      <Modal open={formOpen} onClose={() => setFormOpen(false)} title={editing ? 'Edit expense' : 'Add expense'}>
        <form onSubmit={save} className="space-y-3">
          <label className="block text-xs font-semibold">Title *
            <input value={form.title} onChange={set('title')} className={`${inp} mt-1`} />
            {errors.title && <p className="mt-1 text-red-500">{errors.title}</p>}
          </label>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label className="block text-xs font-semibold">Category
              <select value={form.expense_category_id} onChange={set('expense_category_id')} className={`${inp} mt-1`}>
                <option value="">—</option>
                {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </label>
            <label className="block text-xs font-semibold">Amount *
              <input value={form.amount} onChange={set('amount')} inputMode="decimal" className={`${inp} mt-1`} />
              {errors.amount && <p className="mt-1 text-red-500">{errors.amount}</p>}
            </label>
            <label className="block text-xs font-semibold">Date
              <input type="date" value={form.date} onChange={set('date')} className={`${inp} mt-1`} />
            </label>
            <label className="block text-xs font-semibold">Payment method
              <select value={form.payment_method} onChange={set('payment_method')} className={`${inp} mt-1`}>
                <option value="cash">Cash</option><option value="bank">Bank</option>
                <option value="card">Card</option><option value="mobile_wallet">Mobile wallet</option>
              </select>
            </label>
          </div>
          <label className="block text-xs font-semibold">Note
            <textarea value={form.note} onChange={set('note')} rows={2} className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
          </label>
          <div className="flex justify-end gap-2">
            <button type="button" onClick={() => setFormOpen(false)} className="h-11 rounded-xl border border-slate-200 px-5 text-sm font-bold dark:border-slate-700">Cancel</button>
            <button type="submit" disabled={saving} className="h-11 rounded-xl bg-brand-600 px-6 text-sm font-bold text-white disabled:opacity-50">
              {saving ? 'Saving…' : editing ? 'Update' : 'Save'}
            </button>
          </div>
        </form>
      </Modal>

      <Modal open={catOpen} onClose={() => setCatOpen(false)} title="Expense categories">
        <form onSubmit={addCategory} className="flex gap-2">
          <input value={newCat} onChange={(e) => setNewCat(e.target.value)} placeholder="New category name"
            className="h-11 flex-1 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800" />
          <button type="submit" className="h-11 rounded-xl bg-brand-600 px-4 text-sm font-bold text-white">Add</button>
        </form>
        <ul className="mt-3 divide-y divide-slate-100 dark:divide-slate-800">
          {categories.map((c) => (
            <li key={c.id} className="flex items-center justify-between py-2 text-sm">
              <span className="font-medium">{c.name}</span>
              <button onClick={() => deleteCategory(c.id)} className="rounded-lg p-1.5 text-red-500" aria-label={`Delete ${c.name}`}>
                <Trash2 size={15} />
              </button>
            </li>
          ))}
          {categories.length === 0 && <li className="py-3 text-center text-xs text-slate-500">No categories yet.</li>}
        </ul>
      </Modal>

      <ConfirmDialog open={!!deleting} onClose={() => setDeleting(null)} onConfirm={doDelete}
        title="Delete expense" message={`Delete “${deleting?.title}”?`} />

      {toast && (
        <div className="no-print fixed bottom-16 left-1/2 z-[70] -translate-x-1/2 rounded-full bg-slate-900 px-4 py-2 text-xs font-semibold text-white shadow-lg">{toast}</div>
      )}
    </div>
  );
}
