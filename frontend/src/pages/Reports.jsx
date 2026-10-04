import React, { useEffect, useState } from 'react';
import { Download, Printer } from 'lucide-react';
import api from '../api/client';
import { ENDPOINTS } from '../api/endpoints';
import DataTable from '../components/DataTable';
import LoadingSkeleton from '../components/LoadingSkeleton';
import EmptyState from '../components/EmptyState';

const TYPES = [
  { id: 'sales', label: 'Sales report', ep: ENDPOINTS.reports.sales },
  { id: 'purchases', label: 'Purchases report', ep: ENDPOINTS.reports.purchases },
  { id: 'profit', label: 'Profit & loss', ep: ENDPOINTS.reports.profit },
  { id: 'inventory', label: 'Inventory report', ep: ENDPOINTS.reports.inventory },
  { id: 'expiry', label: 'Expiry report', ep: ENDPOINTS.reports.expiry },
  { id: 'expenses', label: 'Expenses report', ep: ENDPOINTS.reports.expenses },
  { id: 'custom', label: 'Custom report', ep: ENDPOINTS.reports.custom },
];

/**
 * Reports: date range + type selector + filters, server-rendered table,
 * export to PDF / Excel, and print. Columns adapt to whatever the backend
 * returns (objects with consistent keys).
 */
export default function Reports() {
  const [type, setType] = useState('sales');
  const [from, setFrom] = useState(() => {
    const d = new Date();
    d.setDate(1);
    return d.toISOString().slice(0, 10);
  });
  const [to, setTo] = useState(() => new Date().toISOString().slice(0, 10));
  const [q, setQ] = useState('');
  const [page, setPage] = useState(1);
  const [rows, setRows] = useState([]);
  const [columns, setColumns] = useState([]);
  const [summary, setSummary] = useState(null);
  const [loading, setLoading] = useState(false);
  const [ran, setRan] = useState(false);
  const [exporting, setExporting] = useState(false);
  const [toast, setToast] = useState('');

  const showToast = (m) => {
    setToast(m);
    setTimeout(() => setToast(''), 3000);
  };

  const buildColumns = (data) => {
    if (!data || data.length === 0) return [];
    const keys = Object.keys(data[0]).filter((k) => !['id'].includes(k));
    return keys.slice(0, 8).map((k) => ({
      key: k,
      label: k.replace(/_/g, ' '),
      sortable: true,
      render: (r) => {
        const v = r[k];
        if (v == null) return '—';
        if (typeof v === 'number') return v.toLocaleString(undefined, { maximumFractionDigits: 2 });
        if (typeof v === 'object') return JSON.stringify(v).slice(0, 40);
        return String(v).length > 60 ? String(v).slice(0, 60) + '…' : String(v);
      },
      className: typeof data[0][k] === 'number' ? 'text-right' : '',
    }));
  };

  const run = async (p = 1) => {
    setLoading(true);
    try {
      const ep = TYPES.find((t) => t.id === type).ep;
      const res = await api.get(ep, { params: { from, to, q: q || undefined, page: p, per_page: 20 } });
      const d = res.unwrapped?.data;
      const data = d?.data || d?.rows || (Array.isArray(d) ? d : []);
      setRows(data);
      setColumns(buildColumns(data));
      setSummary(d?.summary || d?.totals || null);
      setPage(d?.meta?.current_page || p);
      setRan(true);
    } catch (e) {
      showToast(e.message || 'Report failed.');
      setRows([]);
      setColumns([]);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    setPage(1);
  }, [type]);

  const download = async (kind) => {
    setExporting(true);
    try {
      const ep = kind === 'pdf' ? ENDPOINTS.export.pdf : ENDPOINTS.export.excel;
      const res = await api.get(ep, {
        params: { type, from, to, q: q || undefined },
        responseType: 'blob',
      });
      const ext = kind === 'pdf' ? 'pdf' : 'xlsx';
      const url = window.URL.createObjectURL(new Blob([res.data]));
      const a = document.createElement('a');
      a.href = url;
      a.download = `${type}-report-${from}-to-${to}.${ext}`;
      a.click();
      window.URL.revokeObjectURL(url);
    } catch (e) {
      showToast(e.message || 'Export failed.');
    } finally {
      setExporting(false);
    }
  };

  const inp = 'h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800';

  return (
    <div className="space-y-3">
      <h1 className="text-xl font-extrabold">Reports</h1>

      <div className="no-print glass space-y-3 p-4">
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7">
          {TYPES.map((t) => (
            <button key={t.id} onClick={() => setType(t.id)}
              className={`h-11 rounded-xl border text-xs font-bold ${type === t.id ? 'border-brand-600 bg-brand-600 text-white' : 'border-slate-200 dark:border-slate-700'}`}>
              {t.label}
            </button>
          ))}
        </div>
        <div className="flex flex-wrap items-end gap-2">
          <label className="text-xs font-semibold">From
            <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className={`${inp} mt-1 block`} />
          </label>
          <label className="text-xs font-semibold">To
            <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className={`${inp} mt-1 block`} />
          </label>
          <label className="min-w-[180px] flex-1 text-xs font-semibold">Filter
            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Optional keyword…" className={`${inp} mt-1 block w-full`} />
          </label>
          <button onClick={() => run(1)} disabled={loading} className="h-11 rounded-xl bg-brand-600 px-6 text-sm font-bold text-white disabled:opacity-50">
            {loading ? 'Running…' : 'Run report'}
          </button>
        </div>
      </div>

      {summary && (
        <div className="glass grid grid-cols-2 gap-3 p-4 sm:grid-cols-4">
          {Object.entries(summary).slice(0, 8).map(([k, v]) => (
            <div key={k}>
              <p className="text-xs capitalize text-slate-500">{k.replace(/_/g, ' ')}</p>
              <p className="text-lg font-extrabold">
                {typeof v === 'number' ? v.toLocaleString(undefined, { maximumFractionDigits: 2 }) : String(v)}
              </p>
            </div>
          ))}
        </div>
      )}

      <div className="glass p-3">
        <div className="no-print mb-2 flex justify-end gap-2">
          <button onClick={() => window.print()} className="flex h-10 items-center gap-1.5 rounded-xl border border-slate-200 px-4 text-xs font-bold dark:border-slate-700">
            <Printer size={15} /> Print
          </button>
          <button onClick={() => download('pdf')} disabled={exporting || !ran} className="flex h-10 items-center gap-1.5 rounded-xl border border-slate-200 px-4 text-xs font-bold disabled:opacity-50 dark:border-slate-700">
            <Download size={15} /> PDF
          </button>
          <button onClick={() => download('excel')} disabled={exporting || !ran} className="flex h-10 items-center gap-1.5 rounded-xl border border-slate-200 px-4 text-xs font-bold disabled:opacity-50 dark:border-slate-700">
            <Download size={15} /> Excel
          </button>
        </div>
        {loading ? (
          <LoadingSkeleton rows={5} />
        ) : !ran ? (
          <EmptyState title="No report yet" hint="Pick a report type and date range, then press Run report." />
        ) : (
          <div className="print-area">
            <h2 className="mb-2 hidden text-center text-lg font-extrabold print:block">
              {TYPES.find((t) => t.id === type).label} — {from} to {to}
            </h2>
            <DataTable columns={columns} data={rows} onPage={(p) => run(p)} emptyText="No records for this range." />
          </div>
        )}
      </div>

      {toast && (
        <div className="no-print fixed bottom-16 left-1/2 z-[70] -translate-x-1/2 rounded-full bg-slate-900 px-4 py-2 text-xs font-semibold text-white shadow-lg">{toast}</div>
      )}
    </div>
  );
}
