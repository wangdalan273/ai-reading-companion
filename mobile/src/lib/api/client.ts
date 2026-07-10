import Constants from 'expo-constants';
import { tokenStore } from '../auth/tokenStore';

/**
 * API 客户端：统一注入 Bearer token、拼装 /api/v1 前缀、处理 401。
 * 所有业务请求都走这里，方便集中做重试、日志、错误归一化。
 */
const API_BASE: string =
  (Constants.expoConfig?.extra?.apiBaseUrl as string) ||
  'https://read.sxmnq.art';

export class ApiError extends Error {
  status: number;
  constructor(status: number, message: string) {
    super(message);
    this.status = status;
  }
}

export async function apiFetch<T>(
  path: string,
  options: RequestInit = {}
): Promise<T> {
  const token = await tokenStore.get();
  const headers = new Headers(options.headers);
  headers.set('Content-Type', 'application/json');
  headers.set('Accept', 'application/json');
  if (token) headers.set('Authorization', `Bearer ${token}`);

  const res = await fetch(`${API_BASE}/api/v1${path}`, {
    ...options,
    headers,
  });

  if (res.status === 401) {
    // 凭证失效：清理本地态，交由 authStore 触发重新登录
    await tokenStore.clear();
    throw new ApiError(401, '未授权或登录已过期');
  }

  if (!res.ok) {
    let msg = `请求失败 (${res.status})`;
    try {
      const body = await res.json();
      if (body?.message) msg = body.message;
    } catch {
      /* ignore */
    }
    throw new ApiError(res.status, msg);
  }

  // 204 No Content
  if (res.status === 204) return undefined as T;
  return (await res.json()) as T;
}

export const api = {
  get: <T>(path: string) => apiFetch<T>(path),
  post: <T>(path: string, body?: unknown) =>
    apiFetch<T>(path, { method: 'POST', body: body ? JSON.stringify(body) : undefined }),
  put: <T>(path: string, body?: unknown) =>
    apiFetch<T>(path, { method: 'PUT', body: body ? JSON.stringify(body) : undefined }),
  del: <T>(path: string) => apiFetch<T>(path, { method: 'DELETE' }),
};

export { API_BASE };
