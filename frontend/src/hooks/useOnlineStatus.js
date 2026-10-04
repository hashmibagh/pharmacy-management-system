import { useEffect, useState } from 'react';
import { onConnectivityChange, isOnline } from '../api/client';

/** Reactive online/offline flag for status badges and sync UI. */
export function useOnlineStatus() {
  const [online, setOnline] = useState(isOnline());
  useEffect(() => onConnectivityChange(setOnline), []);
  return online;
}
