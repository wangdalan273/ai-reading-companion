import 'react-native-gesture-handler';
import { useEffect } from 'react';
import { NavigationContainer } from '@react-navigation/native';
import { QueryClientProvider } from '@tanstack/react-query';
import { PaperProvider } from 'react-native-paper';
import { StatusBar } from 'expo-status-bar';
import * as SplashScreen from 'expo-splash-screen';

import { AppNavigator } from './src/navigation/AppNavigator';
import { theme } from './src/theme/theme';
import { useAuthStore } from './src/state/authStore';
import { useSyncStore } from './src/state/syncStore';
import { migrateLocalDb } from './src/lib/storage/db';
import { queryClient } from './src/lib/queryClient';

// ── 开机画面保持可见，直到 App 初始化完成再收起 ────────────────
SplashScreen.preventAutoHideAsync().catch(() => {});

export default function App() {
  const hydrate = useAuthStore((s) => s.hydrate);
  const initialized = useAuthStore((s) => s.initialized);
  const user = useAuthStore((s) => s.user);
  const triggerSync = useSyncStore((s) => s.trigger);
  const hasSyncedOnce = useSyncStore((s) => s.hasSyncedOnce);

  // 启动：建本地库 + 从安全存储恢复登录态
  useEffect(() => {
    migrateLocalDb()
      .catch((e) => console.warn('[db] migrate failed', e))
      .finally(() => hydrate());
  }, [hydrate]);

  // 登录态就绪后自动与云端对齐一次（离线写入也会在此时上行）
  useEffect(() => {
    if (user && !hasSyncedOnce) {
      triggerSync();
    }
  }, [user, hasSyncedOnce, triggerSync]);

  // 初始化完成 → 隐藏开机画面
  useEffect(() => {
    if (initialized) {
      SplashScreen.hideAsync().catch(() => {});
    }
  }, [initialized]);

  if (!initialized) {
    return null; // 开机画面继续显示
  }

  return (
    <QueryClientProvider client={queryClient}>
      <PaperProvider theme={theme}>
        <NavigationContainer>
          <AppNavigator />
          <StatusBar style="auto" />
        </NavigationContainer>
      </PaperProvider>
    </QueryClientProvider>
  );
}
