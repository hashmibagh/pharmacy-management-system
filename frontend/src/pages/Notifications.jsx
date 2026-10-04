import React, { useCallback, useEffect, useState } from 'react';
import { Bell, CheckCheck, Trash2 } from 'lucide-react';
import api from '../api/client';
import { ENDPOINTS } from '../api/endpoints';
import LoadingSkeleton from '../components/LoadingSkeleton';
import EmptyState from '../components/EmptyState';

/** Notifications: list, mark read, clear-all (client-side hide of read ones). */
export default function Notifications() {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState('all'); // all | unread

  const fetchRows = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get(ENDPOINTS.notifications.list, { params: { per_page: 50 } });
      const d = res.unwrapped?.data;
      setRows(d?.data || d || []);
    } catch (e) {
      setRows([]);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchRows();
  }, [fetchRows]);

  const markRead = async (n) => {
    try {
      await api.put(ENDPOINTS.notifications.read(n.id));
      setRows((prev) => prev.map((x) => (x.id === n.id ? { ...x, read_at: new Date().toISOString() } : x)));
    } catch (e) {
      /* ignore */
    }
  };

  const markAllRead = async () => {
    const unread = rows.filter((r) => !r.read_at);
    for (const n of unread) {
      try {
        // eslint-disable-next-line no-await-in-loop
        await api.put(ENDPOINTS.notifications.read(n.id));
      } catch (e) {
        /* ignore */
      }
    }
    setRows((prev) => prev.map((x) => ({ ...x, read_at: x.read_at || new Date().toISOString() })));
  };

  const visible = filter === 'unread' ? rows.filter((r) => !r.read_at) : rows;
  const unreadCount = rows.filter((r) => !r.read_at).length;

  if (loading) return <LoadingSkeleton rows={5} />;

  return (
    <div className="mx-auto max-w-2xl space-y-3">
      <div className="no-print flex items-center gap-2">
        <h1 className="mr-auto text-xl font-extrabold">Notifications</h1>
        {unreadCount > 0 && (
          <button onClick={markAllRead} className="flex h-10 items-center gap-1.5 rounded-xl border border-slate-200 px-3 text-xs font-bold dark:border-slate-700">
            <CheckCheck size={15} /> Mark all read
          </button>
        )}
      </div>

      <div className="no-print flex gap-1">
        {['all', 'unread'].map((f) => (
          <button key={f} onClick={() => setFilter(f)}
            className={`h-10 rounded-xl px-4 text-sm font-bold capitalize ${filter === f ? 'bg-brand-600 text-white' : 'bg-white text-slate-600 dark:bg-slate-900 dark:text-slate-300'}`}>
            {f} {f === 'unread' && unreadCount > 0 && `(${unreadCount})`}
          </button>
        ))}
      </div>

      {visible.length === 0 ? (
        <EmptyState icon={Bell} title="No notifications" hint={filter === 'unread' ? 'You are all caught up.' : 'Alerts about stock, expiry and payments will appear here.'} />
      ) : (
        <ul className="space-y-2">
          {visible.map((n) => (
            <li key={n.id}
              className={`glass flex items-start gap-3 p-3 ${n.read_at ? 'opacity-70' : ''}`}>
              <span className={`grid h-10 w-10 shrink-0 place-items-center rounded-xl ${n.read_at ? 'bg-slate-100 text-slate-400 dark:bg-slate-800' : 'bg-brand-100 text-brand-600 dark:bg-brand-900/40'}`}>
                <Bell size={18} />
              </span>
              <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold">{n.title || n.type || 'Notification'}</p>
                <p className="text-xs text-slate-500">{n.body || n.message || n.data?.message || ''}</p>
                <p className="mt-0.5 text-[11px] text-slate-400">{String(n.created_at || '').slice(0, 16).replace('T', ' ')}</p>
              </div>
              {!n.read_at && (
                <button onClick={() => markRead(n)} className="rounded-lg p-2 text-brand-600 hover:bg-brand-50 dark:hover:bg-slate-800" title="Mark as read">
                  <CheckCheck size={17} />
                </button>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
