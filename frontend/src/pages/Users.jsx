import React, { useCallback, useEffect, useState } from 'react';
import { Plus, Pencil, Trash2 } from 'lucide-react';
import api from '../api/client';
import { ENDPOINTS } from '../api/endpoints';
import { usePermissions } from '../hooks/usePermissions';
import { useDebounce } from '../hooks/useDebounce';
import DataTable from '../components/DataTable';
import { Modal, ConfirmDialog } from '../components/Modal';
import LoadingSkeleton from '../components/LoadingSkeleton';

const emptyForm = { name: '', email: '', phone: '', role_id: '', password: '', password_confirmation: '', status: 'active' };

export default function Users() {
  const { can } = usePermissions();
  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const debouncedQ = useDebounce(q, 400);
  const [roles, setRoles] = useState([]);

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
      const res = await api.get(ENDPOINTS.users.list, {
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
  useEffect(() => {
    (async () => {
      try {
        const res = await api.get(ENDPOINTS.roles.list, { params: { per_page: 100 } });
        const d = res.unwrapped?.data;
        setRoles(d?.data || d || []);
      } catch (e) {
        /* optional */
      }
    })();
  }, []);

  const openCreate = () => {
    setEditing(null); setForm(emptyForm); setErrors({}); setFormOpen(true);
  };
  const openEdit = (r) => {
    setEditing(r);
    setForm({ ...emptyForm, name: r.name || '', email: r.email || '', phone: r.phone || '', role_id: r.role_id || r.role?.id || '', status: r.status || 'active' });
    setErrors({}); setFormOpen(true);
  };

  const save = async (e) => {
    e.preventDefault();
    const errs = {};
    if (!form.name.trim()) errs.name = 'Name is required.';
    if (!form.email.includes('@')) errs.email = 'Valid email required.';
    if (!editing && !form.password) errs.password = 'Password is required for new users.';
    if (form.password && form.password !== form.password_confirmation) errs.password_confirmation = 'Passwords do not match.';
    setErrors(errs);
    if (Object.keys(errs).length) return;
    setSaving(true);
    try {
      const payload = { ...form, role_id: form.role_id || null };
      if (!payload.password) {
        delete payload.password;
        delete payload.password_confirmation;
      }
      if (editing) await api.put(ENDPOINTS.users.detail(editing.id), payload);
      else await api.post(ENDPOINTS.users.create, payload);
      showToast(editing ? 'User updated.' : 'User created.');
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
      await api.delete(ENDPOINTS.users.detail(deleting.id));
      showToast('User deleted.');
      setDeleting(null);
      fetchRows();
    } catch (e) {
      showToast(e.message || 'Delete failed.');
    }
  };

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
  const inp = 'h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800';

  const columns = [
    { key: 'name', label: 'User', sortable: true, render: (r) => (
      <div><p className="font-semibold">{r.name}</p><p className="text-xs text-slate-500">{r.email}</p></div>
    )},
    { key: 'role', label: 'Role', render: (r) => <span className="rounded-full bg-brand-100 px-2.5 py-0.5 text-xs font-bold capitalize text-brand-700 dark:bg-brand-900/40 dark:text-brand-300">{r.role?.name || r.role || '—'}</span> },
    { key: 'status', label: 'Status', render: (r) => (
      <span className={`rounded-full px-2.5 py-0.5 text-xs font-bold capitalize ${r.status === 'active' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'}`}>
        {r.status || '—'}
      </span>
    )},
    { key: 'actions', label: '', className: 'text-right whitespace-nowrap', render: (r) => (
      <div className="flex justify-end gap-1">
        {can('users.edit') && <button onClick={() => openEdit(r)} className="rounded-lg p-2 hover:bg-slate-100 dark:hover:bg-slate-800" title="Edit"><Pencil size={16} /></button>}
        {can('users.delete') && <button onClick={() => setDeleting(r)} className="rounded-lg p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-950/40" title="Delete"><Trash2 size={16} /></button>}
      </div>
    )},
  ];

  if (loading && rows.length === 0) return <LoadingSkeleton rows={6} />;

  return (
    <div className="space-y-3">
      <div className="no-print flex items-center gap-2">
        <h1 className="mr-auto text-xl font-extrabold">Users</h1>
        {can('users.create') && (
          <button onClick={openCreate} className="flex h-10 items-center gap-1.5 rounded-xl bg-brand-600 px-4 text-xs font-bold text-white">
            <Plus size={15} /> Add user
          </button>
        )}
      </div>
      <div className="no-print glass p-3">
        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search name / email…"
          className="h-10 w-full max-w-sm rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800" />
      </div>
      <div className="glass p-3">
        <DataTable columns={columns} data={rows} meta={meta} loading={loading} onPage={setPage} emptyText="No users yet." />
      </div>

      <Modal open={formOpen} onClose={() => setFormOpen(false)} title={editing ? 'Edit user' : 'Add user'}>
        <form onSubmit={save} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <label className="block text-xs font-semibold">Name *
            <input value={form.name} onChange={set('name')} className={`${inp} mt-1`} />
            {errors.name && <p className="mt-1 text-red-500">{errors.name}</p>}
          </label>
          <label className="block text-xs font-semibold">Email *
            <input value={form.email} onChange={set('email')} type="email" className={`${inp} mt-1`} />
            {errors.email && <p className="mt-1 text-red-500">{errors.email}</p>}
          </label>
          <label className="block text-xs font-semibold">Phone
            <input value={form.phone} onChange={set('phone')} inputMode="tel" className={`${inp} mt-1`} />
          </label>
          <label className="block text-xs font-semibold">Role
            <select value={form.role_id} onChange={set('role_id')} className={`${inp} mt-1`}>
              <option value="">— Select —</option>
              {roles.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
            </select>
          </label>
          <label className="block text-xs font-semibold">{editing ? 'New password (leave blank to keep)' : 'Password *'}
            <input value={form.password} onChange={set('password')} type="password" autoComplete="new-password" className={`${inp} mt-1`} />
            {errors.password && <p className="mt-1 text-red-500">{errors.password}</p>}
          </label>
          <label className="block text-xs font-semibold">Confirm password
            <input value={form.password_confirmation} onChange={set('password_confirmation')} type="password" autoComplete="new-password" className={`${inp} mt-1`} />
            {errors.password_confirmation && <p className="mt-1 text-red-500">{errors.password_confirmation}</p>}
          </label>
          <label className="block text-xs font-semibold">Status
            <select value={form.status} onChange={set('status')} className={`${inp} mt-1`}>
              <option value="active">Active</option><option value="inactive">Inactive</option>
            </select>
          </label>
          <div className="flex justify-end gap-2 sm:col-span-2">
            <button type="button" onClick={() => setFormOpen(false)} className="h-11 rounded-xl border border-slate-200 px-5 text-sm font-bold dark:border-slate-700">Cancel</button>
            <button type="submit" disabled={saving} className="h-11 rounded-xl bg-brand-600 px-6 text-sm font-bold text-white disabled:opacity-50">
              {saving ? 'Saving…' : editing ? 'Update' : 'Create'}
            </button>
          </div>
        </form>
      </Modal>

      <ConfirmDialog open={!!deleting} onClose={() => setDeleting(null)} onConfirm={doDelete}
        title="Delete user" message={`Delete user “${deleting?.name}”? They will no longer be able to sign in.`} />

      {toast && (
        <div className="no-print fixed bottom-16 left-1/2 z-[70] -translate-x-1/2 rounded-full bg-slate-900 px-4 py-2 text-xs font-semibold text-white shadow-lg">{toast}</div>
      )}
    </div>
  );
}
