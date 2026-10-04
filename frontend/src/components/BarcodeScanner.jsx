import React, { useEffect, useRef, useState } from 'react';
import { Camera, X, AlertCircle } from 'lucide-react';
import { Modal } from './Modal';

/**
 * Barcode/QR scanner stub using the native BarcodeDetector API
 * (Chrome on Android). If the API or a camera is unavailable, shows a
 * clear fallback message and lets the user type/scan into an input
 * (USB scanners act as keyboards).
 */
export default function BarcodeScanner({ open, onClose, onDetected }) {
  const [supported] = useState(() => 'BarcodeDetector' in window);
  const [error, setError] = useState('');
  const [manual, setManual] = useState('');
  const videoRef = useRef(null);
  const streamRef = useRef(null);
  const rafRef = useRef(null);

  useEffect(() => {
    if (!open) return;
    setError('');
    setManual('');
    let detector = null;
    let stopped = false;

    (async () => {
      if (!supported) {
        setError('This device does not support in-browser barcode scanning. Use a USB/Bluetooth scanner or type the code manually.');
        return;
      }
      try {
        detector = new window.BarcodeDetector({
          formats: ['qr_code', 'ean_13', 'ean_8', 'code_128', 'code_39', 'upc_a', 'upc_e'],
        });
        const stream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: 'environment' },
          audio: false,
        });
        streamRef.current = stream;
        if (videoRef.current) {
          videoRef.current.srcObject = stream;
          await videoRef.current.play().catch(() => {});
        }
        const tick = async () => {
          if (stopped) return;
          try {
            const codes = await detector.detect(videoRef.current);
            if (codes && codes.length > 0) {
              onDetected(codes[0].rawValue);
              onClose();
              return;
            }
          } catch (e) {
            /* keep scanning */
          }
          rafRef.current = requestAnimationFrame(() => setTimeout(tick, 300));
        };
        tick();
      } catch (e) {
        setError('Camera unavailable: ' + (e.message || 'permission denied') + '. You can still type the code manually.');
      }
    })();

    return () => {
      stopped = true;
      if (rafRef.current) cancelAnimationFrame(rafRef.current);
      if (streamRef.current) {
        streamRef.current.getTracks().forEach((t) => t.stop());
        streamRef.current = null;
      }
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, supported]);

  const submitManual = (e) => {
    e.preventDefault();
    if (manual.trim()) {
      onDetected(manual.trim());
      onClose();
    }
  };

  return (
    <Modal open={open} onClose={onClose} title="Scan barcode / QR">
      {!supported || error ? (
        <div className="flex items-start gap-3 rounded-xl bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
          <AlertCircle size={18} className="mt-0.5 shrink-0" />
          <p>{error || 'Barcode scanning is not supported on this device/browser.'}</p>
        </div>
      ) : (
        <div className="overflow-hidden rounded-xl bg-black">
          <video ref={videoRef} className="aspect-video w-full object-cover" playsInline muted />
          <p className="flex items-center justify-center gap-2 py-2 text-xs text-white/80">
            <Camera size={14} /> Point the camera at the barcode
          </p>
        </div>
      )}

      <form onSubmit={submitManual} className="mt-4 flex gap-2">
        <input
          value={manual}
          onChange={(e) => setManual(e.target.value)}
          placeholder="Type or scan code here…"
          inputMode="text"
          autoFocus
          className="h-12 flex-1 rounded-xl border border-slate-200 bg-white px-4 text-base dark:border-slate-700 dark:bg-slate-800"
        />
        <button type="submit" className="h-12 rounded-xl bg-brand-600 px-5 font-semibold text-white">
          Add
        </button>
      </form>
    </Modal>
  );
}

export function ScanButton({ onScan }) {
  const [open, setOpen] = useState(false);
  return (
    <>
      <button
        onClick={() => setOpen(true)}
        className="grid h-12 w-12 shrink-0 place-items-center rounded-xl border border-slate-200 text-slate-600 dark:border-slate-700 dark:text-slate-300"
        aria-label="Scan barcode"
        title="Scan barcode"
      >
        <Camera size={20} />
      </button>
      <BarcodeScanner open={open} onClose={() => setOpen(false)} onDetected={onScan} />
    </>
  );
}
