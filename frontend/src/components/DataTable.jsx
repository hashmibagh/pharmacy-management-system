import React, { useMemo, useState } from 'react';
import { ArrowDown, ArrowUp, ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight } from 'lucide-react';

/**
 * Generic server-side data table.
 * - columns: [{ key, label, render?, sortable?, className? }]
 * - data: array of rows (page already fetched)
 * - sorting is client-side within the fetched page unless `onSort` is given
 * - pagination is server-driven via meta { current_page, last_page, per_page, total }
 */
export default function DataTable({
  columns,
  data = [],
  meta = null,
  loading = false,
  onPage,
  onSort,
  emptyText = 'No records found.',
}) {
  const [sortKey, setSortKey] = useState(null);
  const [sortDir, setSortDir] = useState('asc');

  const rows = useMemo(() => {
    if (onSort || !sortKey) return data;
    const sorted = [...data].sort((a, b) => {
      const av = a[sortKey];
      const bv = b[sortKey];
      if (av == null && bv == null) return 0;
      if (av == null) return 1;
      if (bv == null) return -1;
      if (typeof av === 'number' && typeof bv === 'number') return av - bv;
      return String(av).localeCompare(String(bv));
    });
    return sortDir === 'asc' ? sorted : sorted.reverse();
  }, [data, sortKey, sortDir, onSort]);

  const toggleSort = (col) => {
    if (!col.sortable) return;
    if (onSort) {
      onSort(col.key);
      return;
    }
    if (sortKey === col.key) setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'));
    else {
      setSortKey(col.key);
      setSortDir('asc');
    }
  };

  const pager = (page) => onPage && onPage(page);

  return (
    <div>
      <div className="scrollbar-thin -mx-1 overflow-x-auto px-1">
        <table className="w-full min-w-[640px] border-collapse text-sm">
          <thead>
            <tr className="border-b border-slate-200 dark:border-slate-700">
              {columns.map((col) => (
                <th
                  key={col.key}
                  onClick={() => toggleSort(col)}
                  className={`whitespace-nowrap px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400 ${
                    col.sortable ? 'cursor-pointer select-none hover:text-slate-700 dark:hover:text-slate-200' : ''
                  } ${col.className || ''}`}
                >
                  <span className="inline-flex items-center gap-1">
                    {col.label}
                    {col.sortable && sortKey === col.key && !onSort && (
                      sortDir === 'asc' ? <ArrowUp size={13} /> : <ArrowDown size={13} />
                    )}
                  </span>
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {loading ? (
              Array.from({ length: 5 }).map((_, i) => (
                <tr key={i} className="border-b border-slate-100 dark:border-slate-800">
                  {columns.map((c) => (
                    <td key={c.key} className="px-3 py-3">
                      <div className="relative h-4 overflow-hidden rounded bg-slate-200 dark:bg-slate-700">
                        <div className="animate-shimmer absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/60 to-transparent" />
                      </div>
                    </td>
                  ))}
                </tr>
              ))
            ) : rows.length === 0 ? (
              <tr>
                <td colSpan={columns.length} className="px-3 py-10 text-center text-sm text-slate-500">
                  {emptyText}
                </td>
              </tr>
            ) : (
              rows.map((row, i) => (
                <tr
                  key={row.id ?? i}
                  className="border-b border-slate-100 transition-colors hover:bg-slate-50 last:border-0 dark:border-slate-800 dark:hover:bg-slate-800/50"
                >
                  {columns.map((col) => (
                    <td key={col.key} className={`px-3 py-2.5 align-middle ${col.className || ''}`}>
                      {col.render ? col.render(row) : row[col.key]}
                    </td>
                  ))}
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {meta && meta.last_page > 1 && (
        <div className="no-print mt-3 flex flex-wrap items-center justify-between gap-2 text-sm">
          <p className="text-xs text-slate-500">
            Showing {meta.from ?? ''}–{meta.to ?? ''} of {meta.total} records
          </p>
          <div className="flex items-center gap-1">
            <PageBtn onClick={() => pager(1)} disabled={meta.current_page <= 1} label="First">
              <ChevronsLeft size={16} />
            </PageBtn>
            <PageBtn onClick={() => pager(meta.current_page - 1)} disabled={meta.current_page <= 1} label="Previous">
              <ChevronLeft size={16} />
            </PageBtn>
            <span className="px-2 text-xs font-medium">
              {meta.current_page} / {meta.last_page}
            </span>
            <PageBtn onClick={() => pager(meta.current_page + 1)} disabled={meta.current_page >= meta.last_page} label="Next">
              <ChevronRight size={16} />
            </PageBtn>
            <PageBtn onClick={() => pager(meta.last_page)} disabled={meta.current_page >= meta.last_page} label="Last">
              <ChevronsRight size={16} />
            </PageBtn>
          </div>
        </div>
      )}
    </div>
  );
}

function PageBtn({ children, onClick, disabled, label }) {
  return (
    <button
      onClick={onClick}
      disabled={disabled}
      aria-label={label}
      className="grid h-9 w-9 place-items-center rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-100 disabled:opacity-40 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
    >
      {children}
    </button>
  );
}
