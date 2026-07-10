// 移动端业务服务层：把 /v1 端点收口成 typed 函数，屏幕只调这里。
// 离线优先：读操作优先走同步引擎的本地镜像（见 lib/sync/engine），
// 这里负责「在线时」的实时读写与 SSE 流式对话。
import { api, ApiError, API_BASE } from '../lib/api/client';
import { tokenStore } from '../lib/auth/tokenStore';
import type {
  Book,
  Annotation,
  Flashcard,
  ReadingStats,
  Persona,
  CompanionMessage,
} from '../types/models';

// ── 书籍 ──────────────────────────────────────────────────────────────
export const fetchBooks = () => api.get<Book[]>('/books');
export const fetchBook = (id: number | string) => api.get<Book>(`/books/${id}`);
export const fetchBookFileUrl = (id: number | string) =>
  `${API_BASE}/v1/books/${id}/file`;

// ── 划线 ──────────────────────────────────────────────────────────────
export const fetchAnnotations = (bookId: number | string) =>
  api.get<{ annotations: Annotation[] }>(`/books/${bookId}/annotations`)
    .then((r) => r.annotations);

export async function addAnnotation(
  bookId: number | string,
  payload: { loc: string; quote: string; tag?: string; note?: string }
) {
  return api.post<{ ok: boolean; id: number }>(
    `/books/${bookId}/annotations`,
    payload
  );
}

export const deleteAnnotation = (bookId: number | string, annId: number) =>
  api.del(`/books/${bookId}/annotations/${annId}`);

// ── 闪卡 ──────────────────────────────────────────────────────────────
export const fetchDueFlashcards = () =>
  api.get<{ cards: Flashcard[] }>('/flashcards/due').then((r) => r.cards);

export const reviewFlashcard = (id: number, known: boolean) =>
  api.post(`/flashcards/${id}/review`, { known });

export const deleteFlashcard = (id: number) =>
  api.del(`/flashcards/${id}`);

export const createFlashcard = (bookId: number | string, quote: string) =>
  api.post<{ ok: boolean; id: number }>(`/books/${bookId}/flashcards`, {
    quote,
  });

// ── 阅读时长 ──────────────────────────────────────────────────────────
export const logReading = (bookId: number | string, seconds: number) =>
  api.post<{ ok: boolean; total_today: number }>('/reading/log', {
    book_id: bookId,
    seconds,
  });

// ── 统计 ──────────────────────────────────────────────────────────────
export const fetchStats = () => api.get<ReadingStats>('/reading/stats');

// ── 伴读 AI 对话 ───────────────────────────────────────────────────────
export const fetchPersonas = () =>
  api.get<{ ok: boolean; personas: Persona[] }>('/companion/personas')
    .then((r) => r.personas);

export const fetchCompanionMessages = () =>
  api
    .get<{ ok: boolean; messages: CompanionMessage[] }>('/companion/messages')
    .then((r) => r.messages);

export interface AskPayload {
  message: string;
  book_id?: number;
  conversation_id?: number;
  mode?: 'devil' | 'socratic';
  persona_id?: number;
  scope?: 'book' | 'vault' | 'all';
  context?: string;
}

/**
 * SSE 流式提问：逐 token 回调 onToken，结束时 onDone。
 * 后端 CompanionController::ask 以 `data: "<token>"\n\n` 格式推送，
 * 末尾 `data: "[DONE]"` 收束。此处按 SSE 规范逐行解析 JSON 片段并拼接。
 */
export async function askCompanionStream(
  payload: AskPayload,
  handlers: {
    onToken: (token: string) => void;
    onDone?: (full: string) => void;
    onError?: (err: Error) => void;
  }
): Promise<void> {
  const token = await tokenStore.get();
  const headers = new Headers({
    'Content-Type': 'application/json',
    Accept: 'text/event-stream',
  });
  if (token) headers.set('Authorization', `Bearer ${token}`);

  let res: Response;
  try {
    res = await fetch(`${API_BASE}/v1/companion/ask`, {
      method: 'POST',
      headers,
      body: JSON.stringify(payload),
    });
  } catch (e) {
    handlers.onError?.(e as Error);
    return;
  }

  if (!res.ok || !res.body) {
    handlers.onError?.(new ApiError(res.status, '伴读请求失败'));
    return;
  }

  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';
  let full = '';

  try {
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });

      const events = buffer.split('\n\n');
      buffer = events.pop() ?? '';

      for (const evt of events) {
        const line = evt.trim();
        if (!line.startsWith('data:')) continue;
        const data = line.slice(5).trim();
        if (!data) continue;
        try {
          const parsed = JSON.parse(data);
          if (parsed === '[DONE]') {
            handlers.onDone?.(full);
            return;
          }
          full += parsed;
          handlers.onToken(parsed);
        } catch {
          // 跳过不完整/非法片段，避免中断整段流
        }
      }
    }
    handlers.onDone?.(full);
  } catch (e) {
    handlers.onError?.(e as Error);
  }
}
