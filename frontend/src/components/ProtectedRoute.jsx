import React from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { ShieldAlert } from 'lucide-react';
import { useAuth } from '../context/AuthContext';
import { usePermissions } from '../hooks/usePermissions';
import LoadingSkeleton from './LoadingSkeleton';

/**
 * Route guard:
 *  - not authenticated -> /login (remembering where they were headed)
 *  - missing required `permission` -> "no access" panel
 */
export default function ProtectedRoute({ children, permission }) {
  const { isAuthenticated, loading } = useAuth();
  const { can } = usePermissions();
  const location = useLocation();

  if (loading) {
    return (
      <div className="p-6">
        <LoadingSkeleton rows={5} />
      </div>
    );
  }
  if (!isAuthenticated) {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />;
  }
  if (!can(permission)) {
    return (
      <div className="mx-auto mt-16 max-w-md rounded-2xl border border-slate-200 bg-white p-8 text-center dark:border-slate-700 dark:bg-slate-900">
        <ShieldAlert size={40} className="mx-auto mb-3 text-amber-500" />
        <h2 className="text-lg font-bold">Access denied</h2>
        <p className="mt-1 text-sm text-slate-500">
          Your role does not include the <code>{permission}</code> permission.
        </p>
      </div>
    );
  }
  return children;
}
