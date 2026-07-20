import * as SecureStore from 'expo-secure-store';
import type { AiSettingsDraft, AiSettingsPayload, Annotation, Book, BookConversation, ChatMessage, Flashcard, KnowledgeNote, Persona, ReadingStats, User } from '../types';
import type { ReadingSession } from '../reader/readingSession';
import { consumeSseChunk } from '../companion/conversation';

const configuredApiOrigin = process.env.EXPO_PUBLIC_API_ORIGIN?.trim();
const productionApiOrigin = 'https://read.sxmnq.art';

// A missing build-time variable must remain safe on a physical device. Local
// emulator development can still override this with EXPO_PUBLIC_API_ORIGIN.
export const API_ORIGIN = (configuredApiOrigin || productionApiOrigin).replace(/\/+$/, '');
export const API_ROOT = `${API_ORIGIN}/api/v1`;
const TOKEN_KEY = 'reading-companion-token-v2';

export const tokenStore = {
  get: () => SecureStore.getItemAsync(TOKEN_KEY),
  set: (token: string) => SecureStore.setItemAsync(TOKEN_KEY, token),
  clear: () => SecureStore.deleteItemAsync(TOKEN_KEY),
};

export class ApiError extends Error {
  constructor(public status: number, message: string) {
    super(message);
  }
}

export async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const token = await tokenStore.get();
  const isForm = typeof FormData !== 'undefined' && init.body instanceof FormData;
  const response = await fetch(`${API_ROOT}${path}`, {
    ...init,
    headers: {
      Accept: 'application/json',
      ...(!isForm ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...init.headers,
    },
  });
  if (!response.ok) {
    let message = `请求失败（${response.status}）`;
    try {
      const body = await response.json();
      message = body.message ?? message;
    } catch {}
    throw new ApiError(response.status, message);
  }
  if (response.status === 204) return undefined as T;
  return response.json() as Promise<T>;
}

const wait = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

type ReadingStateResponse = {
  state: null | {
    book_id: number; format: 'epub' | 'pdf'; locator?: string | null; page?: number | null;
    total_pages?: number | null; progress: number; section_title?: string | null;
    bookmarks?: ReadingSession['bookmarks'] | null; client_updated_at?: string | null;
  };
  stale?: boolean;
};

function normalizeReadingState(payload: ReadingStateResponse): ReadingSession | null {
  const state = payload.state;
  if (!state) return null;
  return {
    version: 1, bookId: state.book_id, format: state.format,
    locator: state.locator || undefined, page: state.page || undefined,
    totalPages: state.total_pages || undefined, progress: Number(state.progress) || 0,
    sectionTitle: state.section_title || undefined, bookmarks: state.bookmarks ?? [],
    updatedAt: state.client_updated_at || undefined,
  };
}

async function generatedBookTool(bookId: number, path: string, resultKey: 'graph' | 'map'): Promise<Record<string, unknown>> {
  const endpoint = `/book/${bookId}/${path}`;
  const hasResult = (value: Record<string, unknown>) => value.status === 'done' && !!value[resultKey];
  let latest: Record<string, unknown> = await request<Record<string, unknown>>(endpoint).catch(() => ({}));
  if (hasResult(latest)) return latest;

  if (latest.status !== 'working') {
    try {
      latest = await request<Record<string, unknown>>(endpoint, { method: 'POST', body: '{}' });
      if (hasResult(latest) || latest[resultKey]) return latest;
    } catch {
      // Nginx/CDN can time out while PHP continues and persists the result.
      // Polling the lightweight GET endpoint recovers that completed work.
    }
  }

  for (let attempt = 0; attempt < 40; attempt += 1) {
    await wait(2500);
    latest = await request<Record<string, unknown>>(endpoint).catch(() => latest);
    if (hasResult(latest)) return latest;
    if (latest.status === 'failed') throw new Error(String(latest.error || '生成失败，请稍后重试。'));
  }
  throw new Error('内容仍在生成中。你可以先返回阅读，稍后再次打开会直接显示已完成结果。');
}

export const api = {
  async login(email: string, password: string) {
    const result = await request<{ token: string; user: User }>('/login', {
      method: 'POST', body: JSON.stringify({ email, password }),
    });
    await tokenStore.set(result.token);
    return result.user;
  },
  async register(name: string, email: string, password: string) {
    const result = await request<{ token: string; user: User }>('/register', {
      method: 'POST', body: JSON.stringify({ name, email, password, password_confirmation: password }),
    });
    await tokenStore.set(result.token);
    return result.user;
  },
  me: () => request<User>('/me'),
  sync: () => request<Record<string, unknown[] | string>>('/sync'),
  books: () => request<Book[]>('/books'),
  uploadBook: (form: FormData) => request<Book>('/books', { method: 'POST', body: form }),
  bookFileUrl: (id: number) => `${API_ROOT}/books/${id}/file`,
  personas: async () => (await request<{ personas: Persona[] }>('/companion/personas')).personas,
  companionMessages: (personaId?: number, threadId?: string) => {
    const params = new URLSearchParams();
    if (personaId) params.set('persona_id', String(personaId));
    if (threadId) params.set('thread_id', threadId);
    return request<{ active_thread_id?: string | null; threads: { id: string; title: string }[]; messages: ChatMessage[] }>(`/companion/messages${params.size ? `?${params}` : ''}`);
  },
  bookChatHistory: async (bookId: number, conversationId?: number) => (await request<{ messages: ChatMessage[] }>(`/companion/history?book_id=${bookId}${conversationId ? `&conversation_id=${conversationId}` : ''}`)).messages,
  bookConversations: async (bookId: number) => (await request<{ conversations: BookConversation[] }>(`/book/${bookId}/conversations`)).conversations,
  createBookConversation: async (bookId: number, title: string) => (await request<{ conversation: BookConversation }>(`/book/${bookId}/conversations`, { method: 'POST', body: JSON.stringify({ title }) })).conversation,
  deleteBookConversation: (conversationId: number) => request<{ ok: boolean }>(`/conversations/${conversationId}`, { method: 'DELETE' }),
  deleteCompanionThread: (threadId: string) => request<{ ok: boolean; deleted: number }>(`/companion/threads/${encodeURIComponent(threadId)}`, { method: 'DELETE' }),
  async askCompanion(message: string, personaId: number | undefined, scope: 'book' | 'vault' | 'all', options: { bookId?: number; context?: string; threadId?: string; conversationId?: number; onDelta?: (answer: string) => void } = {}) {
    const token = await tokenStore.get();
    return new Promise<string>((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', `${API_ROOT}/companion/ask`);
      xhr.setRequestHeader('Accept', 'text/event-stream');
      xhr.setRequestHeader('Content-Type', 'application/json');
      if (token) xhr.setRequestHeader('Authorization', `Bearer ${token}`);
      xhr.timeout = 240000;
      let readLength = 0;
      let buffer = '';
      let answer = '';
      const consumeAvailable = (flush = false) => {
        const responseText = xhr.responseText || '';
        const incoming = responseText.slice(readLength);
        readLength = responseText.length;
        const parsed = consumeSseChunk(buffer, flush ? `${incoming}\n\n` : incoming);
        buffer = parsed.buffer;
        if (parsed.tokens.length) {
          answer += parsed.tokens.join('');
          options.onDelta?.(answer);
        }
      };
      xhr.onprogress = () => consumeAvailable();
      xhr.onreadystatechange = () => { if (xhr.readyState === 3) consumeAvailable(); };
      xhr.onload = () => {
        consumeAvailable(true);
        if (xhr.status < 200 || xhr.status >= 300) reject(new ApiError(xhr.status, `伴读请求失败（${xhr.status}）`));
        else resolve(answer);
      };
      xhr.onerror = () => reject(new Error('网络连接中断，请稍后重试。'));
      xhr.ontimeout = () => reject(new Error('伴读思考时间过长，请稍后重试。'));
      xhr.send(JSON.stringify({ message, persona_id: personaId, scope, book_id: options.bookId, context: options.context, thread_id: options.threadId, conversation_id: options.conversationId }));
    });
  },
  dueFlashcards: async () => (await request<{ cards: Flashcard[] }>('/flashcards/due')).cards,
  createFlashcard: (bookId: number, input: { quote: string; front?: string; annotationId?: number }) => request<{ ok: boolean; id: number }>(`/books/${bookId}/flashcards`, { method: 'POST', body: JSON.stringify({ quote: input.quote, front: input.front, annotation_id: input.annotationId }) }),
  reviewFlashcard: (id: number, known: boolean) => request<{ ok: boolean }>(`/flashcards/${id}/review`, { method: 'POST', body: JSON.stringify({ known }) }),
  readingStats: () => request<ReadingStats>('/reading/stats'),
  readingState: async (bookId: number) => normalizeReadingState(await request<ReadingStateResponse>(`/books/${bookId}/reading-state`)),
  saveReadingState: async (session: ReadingSession) => normalizeReadingState(await request<ReadingStateResponse>(`/books/${session.bookId}/reading-state`, {
    method: 'PUT',
    body: JSON.stringify({
      format: session.format, locator: session.locator, page: session.page,
      total_pages: session.totalPages, progress: session.progress,
      section_title: session.sectionTitle, bookmarks: session.bookmarks,
      client_updated_at: session.updatedAt || new Date().toISOString(),
    }),
  })),
  aiSettings: () => request<AiSettingsPayload>('/ai/settings'),
  saveAiSettings: (draft: AiSettingsDraft) => request<AiSettingsPayload>('/ai/settings', {
    method: 'PUT',
    body: JSON.stringify({
      provider: draft.provider,
      format: draft.format,
      base_url: draft.baseUrl.trim(),
      model: draft.model.trim(),
      ...(draft.apiKey.trim() ? { api_key: draft.apiKey.trim() } : {}),
    }),
  }),
  testAiSettings: () => request<{ ok: boolean; message: string }>('/ai/settings/test', { method: 'POST', body: '{}' }),
  annotations: async (bookId: number) => (await request<{ annotations: Annotation[] }>(`/books/${bookId}/annotations`)).annotations,
  addAnnotation: (bookId: number, input: { loc: string; quote: string; note?: string; tag?: string }) => request<{ ok: boolean; id: number }>(`/books/${bookId}/annotations`, { method: 'POST', body: JSON.stringify(input) }),
  updateAnnotation: (bookId: number, annotationId: number, input: { note?: string; tag?: string }) => request<{ ok: boolean }>(`/books/${bookId}/annotations/${annotationId}`, { method: 'PUT', body: JSON.stringify(input) }),
  deleteAnnotation: (bookId: number, annotationId: number) => request<{ ok: boolean }>(`/books/${bookId}/annotations/${annotationId}`, { method: 'DELETE' }),
  addToKnowledgeBase: (input: { content: string; title?: string; bookId?: number }) => request<{ ok: boolean; chunk_id: number }>('/companion/add-to-kb', { method: 'POST', body: JSON.stringify({ content: input.content, title: input.title, book_id: input.bookId, scope: 'book' }) }),
  savedContent: () => request<{
    annotations: (Annotation & { book?: { id: number; title: string } })[];
    conversation_saves: { id: number; book_id?: number | null; title?: string | null; content: string; book?: { id: number; title: string } }[];
  }>('/saved-content'),
  knowledgeNotes: async () => (await request<{ items: KnowledgeNote[] }>('/knowledge/notes')).items,
  knowledgeChunks: async (item: KnowledgeNote) => {
    const params = new URLSearchParams({ type: item.type, title: item.title });
    if (item.book_id) params.set('book_id', String(item.book_id));
    if (item.source_path) params.set('source_path', item.source_path);
    return (await request<{ chunks: string[] }>(`/knowledge/chunks?${params}`)).chunks;
  },
  updateKnowledgeNote: (item: KnowledgeNote, input: { title: string; content: string }) => {
    const params = new URLSearchParams({ type: item.type, title: item.title });
    if (item.book_id) params.set('book_id', String(item.book_id));
    if (item.source_path) params.set('source_path', item.source_path);
    return request<{ ok: boolean }>(`/knowledge/notes?${params}`, { method: 'PUT', body: JSON.stringify(input) });
  },
  deleteKnowledgeNote: (item: KnowledgeNote) => {
    const params = new URLSearchParams({ type: item.type, title: item.title });
    if (item.book_id) params.set('book_id', String(item.book_id));
    if (item.source_path) params.set('source_path', item.source_path);
    return request<{ ok: boolean }>(`/knowledge/notes?${params}`, { method: 'DELETE' });
  },
  logReading: (bookId: number, seconds: number) => request<{ ok: boolean }>(`/reading/log`, { method: 'POST', body: JSON.stringify({ book_id: bookId, seconds }) }),
  analyzeBook: (bookId: number) => request<Record<string, unknown>>(`/book/${bookId}/analyze`, { method: 'POST', body: '{}' }),
  conceptGraph: (bookId: number) => generatedBookTool(bookId, 'concept-graph', 'graph'),
  characterGraph: (bookId: number) => generatedBookTool(bookId, 'characters', 'graph'),
  argumentMap: (bookId: number) => generatedBookTool(bookId, 'argument', 'map'),
  quizBook: (bookId: number) => request<Record<string, unknown>>(`/book/${bookId}/quiz/generate`, { method: 'POST', body: JSON.stringify({ book_id: bookId, source_type: 'book' }) }),
  exportMarkdown: (bookId: number) => request<{ markdown: string; filename: string }>(`/books/${bookId}/export/markdown`),
};
