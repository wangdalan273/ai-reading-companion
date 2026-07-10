import { QueryClient } from '@tanstack/react-query';

/**
 * 全局 QueryClient 单例：App 与 syncStore 共用，便于同步完成后统一失效本地优先查询。
 */
export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 1,
      staleTime: 1000 * 60, // 1 分钟
    },
  },
});
