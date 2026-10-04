import React from 'react';
import { Sun, Moon, Monitor } from 'lucide-react';
import { useTheme } from '../context/ThemeContext';

/** Cycles light -> dark -> system. */
export default function ThemeToggle() {
  const { preference, setPreference } = useTheme();
  const cycle = () => {
    setPreference(preference === 'light' ? 'dark' : preference === 'dark' ? 'system' : 'light');
  };
  const Icon = preference === 'light' ? Sun : preference === 'dark' ? Moon : Monitor;
  return (
    <button
      onClick={cycle}
      className="rounded-xl p-2.5 text-slate-600 hover:bg-slate-200 dark:text-slate-300 dark:hover:bg-slate-800"
      aria-label={`Theme: ${preference}. Switch theme.`}
      title={`Theme: ${preference}`}
    >
      <Icon size={20} />
    </button>
  );
}
