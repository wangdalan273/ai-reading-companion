import { useEffect, useState } from 'react';
import { View, StyleSheet, ScrollView } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import {
  Appbar,
  Text,
  TextInput,
  Button,
  Card,
  Snackbar,
  ActivityIndicator,
} from 'react-native-paper';
import WebView from 'react-native-webview';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { tokenStore } from '../lib/auth/tokenStore';
import { booksRepo, annotationsRepo, readingLogsRepo } from '../lib/sync/repository';
import { fetchBookFileUrl } from '../services';
import type { Book, Annotation } from '../types/models';

/**
 * 阅读器（阶段 2 · 本地优先）
 * - 书籍二进制通过 /v1/books/{id}/file 以 Bearer token 流式加载（PDF 内联渲染；EPUB 精排为下一步）。
 * - 划线：读本地 annotations；新增经 annotationsRepo.create（乐观落本地 + 离线队列）。
 * - 进度：readingLogsRepo.log（乐观累加本地 + 离线队列）。
 */
export function ReaderScreen({ route }: { route?: { params?: { bookId?: string } } }) {
  const navigation = useNavigation<any>();
  const qc = useQueryClient();
  const id = route?.params?.bookId ?? '';
  const [token, setToken] = useState<string | null>(null);
  const [loc, setLoc] = useState('');
  const [quote, setQuote] = useState('');
  const [snack, setSnack] = useState<string | null>(null);

  useEffect(() => {
    tokenStore.get().then(setToken);
  }, []);

  const bookQuery = useQuery<Book | null>({
    queryKey: ['book', id],
    queryFn: () => booksRepo.get(id),
    enabled: !!id,
  });

  const annQuery = useQuery<Annotation[]>({
    queryKey: ['annotations', id],
    queryFn: () => annotationsRepo.list(id),
    enabled: !!id,
  });

  async function onSubmitAnnotation() {
    if (!quote.trim()) {
      setSnack('请填写摘录内容');
      return;
    }
    try {
      await annotationsRepo.create(id, { loc: loc.trim() || 'manual', quote: quote.trim() });
      setLoc('');
      setQuote('');
      qc.invalidateQueries({ queryKey: ['annotations', id] });
      setSnack('划线已保存（离线将自动同步）');
    } catch {
      setSnack('保存失败');
    }
  }

  async function onReportProgress() {
    try {
      await readingLogsRepo.log(id, 60);
      setSnack('已记录 1 分钟阅读');
    } catch {
      setSnack('记录失败');
    }
  }

  if (bookQuery.isLoading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator />
      </View>
    );
  }
  const book = bookQuery.data;
  // file_url 由后端动态生成，本地库不存该列，故实时拼装书籍文件流地址
  const fileUrl = book ? fetchBookFileUrl(book.id) : null;

  return (
    <View style={styles.container}>
      <Appbar.Header>
        <Appbar.BackAction onPress={() => navigation.goBack()} />
        <Appbar.Content title={book?.title ?? '阅读中'} />
        <Appbar.Action icon="progress-clock" onPress={onReportProgress} />
      </Appbar.Header>

      {book && fileUrl && token ? (
        <WebView
          style={styles.webview}
          source={{ uri: fileUrl, headers: { Authorization: `Bearer ${token}` } }}
          startInLoadingState
          renderLoading={() => <ActivityIndicator style={styles.center} />}
        />
      ) : (
        <View style={styles.center}>
          <Text>书籍文件加载中…（PDF 可直接预览，EPUB 精排为下一步）</Text>
        </View>
      )}

      <ScrollView style={styles.panel}>
        <Text variant="titleSmall" style={styles.panelTitle}>
          本书划线（{annQuery.data?.length ?? 0}）
        </Text>
        {(annQuery.data ?? []).map((a) => (
          <Card key={a.id} style={styles.annCard}>
            <Text variant="bodyMedium">{a.quote}</Text>
            {a.note ? <Text variant="bodySmall">📝 {a.note}</Text> : null}
          </Card>
        ))}

        <Text variant="titleSmall" style={styles.panelTitle}>
          新增划线
        </Text>
        <TextInput
          label="定位（CFI / 页码，可留空）"
          value={loc}
          onChangeText={setLoc}
          mode="outlined"
          style={styles.input}
        />
        <TextInput
          label="摘录文本"
          value={quote}
          onChangeText={setQuote}
          mode="outlined"
          multiline
          style={styles.input}
        />
        <Button mode="contained" onPress={onSubmitAnnotation}>
          保存划线
        </Button>
      </ScrollView>

      <Snackbar visible={!!snack} onDismiss={() => setSnack(null)} duration={2000}>
        {snack}
      </Snackbar>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#FFFFFF' },
  center: { flex: 1, justifyContent: 'center', alignItems: 'center', padding: 24 },
  webview: { flex: 1 },
  panel: { maxHeight: '45%', padding: 12, backgroundColor: '#F6F5F8' },
  panelTitle: { marginVertical: 8 },
  annCard: { padding: 12, marginBottom: 8 },
  input: { marginBottom: 8 },
});
