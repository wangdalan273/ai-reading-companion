import { describe, expect, it } from 'vitest';
import {
  initialReaderState,
  readerReducer,
  type ReaderEvent,
} from './readerMachine';

const reduce = (...events: ReaderEvent[]) =>
  events.reduce(readerReducer, initialReaderState);

describe('reader state machine', () => {
  it('moves through download and validation before becoming ready', () => {
    const state = reduce(
      { type: 'OPEN', bookId: 7, format: 'epub' },
      { type: 'DOWNLOAD_PROGRESS', received: 50, total: 100 },
      { type: 'DOWNLOADED', uri: 'file:///book.epub', bytes: 100 },
      { type: 'VALIDATED' },
    );

    expect(state).toMatchObject({
      status: 'ready',
      bookId: 7,
      format: 'epub',
      localUri: 'file:///book.epub',
      progress: 1,
    });
  });

  it('never remains loading after a timeout', () => {
    const state = reduce(
      { type: 'OPEN', bookId: 2, format: 'pdf' },
      { type: 'FAILED', code: 'timeout', message: '下载超时，请检查网络后重试' },
    );

    expect(state.status).toBe('error');
    expect(state.error?.code).toBe('timeout');
    expect(state.canRetry).toBe(true);
  });

  it.each([
    ['empty-file', '书籍文件为空'],
    ['invalid-format', '文件内容与书籍格式不匹配'],
    ['http', '服务器暂时无法提供书籍'],
  ] as const)('exposes %s failures to the user', (code, message) => {
    const state = reduce(
      { type: 'OPEN', bookId: 3, format: 'epub' },
      { type: 'FAILED', code, message },
    );

    expect(state).toMatchObject({ status: 'error', canRetry: true });
    expect(state.error).toEqual({ code, message });
  });

  it('retry resets transient fields but preserves the selected book', () => {
    const state = reduce(
      { type: 'OPEN', bookId: 9, format: 'pdf' },
      { type: 'FAILED', code: 'network', message: '网络连接失败' },
      { type: 'RETRY' },
    );

    expect(state).toMatchObject({
      status: 'downloading',
      bookId: 9,
      format: 'pdf',
      progress: 0,
      error: null,
      canRetry: false,
    });
  });

  it('ignores progress outside downloading and handles unknown totals', () => {
    expect(readerReducer(initialReaderState, {
      type: 'DOWNLOAD_PROGRESS', received: 5, total: 0,
    })).toBe(initialReaderState);

    const downloading = readerReducer(initialReaderState, {
      type: 'OPEN', bookId: 1, format: 'epub',
    });
    expect(readerReducer(downloading, {
      type: 'DOWNLOAD_PROGRESS', received: 5, total: 0,
    }).progress).toBe(0);
  });

  it('does not retry without a selected book and can reset a session', () => {
    expect(readerReducer(initialReaderState, { type: 'RETRY' })).toBe(initialReaderState);
    const opened = readerReducer(initialReaderState, {
      type: 'OPEN', bookId: 1, format: 'pdf',
    });
    expect(readerReducer(opened, { type: 'RESET' })).toBe(initialReaderState);
  });
});
