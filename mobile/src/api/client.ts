import * as SecureStore from 'expo-secure-store';
import type { AiSettingsDraft, AiSettingsPayload, Annotation, Book, ChatMessage, Flashcard, Persona, ReadingStats, User } from '../types';

const configuredApiOrigin = process.env.EXPO_PUBLIC_API_ORIGIN?.trim();

// Public builds must inject their own backend URL. The fallback targets the
// Android emulator's host machine and contains no deployment-specific data.
export const API_ORIGIN = (configuredApiOrigin || 'http://10.0.2.2:8000').replace(/\/+$/, '');
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
  books: () => request<Book[]>('/books'),
  uploadBook: (form: FormData) => request<Book>('/books', { method: 'POST', body: form }),
  bookFileUrl: (id: number) => `${API_ROOT}/books/${id}/file`,
  personas: async () => (await request<{ personas: Persona[] }>('/companion/personas')).personas,
  companionMessages: async (personaId?: number) => (await request<{ messages: ChatMessage[] }>(`/companion/messages${personaId ? `?persona_id=${personaId}` : ''}`)).messages,
  async askCompanion(message: string, personaId: number | undefined, scope: 'book' | 'vault' | 'all', options: { bookId?: number; context?: string } = {}) {
    const token = await tokenStore.get();
    const response = await fetch(`${API_ROOT}/companion/ask`, {
      method: 'POST',
      headers: { Accept: 'text/event-stream', 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
      body: JSON.stringify({ message, persona_id: personaId, scope, book_id: options.bookId, context: options.context }),
    });
    if (!response.ok) throw new ApiError(response.status, `伴读请求失败（${response.status}）`);
    const text = await response.text();
    return text.split(/\r?\n/).filter((line) => line.startsWith('data: ')).map((line) => line.slice(6)).filter((value) => value !== '"[DONE]"').map((value) => {
      try { return JSON.parse(value) as string; } catch { return ''; }
    }).join('');
  },
  dueFlashcards: async () => (await request<{ cards: Flashcard[] }>('/flashcards/due')).cards,
  reviewFlashcard: (id: number, known: boolean) => request<{ ok: boolean }>(`/flashcards/${id}/review`, { method: 'POST', body: JSON.stringify({ known }) }),
  readingStats: () => request<ReadingStats>('/reading/stats'),
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
  addToKnowledgeBase: (input: { content: string; title?: string; bookId?: number }) => request<{ ok: boolean; chunk_id: number }>('/companion/add-to-kb', { method: 'POST', body: JSON.stringify({ content: input.content, title: input.title, book_id: input.bookId, scope: 'book' }) }),
  logReading: (bookId: number, seconds: number) => request<{ ok: boolean }>(`/reading/log`, { method: 'POST', body: JSON.stringify({ book_id: bookId, seconds }) }),
  analyzeBook: (bookId: number) => request<Record<string, unknown>>(`/book/${bookId}/analyze`, { method: 'POST', body: '{}' }),
  conceptGraph: (bookId: number) => request<Record<string, unknown>>(`/book/${bookId}/concept-graph`, { method: 'POST', body: '{}' }),
  characterGraph: (bookId: number) => request<Record<string, unknown>>(`/book/${bookId}/characters`, { method: 'POST', body: '{}' }),
  argumentMap: (bookId: number) => request<Record<string, unknown>>(`/book/${bookId}/argument`, { method: 'POST', body: '{}' }),
  quizBook: (bookId: number) => request<Record<string, unknown>>(`/book/${bookId}/quiz/generate`, { method: 'POST', body: JSON.stringify({ book_id: bookId, source_type: 'book' }) }),
  exportMarkdown: (bookId: number) => request<{ markdown: string; filename: string }>(`/books/${bookId}/export/markdown`),
};
