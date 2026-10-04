import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';
import { fileURLToPath } from 'url';

// PWA-friendly build: assets + a copy of the manifest/service worker
// that live in `public/` are copied verbatim into dist/.
// The service worker is registered manually in src/main.jsx.

const __dirname = path.dirname(fileURLToPath(import.meta.url));

export default defineConfig({
  plugins: [react()],
  build: {
    outDir: 'dist',
    sourcemap: false,
    rollupOptions: {
      output: {
        // Keep entry chunk names stable so the service worker's
        // precache list can be generated predictably at runtime.
        entryFileNames: 'assets/[name].[hash].js',
        chunkFileNames: 'assets/[name].[hash].js',
        assetFileNames: 'assets/[name].[hash].[ext]',
      },
    },
  },
  server: {
    port: 5173,
    host: true,
  },
  preview: {
    port: 4173,
  },
});
