import { useCallback, useMemo } from 'react';
import { useLocation, useNavigate, useSearchParams as useRouterSearchParams } from 'react-router-dom';

export { useParams, Outlet } from 'react-router-dom';

/**
 * Lapisan kompatibilitas untuk menggantikan `next/navigation`.
 * Mempertahankan bentuk panggilan useRouter() yang dipakai project lama
 * (push, replace, back, refresh) agar perilaku navigasi tidak berubah.
 */
export function useRouter() {
  const navigate = useNavigate();

  const push = useCallback((to: any, options?: any) => navigate(to, options as any), [navigate]);
  const replace = useCallback((to: any, options?: any) => navigate(to, { ...(options as any), replace: true }), [navigate]);
  const back = useCallback(() => navigate(-1), [navigate]);
  const forward = useCallback(() => navigate(1), [navigate]);
  const refresh = useCallback(() => navigate(0), [navigate]);
  const prefetch = useCallback(() => {}, []);

  return useMemo(() => ({ push, replace, back, forward, refresh, prefetch }), [push, replace, back, forward, refresh, prefetch]);
}

export function usePathname() {
  return useLocation().pathname;
}

/**
 * Next.js mengembalikan objek ReadonlyURLSearchParams (bukan tuple seperti
 * React Router). Kembalikan elemen pertama agar pemanggilan `.get()` lama
 * tetap berfungsi.
 */
export function useSearchParams() {
  const [params] = useRouterSearchParams();
  return params;
}
