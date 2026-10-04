import React, { useCallback, useEffect, useState } from 'react';
import { Plus, Trash2, Upload, Printer, FileText } from 'lucide-react';
import api from '../api/client';
import { ENDPOINTS } from '../api/endpoints';
import { usePermissions } from '../hooks/usePermissions';
import { useDebounce } from '../hooks/useDebounce';
import DataTable from '../components/DataTable';
import { Modal, ConfirmDialog } from '../components/Modal';
import EmptyState from '../components/EmptyState';
import LoadingSkeleton from '../components/LoadingSkeleton';

const emptyForm = { customer_name: '', customer_phone: '', doctor_name: '', notes: '' };

/** Prescriptions: upload image/PDF, detail view with attachment preview, print. */
export default function Prescriptions() {
  const { can } = usePermissions();
  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const debouncedQ = useDebounce(q, 400);

  const [formOpen, setFormOpen] = useState(false);
  const [form, setForm] = useState(emptyForm);
  const [file, setFile] = useState(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [detail, setDetail] = useState(null);
  const [deleting, setDeleting] = useState(null);
  const [toast, setToast] = useState('');

  const showToast = (m) => {
    setToast(m);
    setTimeout(() => setToast(''), 3000);
  };

  const fetchRows = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get(ENDPOINTS.prescriptions.list, {
        params: { page, q: debouncedQ || undefined, per_page: 12 },
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

  const openDetail = async (r) => {
    try {
      const res = await api.get(ENDPOINTS.prescriptions.detail(r.id));
      setDetail(res.unwrapped?.data || r);
    } catch (e) {
      setDetail(r);
    }
  };

  const openCreate = () => {
    setForm(emptyForm);
    setFile(null);
    setError('');
    setFormOpen(true);
  };

  const save = async (e) => {
    e.preventDefault();
    setError('');
    if (!form.customer_name.trim()) return setError('Customer name is required.');
    if (!file) return setError('Attach a prescription image or PDF.');
    setSaving(true);
    try {
      const fd = new FormData();
      Object.entries(form).forEach(([k, v]) => fd.append(k, v));
      fd.append('file', file);
      await api.post(ENDPOINTS.prescriptions.create, fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      showToast('Prescription uploaded.');
      setFormOpen(false);
      fetchRows();
    } catch (err) {
      setError(err.message || 'Upload failed.');
    } finally {
      setSaving(false);
    }
  };

  const doDelete = async () => {
    try {
      await api.delete(ENDPOINTS.prescriptions.detail(deleting.id));
      showToast('Prescription deleted.');
      setDeleting(null);
      fetchRows();
    } catch (e) {
      showToast(e.message || 'Delete failed.');
    }
  };

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
  const inp = 'h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800';

  const columns = [
    { key: 'customer', label: 'Customer', sortable: true, render: (r) => (
      <button onClick={() => openDetail(r)} className="font-semibold text-brand-600">{r.customer_name || r.customer?.name || '—'}</button>
    )},
    { key: 'doctor', label: 'Doctor', render: (r) => r.doctor_name || '—' },
    { key: 'date', label: 'Date', sortable: true, render: (r) => String(r.created_at || '').slice(0, 10) },
    { key: 'file', label: 'File', render: (r) => (
      <span className="flex items-center gap-1 text-xs text-slate-500"><FileText size={14} />{r.file_name || r.file_type || 'Attachment'}</span>
    )},
    { key: 'actions', label: '', className: 'text-right', render: (r) => (
      can('prescriptions.delete') ? (
        <button onClick={() => setDeleting(r)} className="rounded-lg p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-950/40" title="Delete">
          <Trash2 size={16} />
        </button>
      ) : null
    )},
  ];

  const fileUrl = (d) => d?.file_url || d?.attachment_url || null;
  const isPdf = (d) => /\.pdf$/i.test(fileUrl(d) || '') || d?.file_type === 'pdf';

  if (loading && rows.length === 0) return <LoadingSkeleton rows={6} />;

  return (
    <div className="space-y-3">
      <div className="no-print flex items-center gap-2">
        <h1 className="mr-auto text-xl font-extrabold">Prescriptions</h1>
        {can('prescriptions.create') && (
          <button onClick={openCreate} className="flex h-10 items-center gap-1.5 rounded-xl bg-brand-600 px-4 text-xs font-bold text-white">
            <Plus size={15} /> Upload prescription
          </button>
        )}
      </div>
      <div className="no-print glass p-3">
        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search customer / doctor…"
          className="h-10 w-full max-w-sm rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800" />
      </div>
      <div className="glass p-3">
        <DataTable columns={columns} data={rows} meta={meta} loading={loading} onPage={setPage} emptyText="No prescriptions uploaded yet." />
      </div>

      {/* Upload */}
      <Modal open={formOpen} onClose={() => setFormOpen(false)} title="Upload prescription">
        <form onSubmit={save} className="space-y-3">
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label className="block text-xs font-semibold">Customer name *
              <input value={form.customer_name} onChange={set('customer_name')} className={`${inp} mt-1`} />
            </label>
            <label className="block text-xs font-semibold">Customer phone
              <input value={form.customer_phone} onChange={set('customer_phone')} inputMode="tel" className={`${inp} mt-1`} />
            </label>
            <label className="block text-xs font-semibold">Doctor name
              <input value={form.doctor_name} onChange={set('doctor_name')} className={`${inp} mt-1`} />
            </label>
          </div>
          <label className="block text-xs font-semibold">Notes
            <textarea value={form.notes} onChange={set('notes')} rows={2} className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
          </label>
          <label className="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-slate-300 bg-slate-50 p-6 text-center dark:border-slate-700 dark:bg-slate-800/50">
            <Upload size={28} className="text-slate-400" />
            <span className="text-sm font-semibold">{file ? file.name : 'Tap to attach image or PDF'}</span>
            <span className="text-xs text-slate-500">JPG, PNG or PDF</span>
            <input type="file" accept="image/*,.pdf" className="hidden" onChange={(e) => setFile(e.target.files?.[0] || null)} />
          </label>
          {error && <p className="rounded-xl bg-red-50 px-3 py-2 text-xs text-red-600 dark:bg-red-950/50">{error}</p>}
          <div className="flex justify-end gap-2">
            <button type="button" onClick={() => setFormOpen(false)} className="h-11 rounded-xl border border-slate-200 px-5 text-sm font-bold dark:border-slate-700">Cancel</button>
            <button type="submit" disabled={saving} className="h-11 rounded-xl bg-brand-600 px-6 text-sm font-bold text-white disabled:opacity-50">
              {saving ? 'Uploading…' : 'Upload'}
            </button>
          </div>
        </form>
      </Modal>

      {/* Detail */}
      <Modal open={!!detail} onClose={() => setDetail(null)} title={detail ? `Prescription — ${detail.customer_name || ''}` : ''} wide>
        {detail && (
          <div className="print-area space-y-3">
            <dl className="grid grid-cols-2 gap-2 text-sm">
              <div><dt className="text-xs text-slate-500">Customer</dt><dd className="font-semibold">{detail.customer_name}</dd></div>
              <div><dt className="text-xs text-slate-500">Phone</dt><dd>{detail.customer_phone || '—'}</dd></div>
              <div><dt className="text-xs text-slate-500">Doctor</dt><dd>{detail.doctor_name || '—'}</dd></div>
              <div><dt className="text-xs text-slate-500">Date</dt><dd>{String(detail.created_at || '').slice(0, 10)}</dd></div>
            </dl>
            {detail.notes && <p className="text-sm text-slate-500">Note: {detail.notes}</p>}
            <div className="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">
              {fileUrl(detail) ? (
                isPdf(detail) ? (
                  <iframe src={fileUrl(detail)} title="Prescription PDF" className="h-[60vh] w-full" />
                ) : (
                  <img src={fileUrl(detail)} alt="Prescription" className="max-h-[60vh] w-full object-contain bg-slate-50" />
                )
              ) : (
                <EmptyState title="No preview" hint="The attachment URL was not returned by the server." />
              )}
            </div>
            <div className="no-print flex justify-end">
              <button onClick={() => window.print()} className="flex h-11 items-center gap-1.5 rounded-xl bg-brand-600 px-5 text-sm font-bold text-white">
                <Printer size={16} /> Print
              </button>
            </div>
          </div>
        )}
      </Modal>

      <ConfirmDialog open={!!deleting} onClose={() => setDeleting(null)} onConfirm={doDelete}
        title="Delete prescription" message="Delete this prescription record?" />

      {toast && (
        <div className="no-print fixed bottom-16 left-1/2 z-[70] -translate-x-1/2 rounded-full bg-slate-900 px-4 py-2 text-xs font-semibold text-white shadow-lg">{toast}</div>
      )}
    </div>
  );
}
