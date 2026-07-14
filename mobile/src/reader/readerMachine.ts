export type BookFormat = 'epub' | 'pdf';

export type ReaderErrorCode =
  | 'timeout'
  | 'network'
  | 'http'
  | 'empty-file'
  | 'invalid-format'
  | 'render';

export type ReaderState = {
  status: 'idle' | 'downloading' | 'validating' | 'ready' | 'error';
  bookId: number | null;
  format: BookFormat | null;
  localUri: string | null;
  bytes: number;
  progress: number;
  error: { code: ReaderErrorCode; message: string } | null;
  canRetry: boolean;
};

export type ReaderEvent =
  | { type: 'OPEN'; bookId: number; format: BookFormat }
  | { type: 'DOWNLOAD_PROGRESS'; received: number; total: number }
  | { type: 'DOWNLOADED'; uri: string; bytes: number }
  | { type: 'VALIDATED' }
  | { type: 'FAILED'; code: ReaderErrorCode; message: string }
  | { type: 'RETRY' }
  | { type: 'RESET' };

export const initialReaderState: ReaderState = {
  status: 'idle',
  bookId: null,
  format: null,
  localUri: null,
  bytes: 0,
  progress: 0,
  error: null,
  canRetry: false,
};

export function readerReducer(state: ReaderState, event: ReaderEvent): ReaderState {
  switch (event.type) {
    case 'OPEN':
      return {
        ...initialReaderState,
        status: 'downloading',
        bookId: event.bookId,
        format: event.format,
      };
    case 'DOWNLOAD_PROGRESS':
      if (state.status !== 'downloading') return state;
      return {
        ...state,
        progress: event.total > 0
          ? Math.min(1, Math.max(0, event.received / event.total))
          : 0,
      };
    case 'DOWNLOADED':
      return {
        ...state,
        status: 'validating',
        localUri: event.uri,
        bytes: event.bytes,
        progress: 1,
      };
    case 'VALIDATED':
      return { ...state, status: 'ready', error: null, canRetry: false };
    case 'FAILED':
      return {
        ...state,
        status: 'error',
        error: { code: event.code, message: event.message },
        canRetry: state.bookId !== null,
      };
    case 'RETRY':
      if (state.bookId === null || state.format === null) return state;
      return {
        ...initialReaderState,
        status: 'downloading',
        bookId: state.bookId,
        format: state.format,
      };
    case 'RESET':
      return initialReaderState;
  }
}
