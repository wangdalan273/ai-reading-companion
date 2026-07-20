import type { ChatMessage } from '../types';

export type ThreadMessage = Omit<ChatMessage, 'id'> & {
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

export function visibleThreadMessages<T>(remote: T[], local: T[], isDraft: boolean): T[] {
  return isDraft ? local : [...remote, ...local];
}

export function removeConversationById<T extends { id: string | number }>(items: T[], id: string | number): T[] {
  return items.filter((item) => String(item.id) !== String(id));
}

export function consumeSseChunk(buffer: string, chunk: string): { buffer: string; tokens: string[]; done: boolean } {
  const frames = `${buffer}${chunk}`.split(/\r?\n\r?\n/);
  const remainder = frames.pop() ?? '';
  const tokens: string[] = [];
  let done = false;
  for (const frame of frames) {
    for (const line of frame.split(/\r?\n/)) {
      if (!line.startsWith('data:')) continue;
      const value = line.slice(5).trimStart();
      try {
        const parsed = JSON.parse(value) as string;
        if (parsed === '[DONE]') done = true;
        else tokens.push(parsed);
      } catch {}
    }
  }
  return { buffer: remainder, tokens, done };
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

export function formatMarkdownForReading(value: string): string {
  return value
    .replace(/```[a-zA-Z0-9_-]*\s*([\s\S]*?)```/g, '$1')
    .replace(/^[ \t]{0,3}#{1,6}[ \t]+/gm, '')
    .replace(/^[ \t]*>[ \t]?/gm, '｜')
    .replace(/^[ \t]*[-*+][ \t]+/gm, '• ')
    .replace(/!\[([^\]]*)\]\([^)]*\)/g, '$1')
    .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '$1（$2）')
    .replace(/(\*\*|__)(.*?)\1/g, '$2')
    .replace(/(?<!\*)\*([^*\n]+)\*(?!\*)/g, '$1')
    .replace(/(?<!_)_([^_\n]+)_(?!_)/g, '$1')
    .replace(/`([^`]+)`/g, '$1')
    .replace(/\n{3,}/g, '\n\n')
    .trim();
}
