import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Plus, Pencil, Trash2 } from 'lucide-react';
import api from '../api/client';
import { ENDPOINTS } from '../api/endpoints';
import { usePermissions } from '../hooks/usePermissions';
import { useDebounce } from '../hooks/useDebounce';
import DataTable from '../components/DataTable';
import { Modal, ConfirmDialog } from '../components/Modal';
import LoadingSkeleton from '../components/LoadingSkeleton';

const emptyForm = { name: '', phone: '', email: '', address: '', credit_limit: '', notes: '' };

export default function Customers() {
  const { can } = usePermissions();
  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const debouncedQ = useDebounce(q, 400);

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(null);
  const [toast, setToast] = useState('');

  const showToast = (m) => {
    setToast(m);
    setTimeout(() => setToast(''), 3000);
  };

  const fetchRows = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get(ENDPOINTS.customers.list, {
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

  const openCreate = () => {
    setEditing(null); setForm(emptyForm); setErrors({}); setFormOpen(true);
  };
  const openEdit = (r) => {
    setEditing(r);
    setForm({ ...emptyForm, name: r.name || '', phone: r.phone || '', email: r.email || '', address: r.address || '', credit_limit: r.credit_limit ?? '', notes: r.notes || '' });
    setErrors({}); setFormOpen(true);
  };

  const save = async (e) => {
    e.preventDefault();
    const errs = {};
    if (!form.name.trim()) errs.name = 'Name is required.';
    setErrors(errs);
    if (Object.keys(errs).length) return;
    setSaving(true);
    try {
      const payload = { ...form, credit_limit: form.credit_limit === '' ? null : Number(form.credit_limit) };
      if (editing) await api.put(ENDPOINTS.customers.detail(editing.id), payload);
      else await api.post(ENDPOINTS.customers.create, payload);
      showToast(editing ? 'Customer updated.' : 'Customer created.');
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
      await api.delete(ENDPOINTS.customers.detail(deleting.id));
      showToast('Customer deleted.');
      setDeleting(null);
      fetchRows();
    } catch (e) {
      showToast(e.message || 'Delete failed.');
    }
  };

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
  const inp = 'h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800';

  const columns = [
    { key: 'name', label: 'Customer', sortable: true, render: (r) => (
      <Link to={`/customers/${r.id}`} className="font-semibold text-brand-600">{r.name}</Link>
    )},
    { key: 'phone', label: 'Phone', render: (r) => r.phone || '—' },
    { key: 'due', label: 'Due', sortable: true, render: (r) => (
      <span className={`font-bold ${Number(r.due || 0) > 0 ? 'text-amber-600' : ''}`}>
        {Number(r.due || r.balance || 0).toLocaleString()}
      </span>
    ), className: 'text-right' },
    { key: 'actions', label: '', className: 'text-right whitespace-nowrap', render: (r) => (
      <div className="flex justify-end gap-1">
        {can('customers.edit') && <button onClick={() => openEdit(r)} className="rounded-lg p-2 hover:bg-slate-100 dark:hover:bg-slate-800" title="Edit"><Pencil size={16} /></button>}
        {can('customers.delete') && <button onClick={() => setDeleting(r)} className="rounded-lg p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-950/40" title="Delete"><Trash2 size={16} /></button>}
      </div>
    )},
  ];

  if (loading && rows.length === 0) return <LoadingSkeleton rows={6} />;

  return (
    <div className="space-y-3">
      <div className="no-print flex items-center gap-2">
        <h1 className="mr-auto text-xl font-extrabold">Customers</h1>
        {can('customers.create') && (
          <button onClick={openCreate} className="flex h-10 items-center gap-1.5 rounded-xl bg-brand-600 px-4 text-xs font-bold text-white">
            <Plus size={15} /> Add customer
          </button>
        )}
      </div>
      <div className="no-print glass p-3">
        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search name / phone…"
          className="h-10 w-full max-w-sm rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800" />
      </div>
      <div className="glass p-3">
        <DataTable columns={columns} data={rows} meta={meta} loading={loading} onPage={setPage} emptyText="No customers yet." />
      </div>

      <Modal open={formOpen} onClose={() => setFormOpen(false)} title={editing ? 'Edit customer' : 'Add customer'}>
        <form onSubmit={save} className="space-y-3">
          <label className="block text-xs font-semibold">Name *
            <input value={form.name} onChange={set('name')} className={`${inp} mt-1`} />
            {errors.name && <p className="mt-1 text-red-500">{errors.name}</p>}
          </label>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label className="block text-xs font-semibold">Phone
              <input value={form.phone} onChange={set('phone')} inputMode="tel" className={`${inp} mt-1`} />
            </label>
            <label className="block text-xs font-semibold">Email
              <input value={form.email} onChange={set('email')} type="email" className={`${inp} mt-1`} />
            </label>
          </div>
          <label className="block text-xs font-semibold">Address
            <input value={form.address} onChange={set('address')} className={`${inp} mt-1`} />
          </label>
          <label className="block text-xs font-semibold">Credit limit
            <input value={form.credit_limit} onChange={set('credit_limit')} inputMode="decimal" className={`${inp} mt-1`} />
          </label>
          <label className="block text-xs font-semibold">Notes
            <textarea value={form.notes} onChange={set('notes')} rows={2} className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
          </label>
          <div className="flex justify-end gap-2">
            <button type="button" onClick={() => setFormOpen(false)} className="h-11 rounded-xl border border-slate-200 px-5 text-sm font-bold dark:border-slate-700">Cancel</button>
            <button type="submit" disabled={saving} className="h-11 rounded-xl bg-brand-600 px-6 text-sm font-bold text-white disabled:opacity-50">
              {saving ? 'Saving…' : editing ? 'Update' : 'Create'}
            </button>
          </div>
        </form>
      </Modal>

      <ConfirmDialog open={!!deleting} onClose={() => setDeleting(null)} onConfirm={doDelete}
        title="Delete customer" message={`Delete “${deleting?.name}”? Ledger history will be kept by the backend policy.`} />

      {toast && (
        <div className="no-print fixed bottom-16 left-1/2 z-[70] -translate-x-1/2 rounded-full bg-slate-900 px-4 py-2 text-xs font-semibold text-white shadow-lg">{toast}</div>
      )}
    </div>
  );
}
