import { useAuth } from '../context/AuthContext';

/**
 * Permission helpers reading the user object's permissions array.
 * Super-admins (role === 'admin' or permission '*') pass everything.
 */
export function usePermissions() {
  const { user } = useAuth();
  const perms = user?.permissions || [];

  const can = (permission) => {
    if (!permission) return true;
    if (user?.role === 'admin') return true;
    if (perms.includes('*')) return true;
    return perms.includes(permission);
  };

  const canAny = (...permissions) => permissions.some(can);

  return { can, canAny, permissions: perms };
}
