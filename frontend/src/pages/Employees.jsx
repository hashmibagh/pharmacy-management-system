import React, { useCallback, useEffect, useState } from 'react';
import { Plus, Pencil, Trash2, CalendarCheck, Wallet, Palmtree } from 'lucide-react';
import api from '../api/client';
import { ENDPOINTS } from '../api/endpoints';
import { usePermissions } from '../hooks/usePermissions';
import { useDebounce } from '../hooks/useDebounce';
import DataTable from '../components/DataTable';
import { Modal, ConfirmDialog } from '../components/Modal';
import LoadingSkeleton from '../components/LoadingSkeleton';

const emptyForm = { name: '', phone: '', email: '', address: '', designation: '', salary: '', joining_date: '', status: 'active' };

/** Employees: profile CRUD, attendance view, salary records, leaves. */
export default function Employees() {
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

  const [detail, setDetail] = useState(null); // selected employee w/ tabs
  const [tab, setTab] = useState('attendance');
  const [tabData, setTabData] = useState([]);
  const [tabLoading, setTabLoading] = useState(false);
  const [toast, setToast] = useState('');

  const showToast = (m) => {
    setToast(m);
    setTimeout(() => setToast(''), 3000);
  };

  const fetchRows = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get(ENDPOINTS.employees.list, {
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
    setForm({
      ...emptyForm, name: r.name || '', phone: r.phone || '', email: r.email || '',
      address: r.address || '', designation: r.designation || '', salary: r.salary ?? '',
      joining_date: String(r.joining_date || '').slice(0, 10), status: r.status || 'active',
    });
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
      const payload = { ...form, salary: form.salary === '' ? null : Number(form.salary), joining_date: form.joining_date || null };
      if (editing) await api.put(ENDPOINTS.employees.detail(editing.id), payload);
      else await api.post(ENDPOINTS.employees.create, payload);
      showToast(editing ? 'Employee updated.' : 'Employee added.');
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
      await api.delete(ENDPOINTS.employees.detail(deleting.id));
      showToast('Employee deleted.');
      setDeleting(null);
      fetchRows();
    } catch (e) {
      showToast(e.message || 'Delete failed.');
    }
  };

  const openDetail = async (r) => {
    setDetail(r);
    setTab('attendance');
    await loadTab(r.id, 'attendance');
  };

  const loadTab = async (empId, t) => {
    setTabLoading(true);
    try {
      const ep =
        t === 'attendance' ? ENDPOINTS.employees.attendance(empId)
          : t === 'salary' ? ENDPOINTS.employees.salary(empId)
            : ENDPOINTS.employees.leaves(empId);
      const res = await api.get(ep);
      const d = res.unwrapped?.data;
      setTabData(d?.data || d || []);
    } catch (e) {
      setTabData([]);
    } finally {
      setTabLoading(false);
    }
  };

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
  const inp = 'h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800';

  const columns = [
    { key: 'name', label: 'Employee', sortable: true, render: (r) => (
      <button onClick={() => openDetail(r)} className="font-semibold text-brand-600">{r.name}</button>
    )},
    { key: 'designation', label: 'Designation', render: (r) => r.designation || '—' },
    { key: 'phone', label: 'Phone', render: (r) => r.phone || '—' },
    { key: 'salary', label: 'Salary', sortable: true, render: (r) => (r.salary != null ? Number(r.salary).toLocaleString() : '—'), className: 'text-right' },
    { key: 'status', label: 'Status', render: (r) => (
      <span className={`rounded-full px-2.5 py-0.5 text-xs font-bold capitalize ${r.status === 'active' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'}`}>
        {r.status || '—'}
      </span>
    )},
    { key: 'actions', label: '', className: 'text-right whitespace-nowrap', render: (r) => (
      <div className="flex justify-end gap-1">
        {can('employees.edit') && <button onClick={() => openEdit(r)} className="rounded-lg p-2 hover:bg-slate-100 dark:hover:bg-slate-800" title="Edit"><Pencil size={16} /></button>}
        {can('employees.delete') && <button onClick={() => setDeleting(r)} className="rounded-lg p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-950/40" title="Delete"><Trash2 size={16} /></button>}
      </div>
    )},
  ];

  const tabIcons = { attendance: CalendarCheck, salary: Wallet, leaves: Palmtree };

  if (loading && rows.length === 0) return <LoadingSkeleton rows={6} />;

  return (
    <div className="space-y-3">
      <div className="no-print flex items-center gap-2">
        <h1 className="mr-auto text-xl font-extrabold">Employees</h1>
        {can('employees.create') && (
          <button onClick={openCreate} className="flex h-10 items-center gap-1.5 rounded-xl bg-brand-600 px-4 text-xs font-bold text-white">
            <Plus size={15} /> Add employee
          </button>
        )}
      </div>
      <div className="no-print glass p-3">
        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search name / phone…"
          className="h-10 w-full max-w-sm rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800" />
      </div>
      <div className="glass p-3">
        <DataTable columns={columns} data={rows} meta={meta} loading={loading} onPage={setPage} emptyText="No employees yet." />
      </div>

      <Modal open={formOpen} onClose={() => setFormOpen(false)} title={editing ? 'Edit employee' : 'Add employee'}>
        <form onSubmit={save} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <label className="block text-xs font-semibold">Name *
            <input value={form.name} onChange={set('name')} className={`${inp} mt-1`} />
            {errors.name && <p className="mt-1 text-red-500">{errors.name}</p>}
          </label>
          <label className="block text-xs font-semibold">Designation
            <input value={form.designation} onChange={set('designation')} className={`${inp} mt-1`} />
          </label>
          <label className="block text-xs font-semibold">Phone
            <input value={form.phone} onChange={set('phone')} inputMode="tel" className={`${inp} mt-1`} />
          </label>
          <label className="block text-xs font-semibold">Email
            <input value={form.email} onChange={set('email')} type="email" className={`${inp} mt-1`} />
          </label>
          <label className="block text-xs font-semibold">Monthly salary
            <input value={form.salary} onChange={set('salary')} inputMode="decimal" className={`${inp} mt-1`} />
          </label>
          <label className="block text-xs font-semibold">Joining date
            <input type="date" value={form.joining_date} onChange={set('joining_date')} className={`${inp} mt-1`} />
          </label>
          <label className="block text-xs font-semibold">Address
            <input value={form.address} onChange={set('address')} className={`${inp} mt-1`} />
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

      {/* Employee detail: attendance / salary / leaves */}
      <Modal open={!!detail} onClose={() => setDetail(null)} title={detail ? `${detail.name} — ${detail.designation || 'Employee'}` : ''} wide>
        {detail && (
          <>
            <div className="flex gap-1">
              {['attendance', 'salary', 'leaves'].map((t) => {
                const Icon = tabIcons[t];
                return (
                  <button key={t} onClick={() => { setTab(t); loadTab(detail.id, t); }}
                    className={`flex h-10 flex-1 items-center justify-center gap-1.5 rounded-xl text-xs font-bold capitalize ${tab === t ? 'bg-brand-600 text-white' : 'bg-slate-100 dark:bg-slate-800'}`}>
                    <Icon size={15} /> {t}
                  </button>
                );
              })}
            </div>
            <div className="mt-3">
              {tabLoading ? (
                <LoadingSkeleton rows={3} />
              ) : tabData.length === 0 ? (
                <p className="py-6 text-center text-xs text-slate-500">No {tab} records.</p>
              ) : (
                <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                  {tabData.map((r, i) => (
                    <li key={i} className="flex items-center justify-between py-2">
                      <span>
                        {r.date ? String(r.date).slice(0, 10) : r.month || r.type || `#${i + 1}`}
                        <span className="ml-2 text-xs text-slate-500">{r.status || r.note || r.reason || ''}</span>
                      </span>
                      {r.amount != null && <span className="font-bold">{Number(r.amount).toLocaleString()}</span>}
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </>
        )}
      </Modal>

      <ConfirmDialog open={!!deleting} onClose={() => setDeleting(null)} onConfirm={doDelete}
        title="Delete employee" message={`Delete “${deleting?.name}”?`} />

      {toast && (
        <div className="no-print fixed bottom-16 left-1/2 z-[70] -translate-x-1/2 rounded-full bg-slate-900 px-4 py-2 text-xs font-semibold text-white shadow-lg">{toast}</div>
      )}
    </div>
  );
}
