import axios from 'axios';

/**
 * Central axios instance.
 *
 * - baseURL comes from Vite env: VITE_API_URL (default http://localhost:8000/api)
 * - JWT access token is attached from in-memory storage (see AuthContext);
 *   the refresh token lives in an httpOnly cookie managed by the backend,
 *   so we never touch it from JS.
 * - On 401 the request is retried once after POST auth/refresh (token rotation).
 * - All responses unwrap the backend envelope:
 *     { success: true,  message, data } -> resolves with `data`
 *     { success: false, message, errors } -> rejects with an Error carrying
 *       .errors and .status
 */

const BASE_URL = (import.meta.env.VITE_API_URL || 'http://localhost:8000/api').replace(/\/$/, '');

const api = axios.create({
  baseURL: BASE_URL,
  timeout: 30000,
  withCredentials: true, // send httpOnly refresh cookie
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
});

// Access token lives in memory only (module-level, set by AuthContext).
let accessToken = null;
export function setAccessToken(token) {
  accessToken = token;
}
export function getAccessToken() {
  return accessToken;
}

export function isOnline() {
  return typeof navigator !== 'undefined' ? navigator.onLine : true;
}

// Small offline change-event bus the app subscribes to.
const offlineListeners = new Set();
export function onConnectivityChange(fn) {
  offlineListeners.add(fn);
  return () => offlineListeners.delete(fn);
}
if (typeof window !== 'undefined') {
  window.addEventListener('online', () => offlineListeners.forEach((fn) => fn(true)));
  window.addEventListener('offline', () => offlineListeners.forEach((fn) => fn(false)));
}

api.interceptors.request.use((config) => {
  if (accessToken) {
    config.headers.Authorization = `Bearer ${accessToken}`;
  }
  return config;
});

let refreshPromise = null;

api.interceptors.response.use(
  (response) => {
    const payload = response.data;
    // Pass through non-envelope responses untouched.
    if (!payload || typeof payload.success === 'undefined') return response;
    if (payload.success) {
      // Attach message metadata for toasts without breaking call sites.
      response.unwrapped = { data: payload.data, message: payload.message };
      return response;
    }
    const err = new Error(payload.message || 'Request failed');
    err.status = response.status;
    err.errors = payload.errors || {};
    err.payload = payload;
    throw err;
  },
  async (error) => {
    const original = error.config || {};
    // Try a single token rotation on 401 (but not for the refresh call itself).
    if (
      error.response &&
      error.response.status === 401 &&
      !original._retry &&
      !original.url?.includes('auth/refresh') &&
      !original.url?.includes('auth/login')
    ) {
      original._retry = true;
      try {
        if (!refreshPromise) {
          refreshPromise = api
            .post('/auth/refresh')
            .then((res) => res.unwrapped?.data)
            .finally(() => {
              refreshPromise = null;
            });
        }
        const data = await refreshPromise;
        if (data?.access_token) setAccessToken(data.access_token);
        return api(original);
      } catch (refreshErr) {
        // Refresh failed -> caller (AuthContext) will log the user out.
        setAccessToken(null);
        window.dispatchEvent(new CustomEvent('auth:expired'));
        return Promise.reject(error);
      }
    }
    // Normalise backend envelope errors for direct .get/.post rejections too.
    if (error.response?.data && typeof error.response.data.success !== 'undefined') {
      const payload = error.response.data;
      const err = new Error(payload.message || 'Request failed');
      err.status = error.response.status;
      err.errors = payload.errors || {};
      err.payload = payload;
      throw err;
    }
    throw error;
  },
);

export const apiBaseUrl = BASE_URL;
export default api;
