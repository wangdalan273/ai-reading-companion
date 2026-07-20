import { useMemo, useRef, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ActivityIndicator, Alert, FlatList, KeyboardAvoidingView, Modal, Platform, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { api } from '../api/client';
import type { ChatMessage } from '../types';
import { colors, typography } from '../theme';
import { buildConversationContext, formatMarkdownForReading, removeConversationById, visibleThreadMessages } from '../companion/conversation';

const STARTERS = ['帮我梳理最近阅读的核心观点', '用苏格拉底式问题考考我', '找出我笔记里的矛盾'];
const createMobileThreadId = () => `mobile-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;

export function CompanionScreen() {
  const queryClient = useQueryClient();
  const listRef = useRef<FlatList<ChatMessage>>(null);
  const personas = useQuery({ queryKey: ['personas'], queryFn: api.personas });
  const [personaId, setPersonaId] = useState<number>();
  const [threadId, setThreadId] = useState<string>(() => createMobileThreadId());
  const [isDraft, setIsDraft] = useState(true);
  const activePersonaId = personaId ?? personas.data?.find((item) => item.is_default)?.id ?? personas.data?.[0]?.id;
  const history = useQuery({
    queryKey: ['companion', activePersonaId, threadId],
    queryFn: () => api.companionMessages(activePersonaId, threadId),
    enabled: personas.isSuccess,
  });
  const [local, setLocal] = useState<ChatMessage[]>([]);
  const [scope, setScope] = useState<'book' | 'vault' | 'all'>('all');
  const [text, setText] = useState('');
  const [sending, setSending] = useState(false);
  const [savingMessage, setSavingMessage] = useState<string>();
  const [savedMessages, setSavedMessages] = useState<Set<string>>(() => new Set());
  const [historyOpen, setHistoryOpen] = useState(false);
  const [deletingThreadId, setDeletingThreadId] = useState<string>();
  const messages = useMemo(() => visibleThreadMessages(history.data?.messages ?? [], local, isDraft), [history.data, isDraft, local]);
  const threads = history.data?.threads ?? [];
  const activeThread = threads.find((item) => item.id === threadId);

  const newConversation = () => {
    setThreadId(createMobileThreadId());
    setIsDraft(true);
    setLocal([]);
    setText('');
  };

  const messageKey = (item: ChatMessage, index: number) => `${threadId ?? 'draft'}:${item.id ?? index}:${item.role}`;

  const deleteThread = (id: string) => Alert.alert('删除这段对话？', '问题和回答会从账号中永久删除，笔记库里已保存的内容不受影响。', [
    { text: '取消', style: 'cancel' },
    { text: '删除', style: 'destructive', onPress: async () => {
      setDeletingThreadId(id);
      try {
        await api.deleteCompanionThread(id);
        queryClient.setQueriesData<Awaited<ReturnType<typeof api.companionMessages>>>({ queryKey: ['companion'] }, (current) => current ? {
          ...current,
          threads: removeConversationById(current.threads, id),
          messages: current.active_thread_id === id ? [] : current.messages,
          active_thread_id: current.active_thread_id === id ? null : current.active_thread_id,
        } : current);
        if (threadId === id) newConversation();
        await queryClient.invalidateQueries({ queryKey: ['companion'] });
      } catch (error) {
        Alert.alert('删除失败', error instanceof Error ? error.message : '请检查网络后重试。');
      } finally {
        setDeletingThreadId(undefined);
      }
    } },
  ]);

  const saveToNotebook = async (item: ChatMessage, index: number) => {
    const key = messageKey(item, index);
    if (item.role !== 'assistant' || savedMessages.has(key)) return;
    const question = [...messages.slice(0, index)].reverse().find((message) => message.role === 'user')?.content;
    setSavingMessage(key);
    try {
      await api.addToKnowledgeBase({
        title: question ? question.slice(0, 60) : '伴读收藏',
        content: `${question ? `我的问题：${question}\n\n` : ''}AI 回答：${item.content}`,
      });
      setSavedMessages((current) => new Set(current).add(key));
      void queryClient.invalidateQueries({ queryKey: ['knowledge-notes'] });
      void queryClient.invalidateQueries({ queryKey: ['saved-content'] });
      void queryClient.invalidateQueries({ queryKey: ['unified-library'] });
    } finally { setSavingMessage(undefined); }
  };

  const send = async () => {
    const message = text.trim();
    if (!message || sending) return;
    const context = buildConversationContext('', messages);
    const userId = `local-user-${Date.now()}`;
    const answerId = `local-ai-${Date.now()}`;
    setText(''); setSending(true); setLocal((items) => [...items, { id: userId, role: 'user', content: message }, { id: answerId, role: 'assistant', content: '' }]);
    try {
      const activeThreadId = threadId ?? createMobileThreadId();
      if (!threadId) setThreadId(activeThreadId);
      const answer = await api.askCompanion(message, activePersonaId, scope, {
        context, threadId: activeThreadId,
        onDelta: (current) => setLocal((items) => items.map((item) => item.id === answerId ? { ...item, content: current } : item)),
      });
      if (!answer) setLocal((items) => items.map((item) => item.id === answerId ? { ...item, content: '服务端没有返回内容，请稍后重试。' } : item));
      await queryClient.fetchQuery({
        queryKey: ['companion', activePersonaId, activeThreadId],
        queryFn: () => api.companionMessages(activePersonaId, activeThreadId),
      });
      setIsDraft(false);
      setLocal([]);
    } catch (error) {
      setLocal((items) => items.map((item) => item.id === answerId ? { ...item, content: error instanceof Error ? error.message : '伴读服务暂时不可用。' } : item));
    } finally { setSending(false); }
  };

  return <SafeAreaView style={styles.safe} edges={['top']}>
    <KeyboardAvoidingView style={styles.keyboardPage} behavior={Platform.OS === 'ios' ? 'padding' : 'height'}>
      <View style={styles.header}><Text style={styles.eyebrow}>THINK TOGETHER</Text><Text style={styles.title}>AI 伴读</Text><Text style={styles.subtitle}>跨书检索、连续追问与苏格拉底式思考</Text></View>
      <View style={styles.pills}>
        <FlatList horizontal showsHorizontalScrollIndicator={false} keyboardShouldPersistTaps="handled" data={personas.data ?? []} keyExtractor={(item) => String(item.id)} contentContainerStyle={styles.pillRow} renderItem={({ item }) => <Pressable onPress={() => { setPersonaId(item.id); setThreadId(createMobileThreadId()); setIsDraft(true); setLocal([]); }} style={[styles.pill, activePersonaId === item.id && styles.pillActive]}><Text style={[styles.pillText, activePersonaId === item.id && styles.pillTextActive]}>{item.name}</Text></Pressable>} />
        <View style={styles.scopeRow}>{(['book', 'vault', 'all'] as const).map((item) => <Pressable key={item} onPress={() => setScope(item)}><Text style={[styles.scope, scope === item && styles.scopeActive]}>{item === 'book' ? '本书' : item === 'vault' ? '笔记库' : '全部知识'}</Text></Pressable>)}</View>
        <View style={styles.threadBar}>
          <View style={styles.currentThread}><Text style={styles.threadLabel}>{isDraft ? '新对话' : '当前对话'}</Text><Text numberOfLines={1} style={styles.currentThreadTitle}>{activeThread?.title ?? (isDraft ? '等待第一条消息' : '未命名对话')}</Text></View>
          <Pressable onPress={() => setHistoryOpen(true)} style={styles.historyButton}><Text style={styles.historyButtonText}>历史 {threads.length}</Text></Pressable>
          <Pressable onPress={newConversation} style={styles.newThread}><Text style={styles.newThreadText}>新建</Text></Pressable>
        </View>
      </View>
      <FlatList ref={listRef} data={messages} keyExtractor={(item, index) => messageKey(item, index)} keyboardShouldPersistTaps="handled" keyboardDismissMode="on-drag" onContentSizeChange={() => listRef.current?.scrollToEnd({ animated: true })} contentContainerStyle={styles.messages} renderItem={({ item, index }) => {
        const key = messageKey(item, index);
        return <View style={[styles.bubble, item.role === 'user' ? styles.userBubble : styles.aiBubble]}><Text selectable={item.role === 'assistant'} style={[styles.message, item.role === 'user' && styles.userMessage]}>{item.role === 'assistant' ? formatMarkdownForReading(item.content) : item.content}</Text>{item.role === 'assistant' && <Pressable disabled={!!savingMessage || savedMessages.has(key)} onPress={() => void saveToNotebook(item, index)} style={styles.saveNote}><Text style={styles.saveNoteText}>{savedMessages.has(key) ? '已保存到笔记库' : savingMessage === key ? '保存中…' : '保存到笔记库'}</Text></Pressable>}</View>;
      }} ListEmptyComponent={<View style={styles.empty}><Text style={styles.emptyTitle}>从一个真实问题开始</Text><Text style={styles.emptyBody}>伴读会记住当前对话，继续追问时不必重复背景。</Text><View style={styles.starterList}>{STARTERS.map((starter) => <Pressable key={starter} onPress={() => setText(starter)} style={styles.starter}><Text style={styles.starterText}>{starter}</Text></Pressable>)}</View></View>} />
      <View style={styles.composer}><TextInput multiline value={text} onChangeText={setText} onFocus={() => setTimeout(() => listRef.current?.scrollToEnd({ animated: true }), 180)} placeholder="写下问题，或继续追问…" placeholderTextColor={colors.muted} style={styles.input} /><Pressable disabled={sending || !text.trim()} onPress={() => void send()} style={[styles.send, (sending || !text.trim()) && styles.sendDisabled]}><Text style={styles.sendText}>{sending ? '思考中' : '发送'}</Text></Pressable></View>
    </KeyboardAvoidingView>
    <Modal visible={historyOpen} transparent animationType="slide" onRequestClose={() => setHistoryOpen(false)}>
      <View style={styles.historyShade}><View style={styles.historySheet}>
        <View style={styles.historyHead}><View><Text style={styles.historyKicker}>对话管理</Text><Text style={styles.historyTitle}>历史对话</Text></View><Pressable onPress={() => setHistoryOpen(false)} style={styles.historyClose}><Text style={styles.historyCloseText}>关闭</Text></Pressable></View>
        <FlatList style={styles.historyScroll} data={threads} keyExtractor={(item) => item.id} contentContainerStyle={styles.historyList} ListEmptyComponent={<View style={styles.historyEmpty}><Text style={styles.historyEmptyTitle}>还没有历史对话</Text><Text style={styles.historyEmptyText}>发送第一条消息后，会在这里保存。</Text></View>} renderItem={({ item }) => <View style={[styles.historyItem, threadId === item.id && styles.historyItemActive]}>
          <Pressable onPress={() => { setThreadId(item.id); setIsDraft(false); setLocal([]); setHistoryOpen(false); }} style={styles.historySelect}><Text style={styles.historyItemMeta}>{threadId === item.id ? '当前对话' : '历史对话'}</Text><Text numberOfLines={2} style={styles.historyItemTitle}>{item.title}</Text></Pressable>
          <Pressable disabled={!!deletingThreadId} onPress={() => deleteThread(item.id)} style={styles.historyDelete}>{deletingThreadId === item.id ? <ActivityIndicator size="small" color={colors.danger} /> : <Text style={styles.historyDeleteText}>删除</Text>}</Pressable>
        </View>} />
        <Pressable onPress={() => { newConversation(); setHistoryOpen(false); }} style={styles.historyNew}><Text style={styles.historyNewText}>开始新对话</Text></Pressable>
      </View></View>
    </Modal>
  </SafeAreaView>;
}

const styles = StyleSheet.create({ safe: { flex: 1, backgroundColor: colors.paper }, keyboardPage: { flex: 1 }, header: { padding: 22, paddingBottom: 12 }, eyebrow: { color: colors.accent, fontSize: 10, letterSpacing: 2, fontWeight: '800' }, title: { color: colors.ink, fontFamily: typography.display, fontSize: 31, marginTop: 4 }, subtitle: { color: colors.muted, marginTop: 5 }, pills: { borderBottomWidth: 1, borderColor: colors.line, paddingBottom: 12 }, pillRow: { paddingHorizontal: 18, gap: 8 }, pill: { borderWidth: 1, borderColor: colors.line, borderRadius: 18, paddingHorizontal: 15, paddingVertical: 8, backgroundColor: colors.white }, pillActive: { backgroundColor: colors.ink, borderColor: colors.ink }, pillText: { color: colors.ink, fontWeight: '600' }, pillTextActive: { color: colors.paper }, scopeRow: { flexDirection: 'row', gap: 18, paddingHorizontal: 22, paddingTop: 12 }, scope: { color: colors.muted, fontSize: 12 }, scopeActive: { color: colors.accent, fontWeight: '800' }, threadBar: { marginTop: 12, paddingHorizontal: 18, flexDirection: 'row', alignItems: 'center', gap: 8 }, currentThread: { flex: 1, minWidth: 0 }, threadLabel: { color: colors.muted, fontSize: 9, fontWeight: '800' }, currentThreadTitle: { color: colors.ink, fontSize: 12, fontWeight: '800', marginTop: 2 }, historyButton: { minHeight: 40, justifyContent: 'center', borderWidth: 1, borderColor: colors.line, borderRadius: 13, paddingHorizontal: 12, backgroundColor: colors.white }, historyButtonText: { color: colors.ink, fontSize: 11, fontWeight: '800' }, newThread: { minHeight: 40, justifyContent: 'center', backgroundColor: colors.accentSoft, borderRadius: 13, paddingHorizontal: 12 }, newThreadText: { color: colors.accent, fontSize: 11, fontWeight: '900' }, messages: { padding: 16, gap: 12, flexGrow: 1 }, bubble: { maxWidth: '88%', padding: 14, borderRadius: 17 }, userBubble: { alignSelf: 'flex-end', backgroundColor: colors.ink, borderBottomRightRadius: 5 }, aiBubble: { alignSelf: 'flex-start', backgroundColor: colors.white, borderWidth: 1, borderColor: colors.line, borderBottomLeftRadius: 5 }, message: { color: colors.ink, lineHeight: 22 }, userMessage: { color: colors.white }, saveNote: { marginTop: 9, paddingTop: 8, borderTopWidth: 1, borderTopColor: colors.line }, saveNoteText: { color: colors.accent, fontSize: 10, fontWeight: '900' }, empty: { flex: 1, minHeight: 250, alignItems: 'center', justifyContent: 'center', padding: 30 }, emptyTitle: { fontFamily: typography.display, fontSize: 21, color: colors.ink }, emptyBody: { color: colors.muted, marginTop: 8, textAlign: 'center', lineHeight: 20 }, starterList: { alignSelf: 'stretch', gap: 7, marginTop: 18 }, starter: { borderWidth: 1, borderColor: colors.line, backgroundColor: colors.white, borderRadius: 14, padding: 12 }, starterText: { color: colors.ink, textAlign: 'center', fontSize: 12, fontWeight: '700' }, composer: { flexDirection: 'row', alignItems: 'flex-end', padding: 12, gap: 10, backgroundColor: colors.white, borderTopWidth: 1, borderColor: colors.line }, input: { flex: 1, minHeight: 46, maxHeight: 120, borderRadius: 15, backgroundColor: colors.paper, paddingHorizontal: 14, paddingVertical: 12, color: colors.ink, textAlignVertical: 'top' }, send: { backgroundColor: colors.accent, borderRadius: 16, height: 46, paddingHorizontal: 17, alignItems: 'center', justifyContent: 'center' }, sendDisabled: { opacity: .42 }, sendText: { color: colors.white, fontWeight: '800' }, historyShade: { flex: 1, backgroundColor: 'rgba(20,17,14,.45)', justifyContent: 'flex-end' }, historySheet: { maxHeight: '72%', minHeight: 320, backgroundColor: colors.paper, borderTopLeftRadius: 26, borderTopRightRadius: 26, paddingTop: 20, overflow: 'hidden' }, historyHead: { paddingHorizontal: 20, paddingBottom: 14, flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', borderBottomWidth: 1, borderBottomColor: colors.line }, historyKicker: { color: colors.accent, fontSize: 10, fontWeight: '900', letterSpacing: 1.5 }, historyTitle: { color: colors.ink, fontFamily: typography.display, fontSize: 25, marginTop: 3 }, historyClose: { minWidth: 44, minHeight: 44, alignItems: 'center', justifyContent: 'center' }, historyCloseText: { color: colors.accent, fontWeight: '800' }, historyScroll: { flex: 1 }, historyList: { padding: 16, gap: 9, flexGrow: 1 }, historyItem: { minHeight: 68, borderWidth: 1, borderColor: colors.line, backgroundColor: colors.white, borderRadius: 16, flexDirection: 'row', alignItems: 'center' }, historyItemActive: { borderColor: colors.accent }, historySelect: { flex: 1, paddingHorizontal: 14, paddingVertical: 12 }, historyItemMeta: { color: colors.accent, fontSize: 9, fontWeight: '900' }, historyItemTitle: { color: colors.ink, fontSize: 13, lineHeight: 19, marginTop: 3 }, historyDelete: { minWidth: 58, minHeight: 48, alignItems: 'center', justifyContent: 'center', borderLeftWidth: 1, borderLeftColor: colors.line }, historyDeleteText: { color: colors.danger, fontSize: 11, fontWeight: '900' }, historyEmpty: { minHeight: 180, alignItems: 'center', justifyContent: 'center' }, historyEmptyTitle: { color: colors.ink, fontFamily: typography.display, fontSize: 20 }, historyEmptyText: { color: colors.muted, fontSize: 12, marginTop: 7 }, historyNew: { margin: 16, minHeight: 48, borderRadius: 15, backgroundColor: colors.accent, alignItems: 'center', justifyContent: 'center' }, historyNewText: { color: colors.white, fontWeight: '900' } });
