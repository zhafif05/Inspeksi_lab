import axios, { type AxiosAdapter, type AxiosResponse } from 'axios';

const API_BASE = import.meta.env.VITE_API_URL || 'http://localhost:8000/api'; // ganti IP kalau backend di laptop lain

const api = axios.create({
  baseURL: API_BASE,
});

// Gabungkan GET identik yang sedang berjalan (StrictMode, context + halaman,
// refreshNotifications + fetchData) tanpa menyimpan cache antar-request.
const inFlightGets = new Map<string, Promise<AxiosResponse>>();
const defaultAdapter = api.defaults.adapter as NonNullable<typeof api.defaults.adapter>;

if (defaultAdapter) {
  // axios 1.x menyimpan adapter sebagai array nama ('xhr','http','fetch'),
  // jadi harus di-resolve lewat getAdapter() sebelum dipanggil.
  const runAdapter: AxiosAdapter = (config) =>
    (typeof defaultAdapter === 'function' ? defaultAdapter : axios.getAdapter(defaultAdapter, config))(config);

  api.defaults.adapter = (config) => {
    const method = (config.method || 'get').toLowerCase();
    if (method !== 'get' || config.responseType === 'blob') {
      return runAdapter(config);
    }

    const auth = (config.headers?.Authorization as string | undefined) ?? '';
    const key = [
      config.baseURL ?? '',
      config.url ?? '',
      config.params ? JSON.stringify(config.params) : '',
      auth,
    ].join('|');

    const existing = inFlightGets.get(key);
    if (existing) return existing;

    const pending = runAdapter(config);
    inFlightGets.set(key, pending);
    pending.then(
      () => inFlightGets.delete(key),
      () => inFlightGets.delete(key)
    );
    return pending;
  };
}

export function fotoUrl(path?: string): string | undefined {
  if (!path) return undefined;
  if (path.startsWith('http')) return path;
  const base = API_BASE.replace(/\/api$/, '');
  return `${base}${path.startsWith('/') ? path : '/' + path}`;
}

api.interceptors.request.use((config) => {
  if (typeof window !== 'undefined') {
    const token = localStorage.getItem('token');
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
  }
  return config;
});

api.interceptors.response.use(
  (res) => {
    return res;
  },
  (err) => {
    console.error(`[API] ${err.config?.method?.toUpperCase()} ${err.config?.url}`, err.response?.data);
    if (err.response?.status === 401 && typeof window !== 'undefined') {
      localStorage.removeItem('token');
      localStorage.removeItem('user');
      window.location.href = `${window.location.origin}/dev/siminspeksi/admin`;
    }
    return Promise.reject(err);
  }
);

export default api;
