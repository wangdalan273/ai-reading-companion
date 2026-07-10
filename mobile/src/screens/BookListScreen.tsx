import { useState } from 'react';
import { View, FlatList, StyleSheet, Image, RefreshControl } from 'react-native';
import { Card, FAB, Text, ActivityIndicator, Snackbar, Appbar } from 'react-native-paper';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigation } from '@react-navigation/native';
import { booksRepo } from '../lib/sync/repository';
import { useSyncStore } from '../state/syncStore';
import type { Book } from '../types/models';

/**
 * 书库（阶段 2 · 本地优先）
 * 读：一律来自本地 SQLite（离线即可见），由同步引擎从云端填充。
 * 下拉刷新 / 右上角刷新：触发 syncNow（先推离线队列，再拉云端增量）。
 */
export function BookListScreen() {
  const navigation = useNavigation<any>();
  const qc = useQueryClient();
  const [snack, setSnack] = useState<string | null>(null);
  const syncing = useSyncStore((s) => s.syncing);
  const trigger = useSyncStore((s) => s.trigger);

  const { data, isLoading, refetch } = useQuery<Book[]>({
    queryKey: ['books'],
    queryFn: booksRepo.list,
  });

  const onRefresh = async () => {
    await trigger();
    await refetch();
  };

  const books = data ?? [];

  return (
    <View style={styles.container}>
      <Appbar.Header>
        <Appbar.Content title="我的书库" />
        <Appbar.Action icon={syncing ? 'sync' : 'refresh'} onPress={onRefresh} disabled={syncing} />
      </Appbar.Header>

      {isLoading && !data ? (
        <ActivityIndicator style={styles.center} />
      ) : (
        <FlatList
          data={books}
          keyExtractor={(b) => String(b.id)}
          contentContainerStyle={styles.list}
          refreshControl={
            <RefreshControl refreshing={syncing} onRefresh={onRefresh} colors={['#6750A4']} />
          }
          renderItem={({ item }) => (
            <Card
              style={styles.card}
              onPress={() => navigation.navigate('Reader', { bookId: String(item.id) })}
            >
              <View style={styles.row}>
                {item.cover_url ? (
                  <Image source={{ uri: item.cover_url }} style={styles.cover} />
                ) : (
                  <View style={[styles.cover, styles.coverPlaceholder]}>
                    <Text>📖</Text>
                  </View>
                )}
                <View style={styles.meta}>
                  <Text variant="titleMedium">{item.title}</Text>
                  <Text variant="bodySmall" numberOfLines={1}>
                    {item.author ?? '未知作者'}
                  </Text>
                  <Text variant="labelSmall" style={styles.format}>
                    {(item.format ?? '').toUpperCase()}
                  </Text>
                </View>
              </View>
            </Card>
          )}
          ListEmptyComponent={
            <Text style={styles.center}>
              {syncing ? '正在从云端同步…' : '还没有书籍，点右下角添加'}
            </Text>
          }
        />
      )}

      <FAB
        icon="plus"
        style={styles.fab}
        onPress={() => setSnack('上传导入功能将复用电脑端导入链路（后续迭代接入）')}
        label="添加"
      />
      <Snackbar visible={!!snack} onDismiss={() => setSnack(null)} duration={2500}>
        {snack}
      </Snackbar>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#F6F5F8' },
  center: { flex: 1, textAlign: 'center', marginTop: 80 },
  list: { padding: 12 },
  card: { marginBottom: 12 },
  row: { flexDirection: 'row', padding: 12 },
  cover: { width: 48, height: 64, borderRadius: 6, marginRight: 12 },
  coverPlaceholder: {
    backgroundColor: '#E7E0EC',
    alignItems: 'center',
    justifyContent: 'center',
  },
  meta: { flex: 1, justifyContent: 'center' },
  format: { color: '#6750A4', marginTop: 4 },
  fab: { position: 'absolute', right: 16, bottom: 16 },
});
