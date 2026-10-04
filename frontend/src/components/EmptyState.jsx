import React from 'react';
import { Inbox } from 'lucide-react';

/** Friendly placeholder for lists with zero rows. */
export default function EmptyState({ icon: Icon = Inbox, title = 'Nothing here yet', hint, action }) {
  return (
    <div className="flex flex-col items-center justify-center rounded-2xl border border-dashed border-slate-300 px-6 py-12 text-center dark:border-slate-700">
      <span className="grid h-14 w-14 place-items-center rounded-2xl bg-slate-100 text-slate-400 dark:bg-slate-800">
        <Icon size={28} />
      </span>
      <h3 className="mt-3 text-sm font-bold">{title}</h3>
      {hint && <p className="mt-1 max-w-sm text-xs text-slate-500">{hint}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}
