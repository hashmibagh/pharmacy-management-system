import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import api, { setAccessToken } from '../api/client';
import { ENDPOINTS } from '../api/endpoints';

/**
 * Auth state: JWT login flow.
 * - access token kept in memory only (module state in api/client.js)
 * - refresh token lives in an httpOnly cookie managed by the backend
 * - on load: try a silent refresh, then fetch /auth/me
 * - on 'auth:expired' (refresh failed) -> force logout
 */

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true); // initial session check
  const [busy, setBusy] = useState(false); // login/logout in flight

  const fetchMe = useCallback(async () => {
    const res = await api.get(ENDPOINTS.auth.me);
    const me = res.unwrapped?.data;
    setUser(me || null);
    return me;
  }, []);

  // Silent session restore on app start.
  useEffect(() => {
    (async () => {
      try {
        const res = await api.post(ENDPOINTS.auth.refresh);
        const token = res.unwrapped?.data?.access_token;
        if (token) setAccessToken(token);
        await fetchMe();
      } catch (e) {
        setUser(null);
        setAccessToken(null);
      } finally {
        setLoading(false);
      }
    })();
  }, [fetchMe]);

  // Forced logout when token rotation fails anywhere in the app.
  useEffect(() => {
    const onExpired = () => {
      setAccessToken(null);
      setUser(null);
    };
    window.addEventListener('auth:expired', onExpired);
    return () => window.removeEventListener('auth:expired', onExpired);
  }, []);

  const login = useCallback(
    async (email, password) => {
      setBusy(true);
      try {
        const res = await api.post(ENDPOINTS.auth.login, { email, password });
        const payload = res.unwrapped?.data || {};
        if (payload.access_token) setAccessToken(payload.access_token);
        const me = payload.user || (await fetchMe());
        setUser(me);
        return { ok: true, user: me, message: res.unwrapped?.message };
      } catch (err) {
        return { ok: false, message: err.message, errors: err.errors || {} };
      } finally {
        setBusy(false);
      }
    },
    [fetchMe],
  );

  const logout = useCallback(async () => {
    try {
      await api.post(ENDPOINTS.auth.logout);
    } catch (e) {
      /* still clear local state */
    }
    setAccessToken(null);
    setUser(null);
  }, []);

  const value = useMemo(
    () => ({ user, loading, busy, login, logout, refreshUser: fetchMe, isAuthenticated: !!user }),
    [user, loading, busy, login, logout, fetchMe],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside <AuthProvider>');
  return ctx;
}
