import React, { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Printer, Truck, Phone, Wallet } from 'lucide-react';
import api from '../api/client';
import { ENDPOINTS } from '../api/endpoints';
import LoadingSkeleton from '../components/LoadingSkeleton';
import EmptyState from '../components/EmptyState';
import StatCard from '../components/StatCard';
import DataTable from '../components/DataTable';

/** Supplier detail: profile, purchase history, payable ledger. */
export default function SupplierDetail() {
  const { id } = useParams();
  const [supplier, setSupplier] = useState(null);
  const [ledger, setLedger] = useState([]);
  const [purchases, setPurchases] = useState([]);
  const [loading, setLoading] = useState(true);

  const fetchAll = useCallback(async () => {
    setLoading(true);
    try {
      const [s, l] = await Promise.all([
        api.get(ENDPOINTS.suppliers.detail(id)),
        api.get(ENDPOINTS.suppliers.ledger(id)).catch(() => null),
      ]);
      const sd = s.unwrapped?.data;
      setSupplier(sd || null);
      const ld = l?.unwrapped?.data;
      setLedger(ld?.data || ld || []);
      setPurchases(sd?.purchases || []);
    } catch (e) {
      setSupplier(null);
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => {
    fetchAll();
  }, [fetchAll]);

  if (loading) return <LoadingSkeleton rows={6} />;
  if (!supplier) return <EmptyState title="Supplier not found" />;

  const ledgerCols = [
    { key: 'date', label: 'Date', render: (r) => String(r.date || r.created_at || '').slice(0, 10) },
    { key: 'description', label: 'Description', render: (r) => r.description || r.type || '—' },
    { key: 'debit', label: 'Debit', render: (r) => Number(r.debit || 0).toLocaleString(), className: 'text-right' },
    { key: 'credit', label: 'Credit', render: (r) => Number(r.credit || 0).toLocaleString(), className: 'text-right' },
    { key: 'balance', label: 'Balance', render: (r) => <span className="font-bold">{Number(r.balance || 0).toLocaleString()}</span>, className: 'text-right' },
  ];

  return (
    <div className="space-y-3">
      <div className="no-print flex items-center gap-2">
        <Link to="/suppliers" className="rounded-xl p-2 hover:bg-slate-200 dark:hover:bg-slate-800" aria-label="Back">
          <ArrowLeft size={18} />
        </Link>
        <h1 className="mr-auto text-xl font-extrabold">{supplier.name}</h1>
        <button onClick={() => window.print()} className="flex h-10 items-center gap-1.5 rounded-xl bg-brand-600 px-4 text-xs font-bold text-white">
          <Printer size={15} /> Print ledger
        </button>
      </div>

      <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
        <div className="glass space-y-2 p-4">
          <h2 className="flex items-center gap-2 text-sm font-bold"><Truck size={16} /> Profile</h2>
          <p className="text-sm text-slate-500">{supplier.contact_person || ''}</p>
          <p className="flex items-center gap-2 text-sm"><Phone size={14} className="text-slate-400" /> {supplier.phone || '—'}</p>
          <p className="text-sm text-slate-500">{supplier.email || ''}</p>
          <p className="text-sm text-slate-500">{supplier.address || ''}</p>
          {supplier.notes && <p className="text-xs text-slate-500">Note: {supplier.notes}</p>}
        </div>
        <StatCard icon={Wallet} label="Payable (we owe)" value={Number(supplier.due ?? supplier.balance ?? 0).toLocaleString()} tone="red" />
        <StatCard icon={Truck} label="Total purchases" value={(purchases || []).length} tone="brand" />
      </div>

      <div className="print-area glass p-4">
        <h2 className="mb-2 text-sm font-bold">Ledger</h2>
        <DataTable columns={ledgerCols} data={ledger} emptyText="No ledger entries." />
      </div>

      <div className="no-print glass p-4">
        <h2 className="mb-2 text-sm font-bold">Purchase history</h2>
        {purchases.length === 0 ? (
          <p className="text-xs text-slate-500">No purchases from this supplier.</p>
        ) : (
          <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
            {purchases.map((p) => (
              <li key={p.id} className="flex items-center justify-between py-2">
                <Link to={`/purchases/${p.id}`} className="font-mono font-semibold text-brand-600">{p.invoice_no || `#${p.id}`}</Link>
                <span className="text-xs text-slate-500">{String(p.date || p.created_at || '').slice(0, 10)}</span>
                <span className="font-bold">{Number(p.total ?? 0).toLocaleString()}</span>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
