import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { Alert, FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import * as DocumentPicker from 'expo-document-picker';
import { File } from 'expo-file-system';
import { useQueryClient } from '@tanstack/react-query';
import { SafeAreaView } from 'react-native-safe-area-context';
import { api } from '../api/client';
import type { BottomTabScreenProps } from '@react-navigation/bottom-tabs';
import type { CompositeScreenProps } from '@react-navigation/native';
import type { RootStackParamList, MainTabParamList } from '../navigation/AppNavigator';
import type { Book } from '../types';
import { colors, typography } from '../theme';

type Props = CompositeScreenProps<BottomTabScreenProps<MainTabParamList, 'Library'>, NativeStackScreenProps<RootStackParamList>>;

function BookCard({ book, onPress, onTools }: { book: Book; onPress: () => void; onTools: () => void }) {
  return <Pressable onPress={onPress} style={styles.card}>
    <View style={styles.cover}><Text style={styles.coverLetter}>{book.title.slice(0, 1)}</Text><Text style={styles.format}>{book.format.toUpperCase()}</Text></View>
    <View style={styles.meta}><Text style={styles.bookTitle} numberOfLines={2}>{book.title}</Text><Text style={styles.author}>{book.author || '未知作者'}</Text><View style={styles.cardActions}><Text style={styles.open}>继续阅读  →</Text><Pressable onPress={onTools}><Text style={styles.tools}>阅读工具</Text></Pressable></View></View>
  </Pressable>;
}

export function LibraryScreen({ navigation }: Props) {
  const client = useQueryClient();
  const [importing, setImporting] = useState(false);
  const query = useQuery({ queryKey: ['books'], queryFn: api.books });
  const importBook = async () => {
    if (importing) return;
    try {
      // Android providers frequently report EPUB as application/zip or
      // application/octet-stream, so MIME filtering would hide valid books.
      const picked = await DocumentPicker.getDocumentAsync({ type: '*/*', copyToCacheDirectory: true });
      if (picked.canceled) return;
      const asset = picked.assets[0];
      const extension = asset.name.toLowerCase().match(/\.([^.]+)$/)?.[1];
      if (extension !== 'pdf' && extension !== 'epub') {
        Alert.alert('无法导入', '请选择 EPUB 或 PDF 文件。');
        return;
      }
      if (asset.size && asset.size > 500 * 1024 * 1024) {
        Alert.alert('文件太大', '单本书不能超过 500MB。');
        return;
      }
      setImporting(true);
      const form = new FormData();
      const file = new File(asset.uri);
      form.append('file', file, asset.name);
      form.append('title', asset.name.replace(/\.(pdf|epub)$/i, ''));
      const book = await api.uploadBook(form);
      await client.invalidateQueries({ queryKey: ['books'] });
      Alert.alert('导入成功', `《${book.title}》已经加入书房。`);
    } catch (error) {
      Alert.alert('导入失败', error instanceof Error ? error.message : '请检查网络后重试。');
    } finally {
      setImporting(false);
    }
  };
  return <SafeAreaView style={styles.safe} edges={['top']}>
    <View style={styles.header}><View><Text style={styles.eyebrow}>PRIVATE LIBRARY</Text><Text style={styles.heading}>我的书房</Text></View><Pressable disabled={importing} onPress={() => void importBook()} style={[styles.import, importing && styles.importDisabled]}><Text style={styles.importText}>{importing ? '导入中…' : '导入书籍'}</Text></Pressable></View>
    {query.isError ? <View style={styles.center}><Text style={styles.errorTitle}>书库暂时无法同步</Text><Text style={styles.errorBody}>{query.error instanceof Error ? query.error.message : '请检查网络'}</Text><Pressable onPress={() => void query.refetch()} style={styles.retry}><Text style={styles.retryText}>重新加载</Text></Pressable></View> :
    <FlatList data={query.data ?? []} keyExtractor={(item) => String(item.id)} contentContainerStyle={styles.list} renderItem={({ item }) => <BookCard book={item} onPress={() => navigation.navigate('Reader', { book: item })} onTools={() => navigation.navigate('BookTools', { book: item })} />} refreshControl={<RefreshControl refreshing={query.isFetching} onRefresh={() => void query.refetch()} tintColor={colors.accent} />} ListEmptyComponent={!query.isLoading ? <View style={styles.center}><Text style={styles.errorTitle}>书房还是空的</Text><Text style={styles.errorBody}>可以直接从手机导入 EPUB 或 PDF。</Text></View> : null} />}
  </SafeAreaView>;
}

const styles = StyleSheet.create({ safe: { flex: 1, backgroundColor: colors.paper }, header: { paddingHorizontal: 22, paddingTop: 18, paddingBottom: 18, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }, eyebrow: { color: colors.accent, fontSize: 10, fontWeight: '800', letterSpacing: 2 }, heading: { color: colors.ink, fontFamily: typography.display, fontSize: 32, marginTop: 4 }, profile: { width: 40, height: 40, borderRadius: 20, backgroundColor: colors.ink, color: colors.paper, textAlign: 'center', textAlignVertical: 'center', fontSize: 17 }, import: { backgroundColor: colors.ink, borderRadius: 18, paddingHorizontal: 15, paddingVertical: 10 }, importDisabled: { opacity: .55 }, importText: { color: colors.paper, fontWeight: '800', fontSize: 12 }, list: { padding: 18, paddingBottom: 40, gap: 16 }, card: { backgroundColor: colors.white, borderRadius: 20, padding: 14, flexDirection: 'row', borderWidth: 1, borderColor: colors.line }, cover: { width: 86, height: 122, borderRadius: 10, backgroundColor: colors.ink, padding: 10, justifyContent: 'space-between' }, coverLetter: { color: colors.paper, fontFamily: typography.display, fontSize: 31 }, format: { color: '#D8B69A', fontSize: 9, fontWeight: '800', letterSpacing: 1.3 }, meta: { flex: 1, padding: 8, paddingLeft: 16 }, bookTitle: { color: colors.ink, fontFamily: typography.display, fontSize: 20, lineHeight: 27 }, author: { color: colors.muted, marginTop: 7 }, cardActions: { marginTop: 'auto', flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }, open: { color: colors.accent, fontWeight: '700' }, tools: { color: colors.muted, fontSize: 12, textDecorationLine: 'underline' }, center: { padding: 34, alignItems: 'center', justifyContent: 'center', flex: 1 }, errorTitle: { color: colors.ink, fontFamily: typography.display, fontSize: 22 }, errorBody: { color: colors.muted, marginTop: 9, textAlign: 'center' }, retry: { marginTop: 20, backgroundColor: colors.accent, borderRadius: 22, paddingHorizontal: 22, paddingVertical: 12 }, retryText: { color: colors.white, fontWeight: '700' } });
