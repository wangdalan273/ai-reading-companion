import AsyncStorage from '@react-native-async-storage/async-storage';
import type { BookFormat } from './readerMachine';
import { createThreadMessage, markThreadMessageSaved, type ThreadMessage } from '../companion/conversation';

export type ReadingBookmark = {
  id: string;
  locator?: string;
  page?: number;
  label: string;
  excerpt?: string;
  createdAt: string;
};

export type ReadingSession = {
  version: 1;
  bookId: number;
  format: BookFormat;
  locator?: string;
  page?: number;
  totalPages?: number;
  progress: number;
  sectionTitle?: string;
  updatedAt?: string;
  bookmarks: ReadingBookmark[];
};

export type SelectionState =
  | { mode: 'idle' }
  | { mode: 'actions'; quote: string; locator: string }
  | { mode: 'note'; quote: string; locator: string }
  | { mode: 'ai'; quote: string; locator: string; messages: ThreadMessage[]; autoAsk: boolean };

export type SelectionAction =
  | { type: 'SELECTED'; quote: string; locator: string }
  | { type: 'ASK_AI' }
  | { type: 'ADD_NOTE' }
  | { type: 'AI_ASKED'; id: string; question: string }
  | { type: 'AI_ANSWERED'; id: string; answer: string; failed?: boolean }
  | { type: 'RESTORE_AI'; messages: ThreadMessage[] }
  | { type: 'ANSWER_SAVED'; id: string }
  | { type: 'CLOSE' };

const clamp = (value: number) => Math.max(0, Math.min(1, value));
const sessionKey = (bookId: number) => `reading-session-v1:${bookId}`;

export function createReadingSession(bookId: number, format: BookFormat): ReadingSession {
  return { version: 1, bookId, format, progress: 0, bookmarks: [] };
}

export function parseReadingSession(raw: string | null, bookId: number, format: BookFormat): ReadingSession {
  if (!raw) return createReadingSession(bookId, format);
  try {
    const value = JSON.parse(raw) as Partial<ReadingSession>;
    if (value.version !== 1 || value.bookId !== bookId || value.format !== format) {
      return createReadingSession(bookId, format);
    }
    return {
      ...createReadingSession(bookId, format),
      ...value,
      progress: clamp(typeof value.progress === 'number' ? value.progress : 0),
      bookmarks: Array.isArray(value.bookmarks) ? value.bookmarks : [],
    };
  } catch {
    return createReadingSession(bookId, format);
  }
}

export function updateReadingProgress(session: ReadingSession, update: Partial<Pick<ReadingSession, 'locator' | 'page' | 'totalPages' | 'progress' | 'sectionTitle'>>): ReadingSession {
  return { ...session, ...update, progress: clamp(update.progress ?? session.progress), updatedAt: new Date().toISOString() };
}

export function toggleBookmark(session: ReadingSession, input: Pick<ReadingBookmark, 'locator' | 'page' | 'label'> & Partial<Pick<ReadingBookmark, 'excerpt'>>): ReadingSession {
  const match = (item: ReadingBookmark) => input.locator ? item.locator === input.locator : item.page === input.page;
  if (session.bookmarks.some(match)) {
    return { ...session, bookmarks: session.bookmarks.filter((item) => !match(item)), updatedAt: new Date().toISOString() };
  }
  const identity = input.locator ?? `page-${input.page ?? 1}`;
  return {
    ...session,
    bookmarks: [...session.bookmarks, {
      ...input,
      id: `${identity}:${Date.now()}`,
      label: input.label || '未命名书签',
      createdAt: new Date().toISOString(),
    }],
    updatedAt: new Date().toISOString(),
  };
}

export function newestReadingSession(local: ReadingSession, remote?: ReadingSession | null): ReadingSession {
  if (!remote) return local;
  const localTime = Date.parse(local.updatedAt || '') || 0;
  const remoteTime = Date.parse(remote.updatedAt || '') || 0;
  return remoteTime > localTime ? remote : local;
}

export function selectionReducer(state: SelectionState, action: SelectionAction): SelectionState {
  if (action.type === 'CLOSE') return { mode: 'idle' };
  if (action.type === 'SELECTED') return action.quote.trim()
    ? { mode: 'actions', quote: action.quote.trim(), locator: action.locator }
    : { mode: 'idle' };
  if (state.mode === 'idle') return state;
  if (action.type === 'ASK_AI') return { mode: 'ai', quote: state.quote, locator: state.locator, messages: [], autoAsk: true };
  if (action.type === 'RESTORE_AI') return { mode: 'ai', quote: state.quote, locator: state.locator, messages: action.messages, autoAsk: false };
  if (action.type === 'ADD_NOTE') return { mode: 'note', quote: state.quote, locator: state.locator };
  if (action.type === 'AI_ASKED' && state.mode === 'ai') return {
    ...state,
    autoAsk: false,
    messages: [...state.messages, createThreadMessage('user', action.question, action.id)],
  };
  if (action.type === 'AI_ANSWERED' && state.mode === 'ai') return {
    ...state,
    messages: [...state.messages, createThreadMessage('assistant', action.answer, action.id, action.failed)],
  };
  if (action.type === 'ANSWER_SAVED' && state.mode === 'ai') return {
    ...state,
    messages: markThreadMessageSaved(state.messages, action.id),
  };
  return state;
}

export const readingSessionStore = {
  async load(bookId: number, format: BookFormat) {
    return parseReadingSession(await AsyncStorage.getItem(sessionKey(bookId)), bookId, format);
  },
  async save(session: ReadingSession) {
    await AsyncStorage.setItem(sessionKey(session.bookId), JSON.stringify(session));
  },
};
