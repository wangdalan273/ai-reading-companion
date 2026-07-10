import { useState, useRef, useEffect } from 'react';
import { View, StyleSheet, FlatList } from 'react-native';
import { TextInput, Button, Text, Card, ActivityIndicator } from 'react-native-paper';
import { useMutation } from '@tanstack/react-query';
import { askCompanionStream } from '../services';
import { companionRepo } from '../lib/sync/repository';
import { useAuthStore } from '../state/authStore';
import type { CompanionMessage } from '../types/models';

/**
 * 伴读 AI 对话（阶段 2 · SSE 流式 + 本地缓存）
 * - 进入时从本地库回显历史（离线可见）；在线时流式拉取。
 * - 发送后：user 气泡立即持久化本地；assistant 文本流式拼接，结束后再持久化本地，
 *   下次同步（云端 pull）会按 id 去重，避免重复气泡。
 */
export function CompanionScreen() {
  const uid = useAuthStore((s) => s.user?.id) ?? 0;
  const [messages, setMessages] = useState<CompanionMessage[]>([]);
  const [input, setInput] = useState('');
  const [streaming, setStreaming] = useState(false);
  const listRef = useRef<FlatList>(null);

  useEffect(() => {
    companionRepo
      .list()
      .then(setMessages)
      .catch(() => setMessages([]));
  }, []);

  const send = useMutation({
    mutationFn: async (q: string) => {
      const now = new Date().toISOString();
      const userMsg: CompanionMessage = {
        id: -Date.now(), user_id: uid, scope: 'all', role: 'user',
        content: q, created_at: now, updated_at: now, deleted_at: null,
      };
      const assistantId = -Date.now() - 1;
      const assistantMsg: CompanionMessage = {
        ...userMsg, id: assistantId, role: 'assistant', content: '',
      };
      setMessages((m) => [...m, userMsg, assistantMsg]);
      setStreaming(true);
      await companionRepo.append(userMsg);

      await askCompanionStream(
        { message: q },
        {
          onToken: (t) =>
            setMessages((m) =>
              m.map((msg) => (msg.id === assistantId ? { ...msg, content: msg.content + t } : msg))
            ),
          onDone: (full) => {
            const finalMsg: CompanionMessage = { ...assistantMsg, content: full };
            setMessages((m) =>
              m.map((msg) => (msg.id === assistantId ? finalMsg : msg))
            );
            companionRepo.append(finalMsg);
            setStreaming(false);
          },
          onError: () => {
            setStreaming(false);
            setMessages((m) =>
              m.map((msg) =>
                msg.id === assistantId && !msg.content
                  ? { ...msg, content: '（伴读请求失败，请检查网络或后端密钥）' }
                  : msg
              )
            );
          },
        }
      );
    },
  });

  function onSubmit() {
    const q = input.trim();
    if (!q || streaming) return;
    setInput('');
    send.mutate(q);
    setTimeout(() => listRef.current?.scrollToEnd({ animated: true }), 50);
  }

  return (
    <View style={styles.container}>
      <FlatList
        ref={listRef}
        data={messages}
        keyExtractor={(m) => String(m.id)}
        contentContainerStyle={styles.list}
        renderItem={({ item }) => (
          <Card
            style={[styles.bubble, item.role === 'user' ? styles.user : styles.assistant]}
          >
            <Text>{item.content || (item.role === 'assistant' ? '…' : '')}</Text>
          </Card>
        )}
        onContentSizeChange={() => listRef.current?.scrollToEnd({ animated: true })}
      />
      {streaming && <ActivityIndicator style={styles.streaming} />}
      <View style={styles.inputRow}>
        <TextInput
          value={input}
          onChangeText={setInput}
          placeholder="问问伴读…"
          style={styles.input}
          mode="outlined"
          onSubmitEditing={onSubmit}
        />
        <Button mode="contained" onPress={onSubmit} loading={streaming} disabled={streaming}>
          发送
        </Button>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#F6F5F8' },
  list: { padding: 12 },
  bubble: { padding: 12, marginBottom: 8, maxWidth: '85%' },
  user: { alignSelf: 'flex-end', backgroundColor: '#EADDFF' },
  assistant: { alignSelf: 'flex-start', backgroundColor: '#FFFFFF' },
  streaming: { marginVertical: 4 },
  inputRow: {
    flexDirection: 'row',
    alignItems: 'center',
    padding: 8,
    backgroundColor: '#FFFFFF',
  },
  input: { flex: 1, marginRight: 8 },
});
