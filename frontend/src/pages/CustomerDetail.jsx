import React, { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Printer, User, Phone, Wallet, History } from 'lucide-react';
import api from '../api/client';
import { ENDPOINTS } from '../api/endpoints';
import LoadingSkeleton from '../components/LoadingSkeleton';
import EmptyState from '../components/EmptyState';
import StatCard from '../components/StatCard';
import DataTable from '../components/DataTable';

/** Customer profile: info, purchase history, ledger, printable statement. */
export default function CustomerDetail() {
  const { id } = useParams();
  const [customer, setCustomer] = useState(null);
  const [ledger, setLedger] = useState([]);
  const [purchases, setPurchases] = useState([]);
  const [loading, setLoading] = useState(true);

  const fetchAll = useCallback(async () => {
    setLoading(true);
    try {
      const [c, l] = await Promise.all([
        api.get(ENDPOINTS.customers.detail(id)),
        api.get(ENDPOINTS.customers.ledger(id)).catch(() => null),
      ]);
      const cd = c.unwrapped?.data;
      setCustomer(cd || null);
      const ld = l?.unwrapped?.data;
      setLedger(ld?.data || ld || []);
      setPurchases(cd?.sales || cd?.purchases || []);
    } catch (e) {
      setCustomer(null);
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => {
    fetchAll();
  }, [fetchAll]);

  if (loading) return <LoadingSkeleton rows={6} />;
  if (!customer) return <EmptyState title="Customer not found" />;

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
        <Link to="/customers" className="rounded-xl p-2 hover:bg-slate-200 dark:hover:bg-slate-800" aria-label="Back">
          <ArrowLeft size={18} />
        </Link>
        <h1 className="mr-auto text-xl font-extrabold">{customer.name}</h1>
        <button onClick={() => window.print()} className="flex h-10 items-center gap-1.5 rounded-xl bg-brand-600 px-4 text-xs font-bold text-white">
          <Printer size={15} /> Print statement
        </button>
      </div>

      <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
        <div className="print-area glass space-y-2 p-4">
          <h2 className="flex items-center gap-2 text-sm font-bold"><User size={16} /> Profile</h2>
          <p className="flex items-center gap-2 text-sm"><Phone size={14} className="text-slate-400" /> {customer.phone || '—'}</p>
          <p className="text-sm text-slate-500">{customer.email || ''}</p>
          <p className="text-sm text-slate-500">{customer.address || ''}</p>
          {customer.notes && <p className="text-xs text-slate-500">Note: {customer.notes}</p>}
        </div>
        <StatCard icon={Wallet} label="Outstanding due" value={Number(customer.due ?? customer.balance ?? 0).toLocaleString()} tone="amber" />
        <StatCard icon={History} label="Credit limit" value={customer.credit_limit ? Number(customer.credit_limit).toLocaleString() : '—'} tone="brand" />
      </div>

      <div className="print-area glass p-4">
        <h2 className="mb-2 text-sm font-bold">Ledger</h2>
        <DataTable columns={ledgerCols} data={ledger} emptyText="No ledger entries." />
      </div>

      <div className="no-print glass p-4">
        <h2 className="mb-2 text-sm font-bold">Purchase history</h2>
        {purchases.length === 0 ? (
          <p className="text-xs text-slate-500">No purchases recorded.</p>
        ) : (
          <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-800">
            {purchases.map((p) => (
              <li key={p.id} className="flex items-center justify-between py-2">
                <Link to={`/sales/${p.id}`} className="font-mono font-semibold text-brand-600">{p.invoice_no || `#${p.id}`}</Link>
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
