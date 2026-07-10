import { create } from 'zustand';
import { api } from '../lib/api/client';
import { tokenStore } from '../lib/auth/tokenStore';
import type { User } from '../types/models';

interface AuthState {
  user: User | null;
  token: string | null;
  initialized: boolean; // 是否已完成启动恢复
  hydrate: () => Promise<void>;
  login: (email: string, password: string) => Promise<void>;
  register: (name: string, email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
}

export const useAuthStore = create<AuthState>((set) => ({
  user: null,
  token: null,
  initialized: false,

  // 冷启动：用本地 token 调 /me 恢复会话
  async hydrate() {
    const token = await tokenStore.get();
    if (!token) {
      set({ initialized: true });
      return;
    }
    try {
      const user = await api.get<User>('/me');
      set({ token, user, initialized: true });
    } catch {
      await tokenStore.clear();
      set({ initialized: true });
    }
  },

  async login(email, password) {
    const data = await api.post<{ token: string; user: User }>('/login', {
      email,
      password,
    });
    await tokenStore.set(data.token);
    set({ token: data.token, user: data.user });
  },

  async register(name, email, password) {
    const data = await api.post<{ token: string; user: User }>('/register', {
      name,
      email,
      password,
    });
    await tokenStore.set(data.token);
    set({ token: data.token, user: data.user });
  },

  async logout() {
    try {
      await api.post('/logout');
    } catch {
      /* 即使后端失败也清理本地 */
    }
    await tokenStore.clear();
    set({ token: null, user: null });
  },
}));
