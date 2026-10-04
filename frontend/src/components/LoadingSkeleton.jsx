import React from 'react';

/** Shimmer skeleton rows while a page loads. */
export default function LoadingSkeleton({ rows = 4 }) {
  return (
    <div className="space-y-3">
      {Array.from({ length: rows }).map((_, i) => (
        <div key={i} className="glass p-4">
          <div className="relative h-5 w-2/3 overflow-hidden rounded bg-slate-200 dark:bg-slate-700">
            <div className="animate-shimmer absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/60 to-transparent" />
          </div>
          <div className="relative mt-2 h-4 w-1/3 overflow-hidden rounded bg-slate-200 dark:bg-slate-700">
            <div className="animate-shimmer absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/60 to-transparent" />
          </div>
        </div>
      ))}
    </div>
  );
}
