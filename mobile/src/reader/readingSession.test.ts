import { beforeEach, describe, expect, it, vi } from 'vitest';
import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  createReadingSession,
  parseReadingSession,
  readingSessionStore,
  selectionReducer,
  toggleBookmark,
  updateReadingProgress,
} from './readingSession';

vi.mock('@react-native-async-storage/async-storage', () => ({
  default: { getItem: vi.fn(), setItem: vi.fn() },
}));

beforeEach(() => vi.clearAllMocks());

describe('reading session', () => {
  it('restores the exact EPUB location and progress for the same book', () => {
    const session = updateReadingProgress(createReadingSession(42, 'epub'), {
      locator: 'epubcfi(/6/14!/4/2/8)',
      progress: 0.376,
      sectionTitle: '第三章 认识自己',
    });

    expect(parseReadingSession(JSON.stringify(session), 42, 'epub')).toMatchObject({
      bookId: 42,
      format: 'epub',
      locator: 'epubcfi(/6/14!/4/2/8)',
      progress: 0.376,
      sectionTitle: '第三章 认识自己',
    });
  });

  it('restores a PDF page and ignores corrupt or cross-book state', () => {
    const session = updateReadingProgress(createReadingSession(7, 'pdf'), {
      page: 19,
      totalPages: 120,
      progress: 19 / 120,
    });

    expect(parseReadingSession(JSON.stringify(session), 7, 'pdf').page).toBe(19);
    expect(parseReadingSession(JSON.stringify(session), 8, 'pdf')).toEqual(createReadingSession(8, 'pdf'));
    expect(parseReadingSession('{broken', 7, 'pdf')).toEqual(createReadingSession(7, 'pdf'));
  });

  it('adds and removes one bookmark without duplicates', () => {
    const initial = createReadingSession(42, 'epub');
    const added = toggleBookmark(initial, {
      locator: 'epubcfi(/6/14!/4/2/8)',
      label: '第三章',
      excerpt: '重要的一段话',
    });
    const duplicate = toggleBookmark(added, {
      locator: 'epubcfi(/6/14!/4/2/8)',
      label: '第三章',
    });

    expect(added.bookmarks).toHaveLength(1);
    expect(duplicate.bookmarks).toHaveLength(0);
  });

  it('persists and loads a session through the device store', async () => {
    const stored = updateReadingProgress(createReadingSession(9, 'pdf'), { page: 4, progress: 0.25 });
    vi.mocked(AsyncStorage.getItem).mockResolvedValue(JSON.stringify(stored));

    expect((await readingSessionStore.load(9, 'pdf')).page).toBe(4);
    await readingSessionStore.save(stored);

    expect(AsyncStorage.getItem).toHaveBeenCalledWith('reading-session-v1:9');
    expect(AsyncStorage.setItem).toHaveBeenCalledWith('reading-session-v1:9', JSON.stringify(stored));
  });

  it('clamps progress and supports page bookmarks', () => {
    const session = updateReadingProgress(createReadingSession(3, 'pdf'), { progress: 2 });
    const bookmarked = toggleBookmark(session, { page: 5, label: '' });

    expect(session.progress).toBe(1);
    expect(bookmarked.bookmarks[0]).toMatchObject({ page: 5, label: '未命名书签' });
    expect(parseReadingSession(JSON.stringify({ ...session, progress: -1, bookmarks: null }), 3, 'pdf')).toMatchObject({ progress: 0, bookmarks: [] });
    expect(parseReadingSession(null, 3, 'pdf')).toEqual(createReadingSession(3, 'pdf'));
    expect(parseReadingSession(JSON.stringify({ version: 1, bookId: 3, format: 'pdf' }), 3, 'pdf')).toMatchObject({ progress: 0, bookmarks: [] });
    expect(updateReadingProgress(session, { page: 8 }).progress).toBe(1);
  });
});

describe('selection interaction', () => {
  it('captures a finished selection without opening an AI or note sheet', () => {
    const selected = selectionReducer({ mode: 'idle' }, {
      type: 'SELECTED',
      quote: '知识不是信息的堆积。',
      locator: 'epubcfi(/6/8!/4/2)',
    });

    expect(selected).toEqual({
      mode: 'actions',
      quote: '知识不是信息的堆积。',
      locator: 'epubcfi(/6/8!/4/2)',
    });
  });

  it('opens AI only after the reader explicitly asks and keeps a multi-turn thread', () => {
    const actions = selectionReducer({ mode: 'idle' }, {
      type: 'SELECTED', quote: '何为第一性原理？', locator: 'cfi-1',
    });
    const asking = selectionReducer(actions, { type: 'ASK_AI' });
    const asked = selectionReducer(asking, { type: 'AI_ASKED', id: 'u1', question: '第一性原理是什么？' });
    const answered = selectionReducer(asked, { type: 'AI_ANSWERED', id: 'a1', answer: '从不可再简化的事实出发。' });
    const followedUp = selectionReducer(answered, { type: 'AI_ASKED', id: 'u2', question: '这里的事实具体指什么？' });
    const saved = selectionReducer(followedUp, { type: 'ANSWER_SAVED', id: 'a1' });

    expect(asking.mode).toBe('ai');
    expect(answered).toMatchObject({ mode: 'ai', messages: [
      { id: 'u1', role: 'user', content: '第一性原理是什么？', saved: false },
      { id: 'a1', role: 'assistant', content: '从不可再简化的事实出发。', saved: false },
    ] });
    expect(followedUp.mode === 'ai' && followedUp.messages).toHaveLength(3);
    expect(saved).toMatchObject({ mode: 'ai', messages: [
      expect.anything(),
      expect.objectContaining({ id: 'a1', saved: true }),
      expect.anything(),
    ] });
  });

  it('opens notes explicitly, ignores empty selections, and closes cleanly', () => {
    expect(selectionReducer({ mode: 'idle' }, { type: 'SELECTED', quote: '   ', locator: 'cfi' })).toEqual({ mode: 'idle' });
    const actions = selectionReducer({ mode: 'idle' }, { type: 'SELECTED', quote: '摘录', locator: 'cfi' });
    expect(selectionReducer(actions, { type: 'ADD_NOTE' })).toMatchObject({ mode: 'note', quote: '摘录' });
    expect(selectionReducer(actions, { type: 'CLOSE' })).toEqual({ mode: 'idle' });
    expect(selectionReducer({ mode: 'idle' }, { type: 'ASK_AI' })).toEqual({ mode: 'idle' });
    expect(selectionReducer(actions, { type: 'AI_ANSWERED', id: 'a1', answer: 'ignored' })).toEqual(actions);
    expect(selectionReducer(actions, { type: 'ANSWER_SAVED', id: 'a1' })).toEqual(actions);
  });
});
