import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  Banknote, TrendingUp, CalendarDays, Wallet, Pill, AlertTriangle,
  XCircle, Hourglass, History, Users, UserCheck, Truck, ArrowRight,
} from 'lucide-react';
import {
  ResponsiveContainer, AreaChart, Area, XAxis, YAxis, CartesianGrid,
  Tooltip, BarChart, Bar, PieChart, Pie, Cell, Legend,
} from 'recharts';
import api from '../api/client';
import StatCard from '../components/StatCard';
import LoadingSkeleton from '../components/LoadingSkeleton';
import EmptyState from '../components/EmptyState';

const PIE_COLORS = ['#3497ec', '#10b981', '#f59e0b', '#8b5cf6', '#ef4444', '#06b6d4'];

function fmt(n) {
  const v = Number(n || 0);
  return v.toLocaleString(undefined, { maximumFractionDigits: 0 });
}

export default function Dashboard() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        // Backend convention: /api/dashboard returns all KPI blocks at once.
        // Fallback to /reports/custom?summary=1 if the route differs.
        let res;
        try {
          res = await api.get('/dashboard');
        } catch (e) {
          res = await api.get('/reports/custom', { params: { summary: 'dashboard' } });
        }
        if (!cancelled) setData(res.unwrapped?.data || {});
      } catch (e) {
        if (!cancelled) setError(e.message || 'Could not load dashboard.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  if (loading) return <LoadingSkeleton rows={6} />;
  if (error) {
    return (
      <EmptyState
        title="Could not load dashboard"
        hint={error}
        action={
          <button onClick={() => window.location.reload()} className="h-10 rounded-xl bg-brand-600 px-4 text-sm font-semibold text-white">
            Retry
          </button>
        }
      />
    );
  }

  const sales = data.sales || {};
  const inv = data.inventory || {};
  const cust = data.customers || {};
  const supp = data.suppliers || {};

  const sales30 = (data.sales_last_30_days || []).map((d) => ({
    date: d.date?.slice(5) || '',
    sales: Number(d.total || 0),
    profit: Number(d.profit || 0),
  }));
  const monthly = (data.monthly_comparison || []).map((m) => ({
    month: m.month || '',
    sales: Number(m.sales || 0),
    purchases: Number(m.purchases || 0),
  }));
  const byCategory = (data.sales_by_category || []).map((c) => ({
    name: c.category || 'Other',
    value: Number(c.total || 0),
  }));
  const byPayment = (data.sales_by_payment_method || []).map((p) => ({
    name: p.method || 'Cash',
    value: Number(p.total || 0),
  }));

  return (
    <div className="space-y-4">
      {/* Sales KPIs */}
      <section>
        <h2 className="mb-2 text-sm font-bold uppercase tracking-wide text-slate-500">Sales</h2>
        <div className="grid grid-cols-2 gap-3 xl:grid-cols-5">
          <StatCard icon={Banknote} label="Today's sales" value={fmt(sales.today)} tone="green" />
          <StatCard icon={TrendingUp} label="Today's profit" value={fmt(sales.today_profit)} tone="green" />
          <StatCard icon={CalendarDays} label="This month sales" value={fmt(sales.month)} tone="brand" />
          <StatCard icon={TrendingUp} label="This month profit" value={fmt(sales.month_profit)} tone="brand" />
          <StatCard icon={Hourglass} label="Pending payments" value={fmt(sales.pending_payments)} tone="amber" />
        </div>
      </section>

      {/* Inventory KPIs */}
      <section>
        <h2 className="mb-2 text-sm font-bold uppercase tracking-wide text-slate-500">Inventory</h2>
        <div className="grid grid-cols-2 gap-3 xl:grid-cols-6">
          <StatCard icon={Pill} label="Total medicines" value={fmt(inv.total)} tone="brand" />
          <StatCard icon={AlertTriangle} label="Low stock" value={fmt(inv.low_stock)} tone="amber" />
          <StatCard icon={XCircle} label="Out of stock" value={fmt(inv.out_of_stock)} tone="red" />
          <StatCard icon={Hourglass} label="Expiring soon" value={fmt(inv.expiring_soon)} tone="amber" />
          <StatCard icon={XCircle} label="Expired" value={fmt(inv.expired)} tone="red" />
          <StatCard icon={Wallet} label="Inventory value" value={fmt(inv.value)} tone="violet" />
        </div>
      </section>

      {/* Customers & suppliers */}
      <section>
        <h2 className="mb-2 text-sm font-bold uppercase tracking-wide text-slate-500">People</h2>
        <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
          <StatCard icon={Users} label="Total customers" value={fmt(cust.total)} tone="brand" />
          <StatCard icon={Wallet} label="Customer dues" value={fmt(cust.due)} tone="amber" />
          <StatCard icon={Truck} label="Total suppliers" value={fmt(supp.total)} tone="brand" />
          <StatCard icon={Wallet} label="Supplier dues" value={fmt(supp.due)} tone="red" />
        </div>
      </section>

      {/* Charts */}
      <section className="grid grid-cols-1 gap-3 xl:grid-cols-2">
        <div className="glass p-4">
          <h3 className="mb-3 text-sm font-bold">Sales — last 30 days</h3>
          {sales30.length ? (
            <ResponsiveContainer width="100%" height={260}>
              <AreaChart data={sales30}>
                <CartesianGrid strokeDasharray="3 3" opacity={0.3} />
                <XAxis dataKey="date" tick={{ fontSize: 11 }} interval="preserveStartEnd" />
                <YAxis tick={{ fontSize: 11 }} />
                <Tooltip />
                <Legend />
                <Area type="monotone" dataKey="sales" stroke="#1e7bd2" fill="#3497ec" fillOpacity={0.3} name="Sales" />
                <Area type="monotone" dataKey="profit" stroke="#10b981" fill="#10b981" fillOpacity={0.2} name="Profit" />
              </AreaChart>
            </ResponsiveContainer>
          ) : (
            <EmptyState title="No sales data" hint="Sales for the last 30 days will appear here." />
          )}
        </div>

        <div className="glass p-4">
          <h3 className="mb-3 text-sm font-bold">Monthly comparison</h3>
          {monthly.length ? (
            <ResponsiveContainer width="100%" height={260}>
              <BarChart data={monthly}>
                <CartesianGrid strokeDasharray="3 3" opacity={0.3} />
                <XAxis dataKey="month" tick={{ fontSize: 11 }} />
                <YAxis tick={{ fontSize: 11 }} />
                <Tooltip />
                <Legend />
                <Bar dataKey="sales" fill="#1e7bd2" name="Sales" radius={[4, 4, 0, 0]} />
                <Bar dataKey="purchases" fill="#f59e0b" name="Purchases" radius={[4, 4, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          ) : (
            <EmptyState title="No monthly data" hint="Monthly sales vs purchases will appear here." />
          )}
        </div>

        <div className="glass p-4">
          <h3 className="mb-3 text-sm font-bold">Sales by category</h3>
          {byCategory.length ? (
            <ResponsiveContainer width="100%" height={260}>
              <PieChart>
                <Pie data={byCategory} dataKey="value" nameKey="name" outerRadius={90} label>
                  {byCategory.map((_, i) => (
                    <Cell key={i} fill={PIE_COLORS[i % PIE_COLORS.length]} />
                  ))}
                </Pie>
                <Tooltip />
                <Legend />
              </PieChart>
            </ResponsiveContainer>
          ) : (
            <EmptyState title="No category data" hint="Category-wise sales will appear here." />
          )}
        </div>

        <div className="glass p-4">
          <h3 className="mb-3 text-sm font-bold">Payment methods</h3>
          {byPayment.length ? (
            <ResponsiveContainer width="100%" height={260}>
              <PieChart>
                <Pie data={byPayment} dataKey="value" nameKey="name" innerRadius={55} outerRadius={90} label>
                  {byPayment.map((_, i) => (
                    <Cell key={i} fill={PIE_COLORS[i % PIE_COLORS.length]} />
                  ))}
                </Pie>
                <Tooltip />
                <Legend />
              </PieChart>
            </ResponsiveContainer>
          ) : (
            <EmptyState title="No payment data" hint="Payment-method breakdown will appear here." />
          )}
        </div>
      </section>

      {/* Recent lists */}
      <section className="grid grid-cols-1 gap-3 xl:grid-cols-2">
        <div className="glass p-4">
          <div className="mb-2 flex items-center justify-between">
            <h3 className="flex items-center gap-2 text-sm font-bold"><UserCheck size={16} /> Recent customers</h3>
            <Link to="/customers" className="flex items-center gap-1 text-xs font-semibold text-brand-600">
              View all <ArrowRight size={14} />
            </Link>
          </div>
          <ul className="divide-y divide-slate-100 dark:divide-slate-800">
            {(cust.recent || []).map((c) => (
              <li key={c.id} className="flex items-center justify-between py-2 text-sm">
                <span className="font-medium">{c.name}</span>
                {Number(c.due || 0) > 0 && <span className="text-xs font-semibold text-amber-600">Due {fmt(c.due)}</span>}
              </li>
            ))}
            {!(cust.recent || []).length && <li className="py-3 text-xs text-slate-500">No recent customers.</li>}
          </ul>
        </div>
        <div className="glass p-4">
          <div className="mb-2 flex items-center justify-between">
            <h3 className="flex items-center gap-2 text-sm font-bold"><History size={16} /> Expiring medicines</h3>
            <Link to="/inventory" className="flex items-center gap-1 text-xs font-semibold text-brand-600">
              View all <ArrowRight size={14} />
            </Link>
          </div>
          <ul className="divide-y divide-slate-100 dark:divide-slate-800">
            {(inv.expiring_list || []).map((m) => (
              <li key={m.id} className="flex items-center justify-between py-2 text-sm">
                <span className="font-medium">{m.name}</span>
                <span className="text-xs text-amber-600">Exp {m.expiry_date}</span>
              </li>
            ))}
            {!(inv.expiring_list || []).length && <li className="py-3 text-xs text-slate-500">Nothing expiring soon.</li>}
          </ul>
        </div>
      </section>
    </div>
  );
}
