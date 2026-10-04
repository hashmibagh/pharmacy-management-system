import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Plus, Pencil, Trash2, Check, Minus } from 'lucide-react';
import api from '../api/client';
import { ENDPOINTS, PERMISSIONS } from '../api/endpoints';
import { usePermissions } from '../hooks/usePermissions';
import DataTable from '../components/DataTable';
import { Modal, ConfirmDialog } from '../components/Modal';
import LoadingSkeleton from '../components/LoadingSkeleton';
import EmptyState from '../components/EmptyState';

/** Roles: CRUD + a permission matrix editor saved via /roles/:id/permissions. */
export default function Roles() {
  const { can } = usePermissions();
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [name, setName] = useState('');
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(null);

  const [matrixRole, setMatrixRole] = useState(null);
  const [matrixPerms, setMatrixPerms] = useState([]);
  const [matrixBusy, setMatrixBusy] = useState(false);
  const [toast, setToast] = useState('');

  const showToast = (m) => {
    setToast(m);
    setTimeout(() => setToast(''), 3000);
  };

  const fetchRows = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get(ENDPOINTS.roles.list, { params: { per_page: 50 } });
      const d = res.unwrapped?.data;
      setRows(d?.data || d || []);
    } catch (e) {
      setRows([]);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchRows();
  }, [fetchRows]);

  const openCreate = () => {
    setEditing(null);
    setName('');
    setFormOpen(true);
  };
  const openEdit = (r) => {
    setEditing(r);
    setName(r.name || '');
    setFormOpen(true);
  };

  const saveRole = async (e) => {
    e.preventDefault();
    if (!name.trim()) return showToast('Role name is required.');
    setSaving(true);
    try {
      if (editing) await api.put(ENDPOINTS.roles.detail(editing.id), { name: name.trim() });
      else await api.post(ENDPOINTS.roles.create, { name: name.trim() });
      showToast(editing ? 'Role updated.' : 'Role created.');
      setFormOpen(false);
      fetchRows();
    } catch (err) {
      showToast(err.message || 'Save failed.');
    } finally {
      setSaving(false);
    }
  };

  const doDelete = async () => {
    try {
      await api.delete(ENDPOINTS.roles.detail(deleting.id));
      showToast('Role deleted.');
      setDeleting(null);
      fetchRows();
    } catch (e) {
      showToast(e.message || 'Delete failed.');
    }
  };

  const openMatrix = async (r) => {
    setMatrixRole(r);
    setMatrixBusy(true);
    try {
      const res = await api.get(ENDPOINTS.roles.detail(r.id));
      const d = res.unwrapped?.data;
      setMatrixPerms(d?.permissions || d?.perms || []);
    } catch (e) {
      setMatrixPerms([]);
    } finally {
      setMatrixBusy(false);
    }
  };

  const togglePerm = (perm) => {
    setMatrixPerms((prev) =>
      prev.includes(perm) ? prev.filter((p) => p !== perm) : [...prev, perm],
    );
  };

  const toggleModule = (perms) => {
    const allOn = perms.every((p) => matrixPerms.includes(p));
    setMatrixPerms((prev) =>
      allOn ? prev.filter((p) => !perms.includes(p)) : [...new Set([...prev, ...perms])],
    );
  };

  const saveMatrix = async () => {
    setMatrixBusy(true);
    try {
      await api.put(ENDPOINTS.roles.permissions(matrixRole.id), { permissions: matrixPerms });
      showToast('Permissions saved.');
      setMatrixRole(null);
      fetchRows();
    } catch (e) {
      showToast(e.message || 'Save failed.');
    } finally {
      setMatrixBusy(false);
    }
  };

  // Group permissions by module prefix for the matrix UI.
  const modules = useMemo(() => {
    const groups = {};
    PERMISSIONS.forEach((p) => {
      const mod = p.split('.')[0];
      (groups[mod] = groups[mod] || []).push(p);
    });
    return Object.entries(groups);
  }, []);

  const columns = [
    { key: 'name', label: 'Role', sortable: true, render: (r) => <span className="font-semibold capitalize">{r.name}</span> },
    { key: 'users', label: 'Users', render: (r) => r.users_count ?? '—' },
    { key: 'permissions', label: 'Permissions', render: (r) => `${(r.permissions || []).length} granted` },
    { key: 'actions', label: '', className: 'text-right whitespace-nowrap', render: (r) => (
      <div className="flex justify-end gap-1">
        {can('roles.edit') && (
          <button onClick={() => openMatrix(r)} className="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-bold dark:border-slate-700" title="Edit permissions">
            Permissions
          </button>
        )}
        {can('roles.edit') && <button onClick={() => openEdit(r)} className="rounded-lg p-2 hover:bg-slate-100 dark:hover:bg-slate-800" title="Rename"><Pencil size={16} /></button>}
        {can('roles.delete') && <button onClick={() => setDeleting(r)} className="rounded-lg p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-950/40" title="Delete"><Trash2 size={16} /></button>}
      </div>
    )},
  ];

  if (loading) return <LoadingSkeleton rows={5} />;

  return (
    <div className="space-y-3">
      <div className="no-print flex items-center gap-2">
        <h1 className="mr-auto text-xl font-extrabold">Roles</h1>
        {can('roles.create') && (
          <button onClick={openCreate} className="flex h-10 items-center gap-1.5 rounded-xl bg-brand-600 px-4 text-xs font-bold text-white">
            <Plus size={15} /> Add role
          </button>
        )}
      </div>
      <div className="glass p-3">
        <DataTable columns={columns} data={rows} emptyText="No roles yet." />
      </div>

      <Modal open={formOpen} onClose={() => setFormOpen(false)} title={editing ? 'Rename role' : 'Add role'}>
        <form onSubmit={saveRole} className="space-y-3">
          <label className="block text-xs font-semibold">Role name
            <input value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. pharmacist"
              className="mt-1 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800" />
          </label>
          <div className="flex justify-end gap-2">
            <button type="button" onClick={() => setFormOpen(false)} className="h-11 rounded-xl border border-slate-200 px-5 text-sm font-bold dark:border-slate-700">Cancel</button>
            <button type="submit" disabled={saving} className="h-11 rounded-xl bg-brand-600 px-6 text-sm font-bold text-white disabled:opacity-50">
              {saving ? 'Saving…' : editing ? 'Update' : 'Create'}
            </button>
          </div>
        </form>
      </Modal>

      {/* Permission matrix */}
      <Modal open={!!matrixRole} onClose={() => setMatrixRole(null)} title={matrixRole ? `Permissions — ${matrixRole.name}` : ''} wide>
        {matrixBusy && matrixPerms.length === 0 ? (
          <LoadingSkeleton rows={3} />
        ) : (
          <>
            <div className="space-y-4">
              {modules.map(([mod, perms]) => {
                const on = perms.filter((p) => matrixPerms.includes(p)).length;
                return (
                  <div key={mod} className="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                    <div className="mb-2 flex items-center justify-between">
                      <h4 className="text-sm font-bold capitalize">{mod}</h4>
                      <button onClick={() => toggleModule(perms)}
                        className="flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-bold dark:border-slate-700">
                        {on === perms.length ? <Minus size={13} /> : <Check size={13} />}
                        {on === perms.length ? 'None' : 'All'} ({on}/{perms.length})
                      </button>
                    </div>
                    <div className="grid grid-cols-1 gap-1 sm:grid-cols-2">
                      {perms.map((p) => (
                        <label key={p} className="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50 dark:hover:bg-slate-800">
                          <input type="checkbox" checked={matrixPerms.includes(p)} onChange={() => togglePerm(p)} className="h-4 w-4 accent-brand-600" />
                          <span className="font-mono text-xs">{p}</span>
                        </label>
                      ))}
                    </div>
                  </div>
                );
              })}
            </div>
            <div className="mt-4 flex justify-end gap-2">
              <button onClick={() => setMatrixRole(null)} className="h-11 rounded-xl border border-slate-200 px-5 text-sm font-bold dark:border-slate-700">Cancel</button>
              <button onClick={saveMatrix} disabled={matrixBusy} className="h-11 rounded-xl bg-brand-600 px-6 text-sm font-bold text-white disabled:opacity-50">
                {matrixBusy ? 'Saving…' : 'Save permissions'}
              </button>
            </div>
          </>
        )}
      </Modal>

      <ConfirmDialog open={!!deleting} onClose={() => setDeleting(null)} onConfirm={doDelete}
        title="Delete role" message={`Delete role “${deleting?.name}”? Users with this role must be reassigned first.`} />

      {toast && (
        <div className="no-print fixed bottom-16 left-1/2 z-[70] -translate-x-1/2 rounded-full bg-slate-900 px-4 py-2 text-xs font-semibold text-white shadow-lg">{toast}</div>
      )}
    </div>
  );
}
