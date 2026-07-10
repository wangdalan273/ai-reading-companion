import { create } from 'zustand';
import { syncNow } from '../lib/sync/engine';
import { queryClient } from '../lib/queryClient';

/**
 * 同步状态 store（阶段 2）
 * - trigger()：先推离线队列、再拉云端增量；完成后统一失效 react-query，让页面重读本地库。
 * - 由 App 在「登录态就绪」后自动调用一次，也供页面下拉刷新 / 手动按钮触发。
 */
interface SyncState {
  syncing: boolean;
  lastSyncedAt: string | null;
  lastError: string | null;
  /** 是否至少成功同步过一次（用于首屏区分「真无数据」与「还没拉取」） */
  hasSyncedOnce: boolean;
  trigger: () => Promise<void>;
}

export const useSyncStore = create<SyncState>((set, get) => ({
  syncing: false,
  lastSyncedAt: null,
  lastError: null,
  hasSyncedOnce: false,

  trigger: async () => {
    if (get().syncing) return; // 防重入
    set({ syncing: true, lastError: null });
    try {
      await syncNow();
      set({ lastSyncedAt: new Date().toISOString(), hasSyncedOnce: true });
      // 同步后让所有本地优先查询重新读取本地库（离线即可见）
      queryClient.invalidateQueries();
    } catch (e) {
      set({ lastError: (e as Error)?.message ?? '同步失败' });
    } finally {
      set({ syncing: false });
    }
  },
}));
