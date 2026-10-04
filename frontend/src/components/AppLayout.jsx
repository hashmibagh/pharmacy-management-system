import React from 'react';
import { Outlet } from 'react-router-dom';
import Sidebar from './Sidebar';
import Topbar from './Topbar';
import Breadcrumbs from './Breadcrumbs';
import InstallPrompt from './InstallPrompt';
import { useOnlineStatus } from '../hooks/useOnlineStatus';
import { flushOutbox } from '../offline/sync';

/**
 * Authenticated app shell: sidebar + topbar + page outlet.
 * Flushes the offline outbox shortly after mount in case items are pending.
 */
export default function AppLayout() {
  const [collapsed, setCollapsed] = React.useState(false);
  const [mobileOpen, setMobileOpen] = React.useState(false);
  const online = useOnlineStatus();

  React.useEffect(() => {
    flushOutbox().catch(() => {});
  }, []);

  return (
    <div className="min-h-screen">
      <Sidebar
        collapsed={collapsed}
        onToggle={() => setCollapsed((v) => !v)}
        mobileOpen={mobileOpen}
        onCloseMobile={() => setMobileOpen(false)}
      />
      <div className={`transition-all duration-200 ${collapsed ? 'lg:pl-[72px]' : 'lg:pl-60'}`}>
        <Topbar
          collapsed={collapsed}
          onToggleCollapse={() => setCollapsed((v) => !v)}
          onOpenMobile={() => setMobileOpen(true)}
        />
        <main className="mx-auto w-full max-w-[1400px] px-3 py-4 md:px-6 md:py-6">
          <Breadcrumbs />
          <div className="mt-3">
            <Outlet />
          </div>
        </main>
      </div>
      <InstallPrompt />
      {!online && (
        <div className="no-print fixed bottom-4 left-1/2 z-50 -translate-x-1/2 rounded-full bg-slate-900 px-4 py-2 text-xs font-medium text-white shadow-lg dark:bg-slate-700">
          Offline — new sales will be queued and synced later
        </div>
      )}
    </div>
  );
}
