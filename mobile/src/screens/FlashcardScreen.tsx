import { useState } from 'react';
import { View, StyleSheet } from 'react-native';
import { Card, Button, Text, ActivityIndicator } from 'react-native-paper';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { flashcardsRepo } from '../lib/sync/repository';
import type { Flashcard } from '../types/models';

/**
 * 闪卡复习（阶段 2 · 本地优先）
 * 读：本地 SQLite（离线可见）。写：间隔重复算法乐观更新本地，再上行 /v1/flashcards/{id}/review；
 * 失败则进离线队列，下次同步重放。
 */
export function FlashcardScreen() {
  const qc = useQueryClient();
  const [flipped, setFlipped] = useState(false);

  const { data, isLoading } = useQuery<Flashcard[]>({
    queryKey: ['flashcards', 'due'],
    queryFn: flashcardsRepo.due,
  });

  const review = useMutation({
    mutationFn: (vars: { id: number; known: boolean }) => flashcardsRepo.review(vars.id, vars.known),
    onSuccess: () => {
      setFlipped(false);
      qc.invalidateQueries({ queryKey: ['flashcards', 'due'] });
    },
  });

  if (isLoading) return <ActivityIndicator style={styles.center} />;
  const card = data?.[0];

  return (
    <View style={styles.container}>
      {!card ? (
        <Text style={styles.center}>今天没有到期闪卡 🎉</Text>
      ) : (
        <Card style={styles.card} onLongPress={() => setFlipped((f) => !f)}>
          <View style={styles.body}>
            <Text variant="titleMedium">{card.front}</Text>
            {flipped && (
              <Text variant="bodyLarge" style={styles.back}>
                {card.back}
              </Text>
            )}
            <Text variant="labelSmall" style={styles.hint}>
              {flipped ? '选认识 / 不认识' : '长按看答案'}
            </Text>
          </View>
        </Card>
      )}
      {card && flipped && (
        <View style={styles.actions}>
          <Button
            mode="outlined"
            onPress={() => review.mutate({ id: card.id, known: false })}
            loading={review.isPending}
          >
            不认识
          </Button>
          <Button
            mode="contained"
            onPress={() => review.mutate({ id: card.id, known: true })}
            loading={review.isPending}
          >
            认识
          </Button>
        </View>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#F6F5F8', padding: 16 },
  center: { flex: 1, textAlign: 'center', marginTop: 80 },
  card: { marginBottom: 16 },
  body: { padding: 24, minHeight: 160, justifyContent: 'center' },
  back: { marginTop: 16, color: '#6750A4' },
  hint: { marginTop: 12, color: '#79747E' },
  actions: { flexDirection: 'row', justifyContent: 'space-around' },
});
