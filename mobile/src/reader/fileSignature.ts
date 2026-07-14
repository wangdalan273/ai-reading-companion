import type { BookFormat } from './readerMachine';

const startsWith = (bytes: Uint8Array, signature: readonly number[]) =>
  signature.every((value, index) => bytes[index] === value);

export function detectBookFormat(bytes: Uint8Array): BookFormat | null {
  if (startsWith(bytes, [0x25, 0x50, 0x44, 0x46])) return 'pdf';
  if (startsWith(bytes, [0x50, 0x4b, 0x03, 0x04])) return 'epub';
  return null;
}

export function validateBookBytes(
  bytes: Uint8Array,
  expected: BookFormat,
): { ok: true } | { ok: false; code: 'empty-file' | 'invalid-format' } {
  if (bytes.byteLength === 0) return { ok: false, code: 'empty-file' };
  if (detectBookFormat(bytes) !== expected) {
    return { ok: false, code: 'invalid-format' };
  }
  return { ok: true };
}
