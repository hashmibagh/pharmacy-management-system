import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App';
import './index.css';

// Register the hand-rolled service worker for offline/PWA support.
// In dev (vite serve) the SW is served from public/ as well.
if ('serviceWorker' in navigator && import.meta.env.PROD) {
  window.addEventListener('load', () => {
    navigator.serviceWorker
      .register(import.meta.env.BASE_URL + 'service-worker.js')
      .catch((err) => console.warn('SW registration failed:', err));
  });
} else if ('serviceWorker' in navigator) {
  // In development also register so offline flows can be tested,
  // but tolerate failures (e.g. stale caches).
  navigator.serviceWorker
    .register(import.meta.env.BASE_URL + 'service-worker.js')
    .catch(() => {});
}

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>,
);
