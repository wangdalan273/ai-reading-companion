import { View, StyleSheet } from 'react-native';
import { Card, Text, ActivityIndicator } from 'react-native-paper';
import { useQuery } from '@tanstack/react-query';
import { statsRepo } from '../lib/sync/repository';
import type { ReadingStats } from '../types/models';

/**
 * 阅读统计（阶段 2 · 本地优先）
 * 由本地 reading_logs 计算（离线即可见），与后端公式近似一致；
 * 同步后会用云端数据刷新（deleted_at 过滤保证不含已删记录）。
 */
export function StatsScreen() {
  const { data, isLoading } = useQuery<ReadingStats>({
    queryKey: ['reading', 'stats'],
    queryFn: statsRepo.compute,
  });

  if (isLoading) return <ActivityIndicator style={styles.center} />;

  const hours = data ? Math.round((data.total_seconds / 3600) * 10) / 10 : 0;

  return (
    <View style={styles.container}>
      <Text variant="headlineSmall" style={styles.title}>
        阅读统计
      </Text>
      <View style={styles.grid}>
        <Card style={styles.tile}>
          <Text variant="displaySmall" style={styles.num}>
            {data?.streak ?? 0}
          </Text>
          <Text variant="labelMedium">连续天数</Text>
        </Card>
        <Card style={styles.tile}>
          <Text variant="displaySmall" style={styles.num}>
            {hours}
          </Text>
          <Text variant="labelMedium">累计小时</Text>
        </Card>
        <Card style={styles.tile}>
          <Text variant="displaySmall" style={styles.num}>
            {data?.total_books ?? 0}
          </Text>
          <Text variant="labelMedium">书籍数</Text>
        </Card>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#F6F5F8', padding: 16 },
  center: { flex: 1, marginTop: 80 },
  title: { marginBottom: 16 },
  grid: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between' },
  tile: { width: '48%', padding: 20, marginBottom: 16, alignItems: 'center' },
  num: { color: '#6750A4' },
});
