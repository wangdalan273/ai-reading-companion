import { useMemo, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { FlatList, KeyboardAvoidingView, Platform, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { api } from '../api/client';
import type { ChatMessage } from '../types';
import { colors, typography } from '../theme';
import { buildConversationContext } from '../companion/conversation';

const STARTERS = ['帮我梳理最近阅读的核心观点', '用苏格拉底式问题考考我', '找出我笔记里的矛盾'];

export function CompanionScreen() {
  const listRef = useRef<FlatList<ChatMessage>>(null);
  const personas = useQuery({ queryKey: ['personas'], queryFn: api.personas });
  const [personaId, setPersonaId] = useState<number>();
  const history = useQuery({ queryKey: ['companion', personaId], queryFn: () => api.companionMessages(personaId) });
  const [local, setLocal] = useState<ChatMessage[]>([]);
  const [scope, setScope] = useState<'book' | 'vault' | 'all'>('all');
  const [text, setText] = useState('');
  const [sending, setSending] = useState(false);
  const messages = useMemo(() => [...(history.data ?? []), ...local], [history.data, local]);

  const send = async () => {
    const message = text.trim();
    if (!message || sending) return;
    const context = buildConversationContext('', messages);
    setText(''); setSending(true); setLocal((items) => [...items, { role: 'user', content: message }]);
    try {
      const answer = await api.askCompanion(message, personaId, scope, { context });
      setLocal((items) => [...items, { role: 'assistant', content: answer || '服务端没有返回内容，请稍后重试。' }]);
    } catch (error) {
      setLocal((items) => [...items, { role: 'assistant', content: error instanceof Error ? error.message : '伴读服务暂时不可用。' }]);
    } finally { setSending(false); }
  };

  return <SafeAreaView style={styles.safe} edges={['top']}>
    <KeyboardAvoidingView style={styles.keyboardPage} behavior={Platform.OS === 'ios' ? 'padding' : 'height'}>
      <View style={styles.header}><Text style={styles.eyebrow}>THINK TOGETHER</Text><Text style={styles.title}>AI 伴读</Text><Text style={styles.subtitle}>跨书检索、连续追问与苏格拉底式思考</Text></View>
      <View style={styles.pills}>
        <FlatList horizontal showsHorizontalScrollIndicator={false} keyboardShouldPersistTaps="handled" data={personas.data ?? []} keyExtractor={(item) => String(item.id)} contentContainerStyle={styles.pillRow} renderItem={({ item }) => <Pressable onPress={() => { setPersonaId(item.id); setLocal([]); }} style={[styles.pill, personaId === item.id && styles.pillActive]}><Text style={[styles.pillText, personaId === item.id && styles.pillTextActive]}>{item.name}</Text></Pressable>} />
        <View style={styles.scopeRow}>{(['book', 'vault', 'all'] as const).map((item) => <Pressable key={item} onPress={() => setScope(item)}><Text style={[styles.scope, scope === item && styles.scopeActive]}>{item === 'book' ? '本书' : item === 'vault' ? '笔记库' : '全部知识'}</Text></Pressable>)}</View>
      </View>
      <FlatList ref={listRef} data={messages} keyExtractor={(_, index) => String(index)} keyboardShouldPersistTaps="handled" keyboardDismissMode="on-drag" onContentSizeChange={() => listRef.current?.scrollToEnd({ animated: true })} contentContainerStyle={styles.messages} renderItem={({ item }) => <View style={[styles.bubble, item.role === 'user' ? styles.userBubble : styles.aiBubble]}><Text selectable={item.role === 'assistant'} style={[styles.message, item.role === 'user' && styles.userMessage]}>{item.content}</Text></View>} ListEmptyComponent={<View style={styles.empty}><Text style={styles.emptyTitle}>从一个真实问题开始</Text><Text style={styles.emptyBody}>伴读会记住当前对话，继续追问时不必重复背景。</Text><View style={styles.starterList}>{STARTERS.map((starter) => <Pressable key={starter} onPress={() => setText(starter)} style={styles.starter}><Text style={styles.starterText}>{starter}</Text></Pressable>)}</View></View>} />
      <View style={styles.composer}><TextInput multiline value={text} onChangeText={setText} onFocus={() => setTimeout(() => listRef.current?.scrollToEnd({ animated: true }), 180)} placeholder="写下问题，或继续追问…" placeholderTextColor={colors.muted} style={styles.input} /><Pressable disabled={sending || !text.trim()} onPress={() => void send()} style={[styles.send, (sending || !text.trim()) && styles.sendDisabled]}><Text style={styles.sendText}>{sending ? '思考中' : '发送'}</Text></Pressable></View>
    </KeyboardAvoidingView>
  </SafeAreaView>;
}

const styles = StyleSheet.create({ safe: { flex: 1, backgroundColor: colors.paper }, keyboardPage: { flex: 1 }, header: { padding: 22, paddingBottom: 12 }, eyebrow: { color: colors.accent, fontSize: 10, letterSpacing: 2, fontWeight: '800' }, title: { color: colors.ink, fontFamily: typography.display, fontSize: 31, marginTop: 4 }, subtitle: { color: colors.muted, marginTop: 5 }, pills: { borderBottomWidth: 1, borderColor: colors.line, paddingBottom: 12 }, pillRow: { paddingHorizontal: 18, gap: 8 }, pill: { borderWidth: 1, borderColor: colors.line, borderRadius: 18, paddingHorizontal: 15, paddingVertical: 8, backgroundColor: colors.white }, pillActive: { backgroundColor: colors.ink, borderColor: colors.ink }, pillText: { color: colors.ink, fontWeight: '600' }, pillTextActive: { color: colors.paper }, scopeRow: { flexDirection: 'row', gap: 18, paddingHorizontal: 22, paddingTop: 12 }, scope: { color: colors.muted, fontSize: 12 }, scopeActive: { color: colors.accent, fontWeight: '800' }, messages: { padding: 16, gap: 12, flexGrow: 1 }, bubble: { maxWidth: '88%', padding: 14, borderRadius: 17 }, userBubble: { alignSelf: 'flex-end', backgroundColor: colors.ink, borderBottomRightRadius: 5 }, aiBubble: { alignSelf: 'flex-start', backgroundColor: colors.white, borderWidth: 1, borderColor: colors.line, borderBottomLeftRadius: 5 }, message: { color: colors.ink, lineHeight: 22 }, userMessage: { color: colors.white }, empty: { flex: 1, minHeight: 250, alignItems: 'center', justifyContent: 'center', padding: 30 }, emptyTitle: { fontFamily: typography.display, fontSize: 21, color: colors.ink }, emptyBody: { color: colors.muted, marginTop: 8, textAlign: 'center', lineHeight: 20 }, starterList: { alignSelf: 'stretch', gap: 7, marginTop: 18 }, starter: { borderWidth: 1, borderColor: colors.line, backgroundColor: colors.white, borderRadius: 14, padding: 12 }, starterText: { color: colors.ink, textAlign: 'center', fontSize: 12, fontWeight: '700' }, composer: { flexDirection: 'row', alignItems: 'flex-end', padding: 12, gap: 10, backgroundColor: colors.white, borderTopWidth: 1, borderColor: colors.line }, input: { flex: 1, minHeight: 46, maxHeight: 120, borderRadius: 15, backgroundColor: colors.paper, paddingHorizontal: 14, paddingVertical: 12, color: colors.ink, textAlignVertical: 'top' }, send: { backgroundColor: colors.accent, borderRadius: 16, height: 46, paddingHorizontal: 17, alignItems: 'center', justifyContent: 'center' }, sendDisabled: { opacity: .42 }, sendText: { color: colors.white, fontWeight: '800' } });
