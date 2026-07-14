import * as FileSystem from 'expo-file-system/legacy';
import { decode as decodeBase64 } from 'base64-arraybuffer';
import { api, tokenStore } from '../api/client';
import { validateBookBytes } from './fileSignature';
import type { BookFormat, ReaderErrorCode } from './readerMachine';
import { isCachedBookComplete, isTransferStalled } from './reliability';

export class BookOpenError extends Error {
  constructor(public code: ReaderErrorCode, message: string) { super(message); }
}

export async function downloadAndValidateBook(
  bookId: number,
  format: BookFormat,
  onProgress: (received: number, total: number) => void,
  options: { expectedBytes?: number; stallTimeoutMs?: number } = {},
) {
  const { expectedBytes, stallTimeoutMs = 45_000 } = options;
  const root = `${FileSystem.documentDirectory}books-v2/`;
  await FileSystem.makeDirectoryAsync(root, { intermediates: true });
  const destination = `${root}${bookId}.${format}`;
  const validateLocal = async (uri: string) => {
    const info = await FileSystem.getInfoAsync(uri);
    if (!info.exists || !info.size || !isCachedBookComplete(info.size, expectedBytes)) return null;
    const head = await FileSystem.readAsStringAsync(uri, { encoding: FileSystem.EncodingType.Base64, position: 0, length: 8 });
    const validation = validateBookBytes(new Uint8Array(decodeBase64(head)), format);
    return validation.ok ? { uri, bytes: info.size } : null;
  };
  const cached = await validateLocal(destination);
  if (cached) { onProgress(cached.bytes, cached.bytes); return cached; }
  await FileSystem.deleteAsync(destination, { idempotent: true });
  const token = await tokenStore.get();
  let lastProgressAt = Date.now();
  const task = FileSystem.createDownloadResumable(
    api.bookFileUrl(bookId), destination,
    { headers: token ? { Authorization: `Bearer ${token}` } : {} },
    ({ totalBytesWritten, totalBytesExpectedToWrite }) => {
      lastProgressAt = Date.now();
      onProgress(totalBytesWritten, totalBytesExpectedToWrite);
    },
  );

  let timer: ReturnType<typeof setInterval> | undefined;
  try {
    const stalled = new Promise<never>((_, reject) => {
      timer = setInterval(async () => {
        if (!isTransferStalled(lastProgressAt, Date.now(), stallTimeoutMs)) return;
        if (timer) clearInterval(timer);
        try { await task.pauseAsync(); } catch {}
        reject(new BookOpenError('timeout', `连续 ${Math.round(stallTimeoutMs / 1000)} 秒没有收到数据，请切换网络后重试`));
      }, 1_000);
    });
    const result = await Promise.race([task.downloadAsync(), stalled]);
    if (!result?.uri) throw new BookOpenError('network', '书籍下载未完成，请重试');
    const validated = await validateLocal(result.uri);
    if (!validated) throw new BookOpenError('invalid-format', '文件为空或内容与书籍格式不匹配，请重新导入');
    return validated;
  } catch (error) {
    if (error instanceof BookOpenError) throw error;
    throw new BookOpenError('network', error instanceof Error ? error.message : '网络连接失败');
  } finally {
    if (timer) clearInterval(timer);
  }
}
