import { useMemo, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ActivityIndicator, Alert, Modal, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { api } from '../api/client';
import type { Annotation, KnowledgeNote } from '../types';
import { formatMarkdownForReading } from '../companion/conversation';
import { colors, typography } from '../theme';

type SavedAnnotation = Annotation & { book?: { id: number; title: string } };
type Detail =
  | { kind: 'annotation'; item: SavedAnnotation; title: string; content: string }
  | { kind: 'knowledge'; item: KnowledgeNote; title: string; content: string };
type LibraryKind = 'highlight' | 'note' | 'ai';
type LibraryItem =
  | { key: string; kind: LibraryKind; detailKind: 'annotation'; item: SavedAnnotation; title: string; preview: string }
  | { key: string; kind: LibraryKind; detailKind: 'knowledge'; item: KnowledgeNote; title: string; preview: string };

const TYPE_LABEL: Record<KnowledgeNote['type'], string> = {
  book: '书籍原文', obsidian: 'Obsidian', note: '通用笔记', companion: '伴读收藏', other: '其他笔记',
};

const libraryLabel = (entry: LibraryItem) => entry.kind === 'highlight' ? '划线'
  : entry.kind === 'note' ? '笔记'
    : 'AI 收藏';
const libraryEditable = (entry: LibraryItem) => entry.detailKind === 'annotation'
  || ['note', 'companion', 'other'].includes(entry.item.type);

export function StudyScreen() {
  const client = useQueryClient();
  const cards = useQuery({ queryKey: ['flashcards', 'due'], queryFn: api.dueFlashcards });
  const stats = useQuery({ queryKey: ['reading', 'stats'], queryFn: api.readingStats });
  const library = useQuery({
    queryKey: ['unified-library'],
    queryFn: async () => {
      const [saved, notes] = await Promise.all([api.savedContent(), api.knowledgeNotes()]);
      return { annotations: saved.annotations, notes };
    },
  });
  const [filter, setFilter] = useState<'all' | LibraryKind>('all');
  const [detail, setDetail] = useState<Detail>();
  const [loadingDetail, setLoadingDetail] = useState(false);
  const [saving, setSaving] = useState(false);
  const card = cards.data?.[0];
  const libraryItems = useMemo<LibraryItem[]>(() => [
    ...(library.data?.annotations ?? []).map((item): LibraryItem => ({
      key: `annotation-${item.id}`,
      kind: item.note?.trim() ? 'note' : 'highlight',
      detailKind: 'annotation', item,
      title: item.book?.title ?? '阅读摘录',
      preview: item.note?.trim() || item.quote,
    })),
    ...(library.data?.notes ?? []).filter((item) => !['book', 'obsidian'].includes(item.type)).map((item): LibraryItem => ({
      key: `knowledge-${item.type}-${item.book_id ?? ''}-${item.source_path ?? ''}-${item.title}`,
      kind: item.type === 'companion' ? 'ai' : 'note',
      detailKind: 'knowledge', item,
      title: item.title,
      preview: item.preview,
    })),
  ].sort((a, b) => String(b.item.updated_at ?? '').localeCompare(String(a.item.updated_at ?? ''))), [library.data]);
  const visibleItems = filter === 'all' ? libraryItems : libraryItems.filter((item) => item.kind === filter);

  const review = async (known: boolean) => {
    if (!card) return;
    await api.reviewFlashcard(card.id, known);
    await client.invalidateQueries({ queryKey: ['flashcards', 'due'] });
  };

  const openAnnotation = (item: SavedAnnotation) => setDetail({
    kind: 'annotation', item, title: item.book?.title ?? '阅读划线', content: item.note ?? '',
  });

  const openKnowledge = async (item: KnowledgeNote) => {
    setDetail({ kind: 'knowledge', item, title: item.title, content: item.preview });
    setLoadingDetail(true);
    try {
      const chunks = await api.knowledgeChunks(item);
      setDetail({ kind: 'knowledge', item, title: item.title, content: formatMarkdownForReading(chunks.join('\n\n')) });
    } finally { setLoadingDetail(false); }
  };

  const saveDetail = async () => {
    if (!detail) return;
    setSaving(true);
    try {
      if (detail.kind === 'annotation') {
        await api.updateAnnotation(detail.item.book_id, detail.item.id, { note: detail.content.trim() || undefined, tag: detail.item.tag ?? undefined });
      } else {
        await api.updateKnowledgeNote(detail.item, { title: detail.title.trim(), content: detail.content.trim() });
      }
      await Promise.all([
        client.invalidateQueries({ queryKey: ['saved-content'] }),
        client.invalidateQueries({ queryKey: ['knowledge-notes'] }),
        client.invalidateQueries({ queryKey: ['unified-library'] }),
      ]);
      setDetail(undefined);
    } finally { setSaving(false); }
  };

  const removeDetail = () => {
    if (!detail) return;
    Alert.alert('删除内容', '删除后会同步从电脑端笔记库移除，确定继续吗？', [
      { text: '取消', style: 'cancel' },
      { text: '删除', style: 'destructive', onPress: async () => {
        if (detail.kind === 'annotation') await api.deleteAnnotation(detail.item.book_id, detail.item.id);
        else await api.deleteKnowledgeNote(detail.item);
        setDetail(undefined);
        await Promise.all([
          client.invalidateQueries({ queryKey: ['saved-content'] }),
          client.invalidateQueries({ queryKey: ['knowledge-notes'] }),
          client.invalidateQueries({ queryKey: ['unified-library'] }),
        ]);
      } },
    ]);
  };

  const knowledgeEditable = detail?.kind === 'knowledge' && ['note', 'companion', 'other'].includes(detail.item.type);
  const canEdit = detail?.kind === 'annotation' || knowledgeEditable;
  const canDelete = detail?.kind === 'annotation' || (detail?.kind === 'knowledge' && detail.item.type !== 'book');

  return <SafeAreaView style={styles.safe} edges={['top']}><ScrollView contentContainerStyle={styles.page}>
    <Text style={styles.eyebrow}>READ · RECALL · RETAIN</Text><Text style={styles.title}>复习与笔记库</Text>
    <Text style={styles.syncHint}>这里和电脑端使用同一份划线与笔记数据。</Text>
    <View style={styles.stats}>
      <View style={styles.stat}><Text style={styles.statValue}>{stats.data?.streak ?? '—'}</Text><Text style={styles.statLabel}>连续天数</Text></View>
      <View style={styles.divider} /><View style={styles.stat}><Text style={styles.statValue}>{stats.data?.total_minutes ?? '—'}</Text><Text style={styles.statLabel}>累计分钟</Text></View>
      <View style={styles.divider} /><View style={styles.stat}><Text style={styles.statValue}>{stats.data?.total_books ?? '—'}</Text><Text style={styles.statLabel}>书籍数量</Text></View>
    </View>

    <View style={styles.sectionHead}><Text style={styles.sectionTitle}>今日闪卡</Text><Text style={styles.count}>{cards.data?.length ?? 0} 张待复习</Text></View>
    {card ? <View style={styles.card}><Text style={styles.source}>{card.book?.title ?? '阅读摘录'} · 记忆箱 {card.box}</Text><Text style={styles.front}>{card.front}</Text><Text style={styles.answerText}>{card.back}</Text><View style={styles.actions}><Pressable style={styles.secondary} onPress={() => void review(false)}><Text style={styles.secondaryText}>还不熟</Text></Pressable><Pressable style={styles.primary} onPress={() => void review(true)}><Text style={styles.primaryText}>记住了</Text></Pressable></View></View> : <View style={styles.empty}><Text style={styles.emptyTitle}>今天已复习完成</Text><Text style={styles.emptyBody}>阅读时创建的闪卡会按计划出现在这里。</Text></View>}

    <View style={styles.sectionHead}><Text style={styles.sectionTitle}>我的笔记库</Text><Text style={styles.count}>{libraryItems.length} 条</Text></View>
    <Text style={styles.libraryHint}>手机端只保留阅读划线、手写笔记与 AI 收藏，保存后会同步到电脑端。</Text>
    <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.filters}>{([
      ['all', '全部'], ['highlight', '划线'], ['note', '笔记'], ['ai', 'AI 收藏'],
    ] as const).map(([key, label]) => <Pressable key={key} onPress={() => setFilter(key)} style={[styles.filter, filter === key && styles.filterActive]}><Text style={[styles.filterText, filter === key && styles.filterTextActive]}>{label}</Text></Pressable>)}</ScrollView>
    {library.isLoading && <ActivityIndicator color={colors.accent} />}
    {library.isError && <Retry onPress={() => void library.refetch()} />}
    {!library.isLoading && !library.isError && visibleItems.length === 0 && <View style={styles.empty}><Text style={styles.emptyTitle}>这里还没有内容</Text><Text style={styles.emptyBody}>阅读时划线、写笔记或收藏 AI 回答后，会自动出现在对应分类。</Text></View>}
    <View style={styles.list}>{visibleItems.map((entry) => <Pressable key={entry.key} onPress={() => entry.detailKind === 'annotation' ? openAnnotation(entry.item) : void openKnowledge(entry.item)} style={styles.item}><Text style={styles.source}>{libraryLabel(entry)}</Text><Text style={styles.itemTitle}>{entry.title}</Text>{entry.detailKind === 'annotation' && entry.item.note ? <Text numberOfLines={3} style={styles.body}>{entry.item.note}</Text> : <Text numberOfLines={4} style={styles.body}>{formatMarkdownForReading(entry.preview)}</Text>}<Text style={styles.tapHint}>点击查看{libraryEditable(entry) ? '、编辑' : ''}</Text></Pressable>)}</View>
  </ScrollView>

    <Modal visible={!!detail} transparent animationType="slide" onRequestClose={() => setDetail(undefined)}>
      <View style={styles.shade}><SafeAreaView style={styles.sheet} edges={['bottom']}>
        <View style={styles.sheetHead}><Text style={styles.sheetTitle}>{detail?.kind === 'knowledge' ? TYPE_LABEL[detail.item.type] : '划线笔记'}</Text><Pressable onPress={() => setDetail(undefined)}><Text style={styles.close}>关闭</Text></Pressable></View>
        {detail?.kind === 'annotation' && <View style={styles.quote}><Text style={styles.body}>{detail.item.quote}</Text></View>}
        {detail?.kind === 'knowledge' && <TextInput editable={knowledgeEditable} value={detail.title} onChangeText={(title) => setDetail({ ...detail, title })} style={[styles.titleInput, !knowledgeEditable && styles.readOnly]} />}
        {loadingDetail ? <ActivityIndicator style={styles.detailLoader} color={colors.accent} /> : <TextInput editable={!!canEdit} multiline value={detail?.content ?? ''} onChangeText={(content) => detail && setDetail({ ...detail, content } as Detail)} placeholder={detail?.kind === 'annotation' ? '写下你对这段话的理解…' : undefined} placeholderTextColor={colors.muted} style={[styles.contentInput, !canEdit && styles.readOnly]} />}
        <View style={styles.sheetActions}>{canDelete && <Pressable onPress={removeDetail} style={styles.delete}><Text style={styles.deleteText}>删除</Text></Pressable>}{canEdit && <Pressable disabled={saving || !detail?.title.trim()} onPress={() => void saveDetail()} style={styles.primary}><Text style={styles.primaryText}>{saving ? '保存中…' : '保存修改'}</Text></Pressable>}</View>
      </SafeAreaView></View>
    </Modal>
  </SafeAreaView>;
}

function Retry({ onPress }: { onPress: () => void }) {
  return <Pressable onPress={onPress} style={styles.retry}><Text style={styles.retryText}>加载失败，点击重试</Text></Pressable>;
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.paper }, page: { padding: 22, paddingBottom: 42 }, eyebrow: { color: colors.accent, fontSize: 10, letterSpacing: 2, fontWeight: '800' }, title: { color: colors.ink, fontFamily: typography.display, fontSize: 31, marginTop: 5 }, syncHint: { color: colors.muted, marginTop: 7, lineHeight: 20 }, stats: { flexDirection: 'row', backgroundColor: colors.ink, borderRadius: 20, padding: 20, marginTop: 22 }, stat: { flex: 1, alignItems: 'center' }, statValue: { color: colors.paper, fontFamily: typography.display, fontSize: 27 }, statLabel: { color: '#CBBFB0', fontSize: 11, marginTop: 4 }, divider: { width: 1, backgroundColor: '#504940' }, sectionHead: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginTop: 28, marginBottom: 12 }, sectionTitle: { color: colors.ink, fontFamily: typography.display, fontSize: 21 }, count: { color: colors.muted, fontSize: 12 }, libraryHint: { color: colors.muted, fontSize: 12, lineHeight: 19, marginTop: -5, marginBottom: 11 }, filters: { gap: 8, paddingBottom: 13 }, filter: { borderWidth: 1, borderColor: colors.line, backgroundColor: colors.white, borderRadius: 15, paddingHorizontal: 13, paddingVertical: 8 }, filterActive: { backgroundColor: colors.ink, borderColor: colors.ink }, filterText: { color: colors.muted, fontSize: 11, fontWeight: '800' }, filterTextActive: { color: colors.paper }, card: { backgroundColor: colors.white, borderRadius: 20, borderWidth: 1, borderColor: colors.line, padding: 20 }, source: { color: colors.accent, fontSize: 10, fontWeight: '900', marginBottom: 7 }, front: { color: colors.ink, fontFamily: typography.display, fontSize: 22, lineHeight: 31, marginTop: 8 }, answerText: { color: colors.muted, lineHeight: 21, borderTopWidth: 1, borderTopColor: colors.line, marginTop: 17, paddingTop: 13 }, actions: { flexDirection: 'row', gap: 10, marginTop: 18 }, secondary: { flex: 1, borderWidth: 1, borderColor: colors.line, borderRadius: 14, padding: 13, alignItems: 'center' }, secondaryText: { color: colors.ink, fontWeight: '700' }, primary: { flex: 1, backgroundColor: colors.accent, borderRadius: 14, padding: 13, alignItems: 'center' }, primaryText: { color: colors.white, fontWeight: '800' }, empty: { backgroundColor: colors.white, borderRadius: 20, padding: 28, borderWidth: 1, borderColor: colors.line, alignItems: 'center' }, emptyTitle: { color: colors.ink, fontFamily: typography.display, fontSize: 20 }, emptyBody: { color: colors.muted, textAlign: 'center', lineHeight: 21, marginTop: 7 }, list: { gap: 10 }, item: { backgroundColor: colors.white, borderWidth: 1, borderColor: colors.line, borderRadius: 16, padding: 15 }, itemTitle: { color: colors.ink, fontWeight: '800', marginBottom: 5 }, body: { color: colors.ink, lineHeight: 22 }, note: { color: colors.muted, lineHeight: 20, borderTopWidth: 1, borderTopColor: colors.line, marginTop: 10, paddingTop: 9 }, tapHint: { color: colors.accent, fontSize: 10, fontWeight: '800', marginTop: 9 }, retry: { borderWidth: 1, borderColor: colors.line, borderRadius: 14, padding: 14, alignItems: 'center' }, retryText: { color: colors.accent, fontWeight: '800' }, shade: { flex: 1, backgroundColor: 'rgba(20,17,14,.45)', justifyContent: 'flex-end' }, sheet: { maxHeight: '85%', backgroundColor: colors.paper, borderTopLeftRadius: 26, borderTopRightRadius: 26, padding: 20 }, sheetHead: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }, sheetTitle: { color: colors.ink, fontFamily: typography.display, fontSize: 25 }, close: { color: colors.accent, fontWeight: '800' }, quote: { backgroundColor: colors.paperDeep, borderLeftWidth: 3, borderLeftColor: colors.accent, borderRadius: 12, padding: 14, marginBottom: 12 }, titleInput: { borderWidth: 1, borderColor: colors.line, backgroundColor: colors.white, borderRadius: 13, padding: 13, color: colors.ink, fontWeight: '800', marginBottom: 10 }, contentInput: { minHeight: 180, maxHeight: 380, borderWidth: 1, borderColor: colors.line, backgroundColor: colors.white, borderRadius: 14, padding: 14, color: colors.ink, lineHeight: 22, textAlignVertical: 'top' }, readOnly: { backgroundColor: colors.paperDeep }, detailLoader: { minHeight: 180 }, sheetActions: { flexDirection: 'row', gap: 10, marginTop: 14 }, delete: { borderWidth: 1, borderColor: '#C98B76', borderRadius: 14, padding: 13, alignItems: 'center', flex: 1 }, deleteText: { color: '#A84F38', fontWeight: '800' },
});
