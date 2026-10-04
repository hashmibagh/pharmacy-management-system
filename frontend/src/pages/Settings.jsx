import React, { useEffect, useState } from 'react';
import { Building2, Cpu, ReceiptText, Palette, User, Lock } from 'lucide-react';
import api from '../api/client';
import { ENDPOINTS } from '../api/endpoints';
import { usePermissions } from '../hooks/usePermissions';
import { useTheme } from '../context/ThemeContext';
import LoadingSkeleton from '../components/LoadingSkeleton';

/**
 * Settings: pharmacy profile, system options, invoice layout,
 * appearance (theme), plus own profile + change password.
 */
export default function Settings() {
  const { can } = usePermissions();
  const { preference, setPreference } = useTheme();
  const [tab, setTab] = useState('pharmacy');
  const [settings, setSettings] = useState(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [toast, setToast] = useState('');

  // Profile / password
  const [profile, setProfile] = useState({ name: '', email: '', phone: '' });
  const [pw, setPw] = useState({ current_password: '', password: '', password_confirmation: '' });

  const showToast = (m) => {
    setToast(m);
    setTimeout(() => setToast(''), 3000);
  };

  useEffect(() => {
    (async () => {
      try {
        const [s, p] = await Promise.all([
          api.get(ENDPOINTS.settings),
          api.get(ENDPOINTS.auth.profile).catch(() => null),
        ]);
        setSettings(s.unwrapped?.data || {});
        const pd = p?.unwrapped?.data;
        if (pd) setProfile({ name: pd.name || '', email: pd.email || '', phone: pd.phone || '' });
      } catch (e) {
        setSettings({});
      } finally {
        setLoading(false);
      }
    })();
  }, []);

  const set = (path, value) => {
    setSettings((prev) => {
      const next = { ...(prev || {}) };
      const parts = path.split('.');
      let obj = next;
      for (let i = 0; i < parts.length - 1; i++) {
        obj[parts[i]] = { ...(obj[parts[i]] || {}) };
        obj = obj[parts[i]];
      }
      obj[parts[parts.length - 1]] = value;
      return next;
    });
  };

  const saveSettings = async () => {
    setSaving(true);
    try {
      await api.put(ENDPOINTS.settings, settings);
      showToast('Settings saved.');
    } catch (e) {
      showToast(e.message || 'Save failed.');
    } finally {
      setSaving(false);
    }
  };

  const saveProfile = async (e) => {
    e.preventDefault();
    try {
      await api.put(ENDPOINTS.auth.profile, profile);
      showToast('Profile updated.');
    } catch (err) {
      showToast(err.message || 'Update failed.');
    }
  };

  const changePassword = async (e) => {
    e.preventDefault();
    if (pw.password !== pw.password_confirmation) return showToast('New passwords do not match.');
    try {
      await api.post(ENDPOINTS.auth.changePassword, pw);
      setPw({ current_password: '', password: '', password_confirmation: '' });
      showToast('Password changed.');
    } catch (err) {
      showToast(err.message || 'Password change failed.');
    }
  };

  if (loading) return <LoadingSkeleton rows={5} />;

  const TABS = [
    { id: 'pharmacy', label: 'Pharmacy', icon: Building2 },
    { id: 'system', label: 'System', icon: Cpu },
    { id: 'invoice', label: 'Invoice', icon: ReceiptText },
    { id: 'appearance', label: 'Appearance', icon: Palette },
    { id: 'profile', label: 'My profile', icon: User },
    { id: 'password', label: 'Password', icon: Lock },
  ];

  const inp = 'h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800';
  const pharmacy = settings?.pharmacy || {};
  const system = settings?.system || {};
  const invoice = settings?.invoice || {};

  return (
    <div className="space-y-3">
      <h1 className="text-xl font-extrabold">Settings</h1>

      <div className="no-print flex gap-1 overflow-x-auto">
        {TABS.map((t) => (
          <button key={t.id} onClick={() => setTab(t.id)}
            className={`flex h-10 shrink-0 items-center gap-1.5 rounded-xl px-4 text-sm font-bold ${tab === t.id ? 'bg-brand-600 text-white' : 'bg-white text-slate-600 dark:bg-slate-900 dark:text-slate-300'}`}>
            <t.icon size={15} /> {t.label}
          </button>
        ))}
      </div>

      <div className="glass p-4">
        {tab === 'pharmacy' && (
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <F label="Pharmacy name"><input value={pharmacy.name || ''} onChange={(e) => set('pharmacy.name', e.target.value)} className={inp} /></F>
            <F label="Phone"><input value={pharmacy.phone || ''} onChange={(e) => set('pharmacy.phone', e.target.value)} className={inp} /></F>
            <F label="Email"><input value={pharmacy.email || ''} onChange={(e) => set('pharmacy.email', e.target.value)} className={inp} /></F>
            <F label="NTN / Tax no"><input value={pharmacy.tax_no || ''} onChange={(e) => set('pharmacy.tax_no', e.target.value)} className={inp} /></F>
            <div className="sm:col-span-2"><F label="Address"><textarea value={pharmacy.address || ''} onChange={(e) => set('pharmacy.address', e.target.value)} rows={2} className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" /></F></div>
            <div className="sm:col-span-2"><F label="Receipt footer text"><input value={pharmacy.receipt_footer || ''} onChange={(e) => set('pharmacy.receipt_footer', e.target.value)} className={inp} /></F></div>
          </div>
        )}

        {tab === 'system' && (
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <F label="Currency"><input value={system.currency || ''} onChange={(e) => set('system.currency', e.target.value)} placeholder="PKR" className={inp} /></F>
            <F label="Date format"><input value={system.date_format || ''} onChange={(e) => set('system.date_format', e.target.value)} placeholder="Y-m-d" className={inp} /></F>
            <F label="Low-stock threshold (days of cover)"><input value={system.low_stock_days || ''} onChange={(e) => set('system.low_stock_days', e.target.value)} inputMode="numeric" className={inp} /></F>
            <F label="Expiry alert window (days)"><input value={system.expiry_alert_days || ''} onChange={(e) => set('system.expiry_alert_days', e.target.value)} inputMode="numeric" className={inp} /></F>
            <Toggle label="Allow negative stock" value={!!system.allow_negative_stock} onChange={(v) => set('system.allow_negative_stock', v)} />
            <Toggle label="Require customer on credit sales" value={!!system.require_customer_on_credit} onChange={(v) => set('system.require_customer_on_credit', v)} />
          </div>
        )}

        {tab === 'invoice' && (
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <F label="Paper size">
              <select value={invoice.paper || 'a4'} onChange={(e) => set('invoice.paper', e.target.value)} className={inp}>
                <option value="a4">A4</option>
                <option value="thermal">Thermal 80mm</option>
              </select>
            </F>
            <F label="Invoice prefix"><input value={invoice.prefix || ''} onChange={(e) => set('invoice.prefix', e.target.value)} placeholder="INV-" className={inp} /></F>
            <Toggle label="Show logo on invoice" value={!!invoice.show_logo} onChange={(v) => set('invoice.show_logo', v)} />
            <Toggle label="Show tax breakdown" value={!!invoice.show_tax} onChange={(v) => set('invoice.show_tax', v)} />
            <Toggle label="Show barcode on receipt" value={!!invoice.show_barcode} onChange={(v) => set('invoice.show_barcode', v)} />
          </div>
        )}

        {tab === 'appearance' && (
          <div className="space-y-3">
            <p className="text-sm font-semibold">Theme</p>
            <div className="grid grid-cols-3 gap-2">
              {['light', 'dark', 'system'].map((p) => (
                <button key={p} onClick={() => setPreference(p)}
                  className={`h-12 rounded-xl border text-sm font-bold capitalize ${preference === p ? 'border-brand-600 bg-brand-600 text-white' : 'border-slate-200 dark:border-slate-700'}`}>
                  {p}
                </button>
              ))}
            </div>
            <p className="text-xs text-slate-500">Applies instantly to this device.</p>
          </div>
        )}

        {tab === 'profile' && (
          <form onSubmit={saveProfile} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <F label="Name"><input value={profile.name} onChange={(e) => setProfile((p) => ({ ...p, name: e.target.value }))} className={inp} /></F>
            <F label="Email"><input value={profile.email} onChange={(e) => setProfile((p) => ({ ...p, email: e.target.value }))} type="email" className={inp} /></F>
            <F label="Phone"><input value={profile.phone} onChange={(e) => setProfile((p) => ({ ...p, phone: e.target.value }))} inputMode="tel" className={inp} /></F>
            <div className="sm:col-span-2"><button type="submit" className="h-11 rounded-xl bg-brand-600 px-6 text-sm font-bold text-white">Update profile</button></div>
          </form>
        )}

        {tab === 'password' && (
          <form onSubmit={changePassword} className="max-w-sm space-y-3">
            <F label="Current password"><input type="password" value={pw.current_password} onChange={(e) => setPw((p) => ({ ...p, current_password: e.target.value }))} className={inp} autoComplete="current-password" /></F>
            <F label="New password"><input type="password" value={pw.password} onChange={(e) => setPw((p) => ({ ...p, password: e.target.value }))} className={inp} autoComplete="new-password" /></F>
            <F label="Confirm new password"><input type="password" value={pw.password_confirmation} onChange={(e) => setPw((p) => ({ ...p, password_confirmation: e.target.value }))} className={inp} autoComplete="new-password" /></F>
            <button type="submit" className="h-11 rounded-xl bg-brand-600 px-6 text-sm font-bold text-white">Change password</button>
          </form>
        )}

        {!['profile', 'password', 'appearance'].includes(tab) && can('settings.edit') && (
          <div className="mt-4 flex justify-end">
            <button onClick={saveSettings} disabled={saving} className="h-11 rounded-xl bg-brand-600 px-8 text-sm font-bold text-white disabled:opacity-50">
              {saving ? 'Saving…' : 'Save settings'}
            </button>
          </div>
        )}
      </div>

      {toast && (
        <div className="no-print fixed bottom-16 left-1/2 z-[70] -translate-x-1/2 rounded-full bg-slate-900 px-4 py-2 text-xs font-semibold text-white shadow-lg">{toast}</div>
      )}
    </div>
  );
}

function F({ label, children }) {
  return (
    <label className="block text-xs font-semibold text-slate-600 dark:text-slate-300">
      {label}
      <div className="mt-1">{children}</div>
    </label>
  );
}

function Toggle({ label, value, onChange }) {
  return (
    <label className="flex h-11 cursor-pointer items-center justify-between rounded-xl border border-slate-200 px-3 text-sm font-semibold dark:border-slate-700">
      {label}
      <input type="checkbox" checked={value} onChange={(e) => onChange(e.target.checked)} className="h-5 w-5 accent-brand-600" />
    </label>
  );
}
