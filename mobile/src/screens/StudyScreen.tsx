import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { api } from '../api/client';
import { colors, typography } from '../theme';

export function StudyScreen() {
  const client = useQueryClient();
  const cards = useQuery({ queryKey: ['flashcards', 'due'], queryFn: api.dueFlashcards });
  const stats = useQuery({ queryKey: ['reading', 'stats'], queryFn: api.readingStats });
  const card = cards.data?.[0];
  const review = async (known: boolean) => { if (!card) return; await api.reviewFlashcard(card.id, known); await client.invalidateQueries({ queryKey: ['flashcards', 'due'] }); };
  return <SafeAreaView style={styles.safe} edges={['top']}><ScrollView contentContainerStyle={styles.page}>
    <Text style={styles.eyebrow}>READ · RECALL · RETAIN</Text><Text style={styles.title}>学习与回顾</Text>
    <View style={styles.stats}>
      <View style={styles.stat}><Text style={styles.statValue}>{stats.data?.streak ?? '—'}</Text><Text style={styles.statLabel}>连续天数</Text></View>
      <View style={styles.divider} /><View style={styles.stat}><Text style={styles.statValue}>{stats.data?.total_minutes ?? '—'}</Text><Text style={styles.statLabel}>累计分钟</Text></View>
      <View style={styles.divider} /><View style={styles.stat}><Text style={styles.statValue}>{stats.data?.total_books ?? '—'}</Text><Text style={styles.statLabel}>书籍数量</Text></View>
    </View>
    <View style={styles.sectionHead}><Text style={styles.sectionTitle}>今日闪卡</Text><Text style={styles.count}>{cards.data?.length ?? 0} 张待复习</Text></View>
    {card ? <View style={styles.card}><Text style={styles.book}>{card.book?.title ?? '阅读摘录'} · 记忆箱 {card.box}</Text><Text style={styles.front}>{card.front}</Text><View style={styles.answer}><Text style={styles.answerLabel}>出处 / 答案</Text><Text style={styles.answerText}>{card.back}</Text></View><View style={styles.actions}><Pressable style={styles.again} onPress={() => void review(false)}><Text style={styles.againText}>还不熟</Text></Pressable><Pressable style={styles.known} onPress={() => void review(true)}><Text style={styles.knownText}>记住了</Text></Pressable></View></View> : <View style={styles.empty}><Text style={styles.emptyTitle}>今天已复习完成</Text><Text style={styles.emptyBody}>阅读时创建的闪卡会按间隔重复计划出现在这里。</Text></View>}
    <Text style={styles.sectionTitle}>最近阅读</Text><View style={styles.days}>{(stats.data?.days ?? []).slice(-14).map((day) => <View key={day.date} style={styles.day}><View style={[styles.bar, { height: Math.max(4, Math.min(70, day.seconds / 30)) }]} /><Text style={styles.dayLabel}>{day.date.slice(5)}</Text></View>)}</View>
  </ScrollView></SafeAreaView>;
}

const styles = StyleSheet.create({ safe: { flex: 1, backgroundColor: colors.paper }, page: { padding: 22, paddingBottom: 42 }, eyebrow: { color: colors.accent, fontSize: 10, letterSpacing: 2, fontWeight: '800' }, title: { color: colors.ink, fontFamily: typography.display, fontSize: 31, marginTop: 5 }, stats: { flexDirection: 'row', backgroundColor: colors.ink, borderRadius: 20, padding: 20, marginTop: 22 }, stat: { flex: 1, alignItems: 'center' }, statValue: { color: colors.paper, fontFamily: typography.display, fontSize: 27 }, statLabel: { color: '#CBBFB0', fontSize: 11, marginTop: 4 }, divider: { width: 1, backgroundColor: '#504940' }, sectionHead: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginTop: 28, marginBottom: 12 }, sectionTitle: { color: colors.ink, fontFamily: typography.display, fontSize: 21, marginTop: 25 }, count: { color: colors.muted, marginTop: 25, fontSize: 12 }, card: { backgroundColor: colors.white, borderRadius: 20, borderWidth: 1, borderColor: colors.line, padding: 20 }, book: { color: colors.accent, fontSize: 11, fontWeight: '800' }, front: { color: colors.ink, fontFamily: typography.display, fontSize: 22, lineHeight: 31, marginTop: 15 }, answer: { borderTopWidth: 1, borderColor: colors.line, marginTop: 20, paddingTop: 15 }, answerLabel: { color: colors.muted, fontSize: 10, letterSpacing: 1 }, answerText: { color: colors.ink, marginTop: 6 }, actions: { flexDirection: 'row', gap: 10, marginTop: 20 }, again: { flex: 1, borderWidth: 1, borderColor: colors.line, borderRadius: 14, padding: 13, alignItems: 'center' }, againText: { color: colors.ink, fontWeight: '700' }, known: { flex: 1, backgroundColor: colors.accent, borderRadius: 14, padding: 13, alignItems: 'center' }, knownText: { color: colors.white, fontWeight: '700' }, empty: { backgroundColor: colors.white, borderRadius: 20, padding: 28, borderWidth: 1, borderColor: colors.line, alignItems: 'center' }, emptyTitle: { color: colors.ink, fontFamily: typography.display, fontSize: 20 }, emptyBody: { color: colors.muted, textAlign: 'center', lineHeight: 21, marginTop: 7 }, days: { minHeight: 105, flexDirection: 'row', alignItems: 'flex-end', gap: 5, marginTop: 14 }, day: { flex: 1, alignItems: 'center' }, bar: { width: '72%', minWidth: 3, backgroundColor: colors.accent, borderRadius: 3 }, dayLabel: { color: colors.muted, fontSize: 7, marginTop: 5, transform: [{ rotate: '-55deg' }] } });
