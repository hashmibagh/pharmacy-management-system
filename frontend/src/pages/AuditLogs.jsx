import React, { useCallback, useEffect, useState } from 'react';
import api from '../api/client';
import { ENDPOINTS } from '../api/endpoints';
import { useDebounce } from '../hooks/useDebounce';
import DataTable from '../components/DataTable';
import LoadingSkeleton from '../components/LoadingSkeleton';

/** Audit logs: who did what and when. Read-only. */
export default function AuditLogs() {
  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const debouncedQ = useDebounce(q, 400);

  const fetchRows = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get(ENDPOINTS.auditLogs, {
        params: { page, q: debouncedQ || undefined, per_page: 20 },
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

  const columns = [
    { key: 'when', label: 'When', render: (r) => String(r.created_at || '').slice(0, 19).replace('T', ' ') },
    { key: 'user', label: 'User', render: (r) => r.user?.name || r.user_name || '—' },
    { key: 'action', label: 'Action', render: (r) => (
      <span className="rounded-full bg-slate-100 px-2.5 py-0.5 font-mono text-xs font-bold dark:bg-slate-800">
        {r.action || r.event || '—'}
      </span>
    )},
    { key: 'model', label: 'Record', render: (r) => `${r.auditable_type || r.model || ''} ${r.auditable_id || r.model_id ? `#${r.auditable_id || r.model_id}` : ''}` },
    { key: 'detail', label: 'Detail', render: (r) => (
      <span className="block max-w-xs truncate text-xs text-slate-500">{r.description || r.detail || JSON.stringify(r.changes || {}).slice(0, 80)}</span>
    )},
  ];

  if (loading && rows.length === 0) return <LoadingSkeleton rows={6} />;

  return (
    <div className="space-y-3">
      <h1 className="text-xl font-extrabold">Audit Logs</h1>
      <div className="no-print glass p-3">
        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search user / action…"
          className="h-10 w-full max-w-sm rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800" />
      </div>
      <div className="glass p-3">
        <DataTable columns={columns} data={rows} meta={meta} loading={loading} onPage={setPage} emptyText="No audit entries yet." />
      </div>
    </div>
  );
}
