import React, { createContext, useContext, useEffect, useMemo, useState } from 'react';

const ThemeContext = createContext(null);
const STORAGE_KEY = 'pharmacy-theme'; // 'light' | 'dark' | 'system'

function resolveTheme(pref) {
  if (pref === 'light' || pref === 'dark') return pref;
  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

export function ThemeProvider({ children }) {
  const [preference, setPreference] = useState(
    () => localStorage.getItem(STORAGE_KEY) || 'system',
  );

  useEffect(() => {
    const resolved = resolveTheme(preference);
    document.documentElement.classList.toggle('dark', resolved === 'dark');
    document
      .querySelector('meta[name="theme-color"]')
      ?.setAttribute('content', resolved === 'dark' ? '#0f172a' : '#1e7bd2');
  }, [preference]);

  // Follow OS changes while in "system" mode.
  useEffect(() => {
    if (preference !== 'system') return;
    const mq = window.matchMedia('(prefers-color-scheme: dark)');
    const onChange = () => {
      document.documentElement.classList.toggle('dark', mq.matches);
    };
    mq.addEventListener('change', onChange);
    return () => mq.removeEventListener('change', onChange);
  }, [preference]);

  const value = useMemo(
    () => ({
      preference,
      setPreference: (p) => {
        localStorage.setItem(STORAGE_KEY, p);
        setPreference(p);
      },
      resolved: resolveTheme(preference),
    }),
    [preference],
  );

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export function useTheme() {
  const ctx = useContext(ThemeContext);
  if (!ctx) throw new Error('useTheme must be used inside <ThemeProvider>');
  return ctx;
}
