import React, { useEffect, useState } from 'react';
import { Download, X } from 'lucide-react';

/**
 * PWA install prompt. Listens for `beforeinstallprompt`, defers it and
 * shows a small banner; dismissing persists for 14 days.
 */
export default function InstallPrompt() {
  const [deferred, setDeferred] = useState(null);
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    if (localStorage.getItem('pwa-install-dismissed')) return;
    const onPrompt = (e) => {
      e.preventDefault();
      setDeferred(e);
      setVisible(true);
    };
    window.addEventListener('beforeinstallprompt', onPrompt);
    return () => window.removeEventListener('beforeinstallprompt', onPrompt);
  }, []);

  if (!visible) return null;

  const install = async () => {
    if (!deferred) return;
    deferred.prompt();
    await deferred.userChoice.catch(() => {});
    setDeferred(null);
    setVisible(false);
  };

  const dismiss = () => {
    localStorage.setItem('pwa-install-dismissed', Date.now().toString());
    setVisible(false);
  };

  return (
    <div className="no-print fixed bottom-4 left-4 right-4 z-50 mx-auto flex max-w-md items-center gap-3 rounded-2xl border border-slate-200 bg-white p-3 shadow-2xl sm:left-auto dark:border-slate-700 dark:bg-slate-900">
      <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-brand-600 text-xl text-white">💊</span>
      <div className="min-w-0 flex-1">
        <p className="text-sm font-bold">Install Pharmacy app</p>
        <p className="truncate text-xs text-slate-500">Faster access & offline support</p>
      </div>
      <button
        onClick={install}
        className="flex h-10 items-center gap-1.5 rounded-xl bg-brand-600 px-4 text-sm font-semibold text-white"
      >
        <Download size={16} /> Install
      </button>
      <button onClick={dismiss} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="Dismiss">
        <X size={16} />
      </button>
    </div>
  );
}
