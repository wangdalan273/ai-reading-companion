import { describe, expect, it } from 'vitest';
import { detectBookFormat, validateBookBytes } from './fileSignature';

describe('book file signature validation', () => {
  it('detects PDF by its magic bytes', () => {
    expect(detectBookFormat(new Uint8Array([0x25, 0x50, 0x44, 0x46, 0x2d]))).toBe('pdf');
  });

  it('detects EPUB as a ZIP container', () => {
    expect(detectBookFormat(new Uint8Array([0x50, 0x4b, 0x03, 0x04]))).toBe('epub');
  });

  it('rejects empty and unknown files with actionable error codes', () => {
    expect(validateBookBytes(new Uint8Array(), 'epub')).toEqual({
      ok: false,
      code: 'empty-file',
    });
    expect(validateBookBytes(new Uint8Array([1, 2, 3, 4]), 'pdf')).toEqual({
      ok: false,
      code: 'invalid-format',
    });
  });

  it('rejects a valid file whose declared format is wrong', () => {
    expect(validateBookBytes(new Uint8Array([0x25, 0x50, 0x44, 0x46]), 'epub')).toEqual({
      ok: false,
      code: 'invalid-format',
    });
  });

  it('accepts matching EPUB and PDF signatures', () => {
    expect(validateBookBytes(new Uint8Array([0x50, 0x4b, 0x03, 0x04]), 'epub')).toEqual({ ok: true });
    expect(validateBookBytes(new Uint8Array([0x25, 0x50, 0x44, 0x46]), 'pdf')).toEqual({ ok: true });
  });
});
