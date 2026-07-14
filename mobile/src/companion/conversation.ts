import type { ChatMessage } from '../types';

export type ThreadMessage = ChatMessage & {
  id: string;
  saved: boolean;
  failed?: boolean;
};

let messageSequence = 0;

export function createThreadMessage(
  role: ThreadMessage['role'],
  content: string,
  id = `${Date.now()}-${messageSequence++}`,
  failed = false,
): ThreadMessage {
  return { id, role, content, saved: false, failed };
}

export function markThreadMessageSaved(messages: ThreadMessage[], id: string): ThreadMessage[] {
  return messages.map((message) => message.id === id ? { ...message, saved: true } : message);
}

function clipEnd(value: string, maxLength: number) {
  if (value.length <= maxLength) return value;
  return `${value.slice(0, Math.max(0, maxLength - 1))}…`;
}

export function buildConversationContext(
  source: string,
  messages: Pick<ThreadMessage, 'role' | 'content' | 'failed'>[],
  maxLength = 3800,
): string {
  const safeMax = Math.max(400, maxLength);
  const cleanSource = source.trim();
  const sourceBudget = cleanSource ? Math.min(1600, Math.floor(safeMax * 0.45)) : 0;
  const sourceSection = cleanSource ? `原文：\n${clipEnd(cleanSource, sourceBudget)}` : '';
  const transcript = messages
    .filter((message) => !message.failed && message.content.trim())
    .slice(-8)
    .map((message) => `${message.role === 'user' ? '读者' : 'AI'}：${message.content.trim()}`)
    .join('\n');

  if (!transcript) return clipEnd(sourceSection, safeMax);
  const historyHeader = sourceSection ? `${sourceSection}\n\n此前对话：\n` : '此前对话：\n';
  const available = safeMax - historyHeader.length;
  if (transcript.length <= available) return `${historyHeader}${transcript}`;
  return `${historyHeader}…${transcript.slice(-(available - 1))}`;
}

export function formatThreadForCollection(messages: ThreadMessage[], throughId: string): string {
  const end = messages.findIndex((message) => message.id === throughId);
  return messages
    .slice(0, end < 0 ? messages.length : end + 1)
    .filter((message) => !message.failed)
    .map((message) => `${message.role === 'user' ? '我的问题' : 'AI 回答'}：${message.content}`)
    .join('\n\n');
}
