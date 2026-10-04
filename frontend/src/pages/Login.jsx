import React, { useState } from 'react';
import { Navigate, useLocation, useNavigate } from 'react-router-dom';
import { Lock, Mail, Eye, EyeOff } from 'lucide-react';
import { useAuth } from '../context/AuthContext';
import { useOnlineStatus } from '../hooks/useOnlineStatus';

export default function Login() {
  const { isAuthenticated, login, busy, loading } = useAuth();
  const online = useOnlineStatus();
  const navigate = useNavigate();
  const location = useLocation();
  const from = location.state?.from || '/dashboard';

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPw, setShowPw] = useState(false);
  const [error, setError] = useState('');
  const [fieldErrors, setFieldErrors] = useState({});

  if (!loading && isAuthenticated) {
    return <Navigate to={from} replace />;
  }

  const submit = async (e) => {
    e.preventDefault();
    setError('');
    setFieldErrors({});
    const errs = {};
    if (!email.includes('@')) errs.email = 'Enter a valid email address.';
    if (!password) errs.password = 'Password is required.';
    setFieldErrors(errs);
    if (Object.keys(errs).length > 0) return;

    const res = await login(email.trim(), password);
    if (res.ok) navigate(from, { replace: true });
    else {
      setError(res.message || 'Login failed.');
      setFieldErrors(res.errors || {});
    }
  };

  return (
    <div className="flex min-h-screen items-center justify-center bg-gradient-to-br from-brand-600 via-brand-800 to-slate-950 p-4">
      <div className="glass w-full max-w-sm p-8">
        <div className="mb-6 text-center">
          <span className="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-brand-600 text-3xl text-white shadow-lg">
            💊
          </span>
          <h1 className="mt-4 text-2xl font-extrabold">Pharmacy Management</h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Sign in to your account</p>
        </div>

        {!online && (
          <div className="mb-4 rounded-xl bg-amber-100 px-3 py-2 text-center text-xs font-semibold text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
            You are offline — sign-in requires internet.
          </div>
        )}

        <form onSubmit={submit} className="space-y-4">
          <div>
            <label className="mb-1 block text-xs font-semibold text-slate-600 dark:text-slate-300">Email</label>
            <div className="relative">
              <Mail size={17} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
              <input
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="you@pharmacy.com"
                autoComplete="username"
                className="h-12 w-full rounded-xl border border-slate-200 bg-white pl-10 pr-3 text-sm outline-none focus:border-brand-500 dark:border-slate-700 dark:bg-slate-800"
              />
            </div>
            {fieldErrors.email && <p className="mt-1 text-xs text-red-500">{fieldErrors.email}</p>}
          </div>
          <div>
            <label className="mb-1 block text-xs font-semibold text-slate-600 dark:text-slate-300">Password</label>
            <div className="relative">
              <Lock size={17} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
              <input
                type={showPw ? 'text' : 'password'}
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="••••••••"
                autoComplete="current-password"
                className="h-12 w-full rounded-xl border border-slate-200 bg-white pl-10 pr-11 text-sm outline-none focus:border-brand-500 dark:border-slate-700 dark:bg-slate-800"
              />
              <button
                type="button"
                onClick={() => setShowPw((v) => !v)}
                className="absolute right-2 top-1/2 -translate-y-1/2 rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700"
                aria-label={showPw ? 'Hide password' : 'Show password'}
              >
                {showPw ? <EyeOff size={17} /> : <Eye size={17} />}
              </button>
            </div>
            {fieldErrors.password && <p className="mt-1 text-xs text-red-500">{fieldErrors.password}</p>}
          </div>

          {error && (
            <p className="rounded-xl bg-red-50 px-3 py-2 text-center text-sm text-red-600 dark:bg-red-950/50">
              {error}
            </p>
          )}

          <button
            type="submit"
            disabled={busy}
            className="h-12 w-full rounded-xl bg-brand-600 text-sm font-bold text-white hover:bg-brand-700 disabled:opacity-60"
          >
            {busy ? 'Signing in…' : 'Sign in'}
          </button>
        </form>
      </div>
    </div>
  );
}
