import React, { useEffect } from 'react';
import { X, AlertTriangle } from 'lucide-react';

/** Generic modal shell: overlay + centered panel, Esc/overlay click to close. */
export function Modal({ open, onClose, title, children, wide = false }) {
  useEffect(() => {
    if (!open) return;
    const onKey = (e) => e.key === 'Escape' && onClose?.();
    document.addEventListener('keydown', onKey);
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = '';
    };
  }, [open, onClose]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-[60] flex items-end justify-center sm:items-center">
      <div className="absolute inset-0 bg-black/50" onClick={onClose} />
      <div
        role="dialog"
        aria-modal="true"
        className={`relative flex max-h-[92vh] w-full flex-col rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl dark:bg-slate-900 ${
          wide ? 'sm:max-w-3xl' : 'sm:max-w-lg'
        }`}
      >
        <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3 dark:border-slate-700">
          <h3 className="text-base font-bold">{title}</h3>
          <button
            onClick={onClose}
            className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
            aria-label="Close"
          >
            <X size={18} />
          </button>
        </div>
        <div className="scrollbar-thin overflow-y-auto px-4 py-4">{children}</div>
      </div>
    </div>
  );
}

/** Confirmation dialog for destructive actions (delete, return, etc.). */
export function ConfirmDialog({ open, onClose, onConfirm, title = 'Are you sure?', message, confirmLabel = 'Delete', busy = false }) {
  return (
    <Modal open={open} onClose={onClose} title={title}>
      <div className="flex items-start gap-3">
        <span className="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-red-100 text-red-600 dark:bg-red-900/40">
          <AlertTriangle size={22} />
        </span>
        <p className="pt-1 text-sm text-slate-600 dark:text-slate-300">
          {message || 'This action cannot be undone.'}
        </p>
      </div>
      <div className="mt-6 flex justify-end gap-2">
        <button
          onClick={onClose}
          disabled={busy}
          className="h-11 rounded-xl border border-slate-200 px-5 text-sm font-semibold hover:bg-slate-100 disabled:opacity-50 dark:border-slate-700 dark:hover:bg-slate-800"
        >
          Cancel
        </button>
        <button
          onClick={onConfirm}
          disabled={busy}
          className="h-11 rounded-xl bg-red-600 px-5 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-50"
        >
          {busy ? 'Working…' : confirmLabel}
        </button>
      </div>
    </Modal>
  );
}
