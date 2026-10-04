import React from 'react';
import { TrendingDown, TrendingUp } from 'lucide-react';

/** Glassmorphism KPI card with optional trend delta. */
export default function StatCard({ icon: Icon, label, value, sub, delta, deltaUp, tone = 'brand' }) {
  const tones = {
    brand: 'bg-brand-500/15 text-brand-600 dark:text-brand-400',
    green: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
    amber: 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
    red: 'bg-red-500/15 text-red-600 dark:text-red-400',
    violet: 'bg-violet-500/15 text-violet-600 dark:text-violet-400',
  };
  return (
    <div className="glass p-4">
      <div className="flex items-start justify-between">
        <div>
          <p className="text-xs font-medium text-slate-500 dark:text-slate-400">{label}</p>
          <p className="mt-1 text-2xl font-extrabold tracking-tight">{value}</p>
          {sub && <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{sub}</p>}
        </div>
        {Icon && (
          <span className={`grid h-11 w-11 place-items-center rounded-xl ${tones[tone] || tones.brand}`}>
            <Icon size={22} />
          </span>
        )}
      </div>
      {typeof delta === 'number' && (
        <p className={`mt-2 flex items-center gap-1 text-xs font-semibold ${deltaUp ? 'text-emerald-600' : 'text-red-500'}`}>
          {deltaUp ? <TrendingUp size={14} /> : <TrendingDown size={14} />}
          {delta}% vs previous period
        </p>
      )}
    </div>
  );
}
