import { create } from 'zustand';
import { api, tokenStore } from '../api/client';
import type { User } from '../types';

type AuthState = {
  user: User | null;
  booting: boolean;
  restore: () => Promise<void>;
  login: (email: string, password: string) => Promise<void>;
  register: (name: string, email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
};

export const useAuthStore = create<AuthState>((set) => ({
  user: null,
  booting: true,
  restore: async () => {
    const token = await tokenStore.get();
    if (!token) return set({ booting: false });
    try {
      set({ user: await api.me(), booting: false });
    } catch {
      await tokenStore.clear();
      set({ user: null, booting: false });
    }
  },
  login: async (email, password) => set({ user: await api.login(email, password) }),
  register: async (name, email, password) => set({ user: await api.register(name, email, password) }),
  logout: async () => {
    await tokenStore.clear();
    set({ user: null });
  },
}));
