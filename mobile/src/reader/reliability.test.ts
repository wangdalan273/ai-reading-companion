import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import {
  getRenderTimeoutMs,
  isCachedBookComplete,
  isTransferStalled,
  normalizeToolResult,
} from './reliability';

describe('reader reliability policy', () => {
  it('does not fail an active large download because total time exceeded 25 seconds', () => {
    expect(isTransferStalled(24_000, 50_000, 45_000)).toBe(false);
  });

  it('fails only after no bytes arrive for the stall window', () => {
    expect(isTransferStalled(10_000, 55_001, 45_000)).toBe(true);
  });

  it('gives EPUB layout more time as file size grows while remaining bounded', () => {
    expect(getRenderTimeoutMs('epub', 2 * 1024 * 1024)).toBe(45_000);
    expect(getRenderTimeoutMs('epub', 80 * 1024 * 1024)).toBe(120_000);
    expect(getRenderTimeoutMs('pdf', 80 * 1024 * 1024)).toBe(60_000);
  });

  it('rejects a partial PDF left behind by a timed-out previous download', () => {
    expect(isCachedBookComplete(2 * 1024 * 1024, 20 * 1024 * 1024)).toBe(false);
    expect(isCachedBookComplete(19.9 * 1024 * 1024, 20 * 1024 * 1024)).toBe(true);
    expect(isCachedBookComplete(2 * 1024 * 1024, undefined)).toBe(true);
  });

  it('uses the Expo legacy filesystem contract required by the EPUB adapter', () => {
    const source = readFileSync(new URL('./useEpubFileSystem.ts', import.meta.url), 'utf8');
    expect(source).toContain("from 'expo-file-system/legacy'");
    expect(source).not.toContain("from 'expo-file-system';");
  });

  it('never falls back to the emulator-only API address in a production APK', () => {
    const clientSource = readFileSync(new URL('../api/client.ts', import.meta.url), 'utf8');
    const buildSource = readFileSync(new URL('../../scripts/build-standalone-apk.mjs', import.meta.url), 'utf8');

    expect(clientSource).toContain('https://read.sxmnq.art');
    expect(clientSource).not.toContain("configuredApiOrigin || 'http://10.0.2.2:8000'");
    expect(buildSource).toContain("apiOrigin.startsWith('https://')");
    expect(buildSource).toContain('EXPO_PUBLIC_API_ORIGIN: apiOrigin');
    expect(buildSource).toContain('syncAndroidVersion();');
    expect(buildSource).toContain('appConfig.expo?.android?.versionCode');
  });

  it('uploads EPUB and PDF files as Expo File blobs instead of unsupported URI parts', () => {
    const source = readFileSync(new URL('../screens/LibraryScreen.tsx', import.meta.url), 'utf8');

    expect(source).toContain("import { File } from 'expo-file-system';");
    expect(source).toContain("extension !== 'pdf' && extension !== 'epub'");
    expect(source).toContain('const file = new File(asset.uri);');
    expect(source).toContain("form.append('file', file, asset.name);");
    expect(source).not.toContain("form.append('file', {");
  });
});

describe('reading tool result normalization', () => {
  it('turns character graph JSON into a named mobile view model', () => {
    expect(normalizeToolResult('characters', {
      ok: true,
      graph: {
        genre: 'unknown',
        characters: [{ name: '无疾', faction: '', desc: '', chapters: [2, 10, 12] }],
        relations: [{ from: '无疾', to: '橘井', type: '学习', desc: '医理传承' }],
      },
    })).toEqual({
      kind: 'characters',
      genre: '类型待识别',
      characters: [{ name: '无疾', faction: '', description: '暂无人物简介', chapterLabel: '第 2、10、12 章' }],
      relations: [{ from: '无疾', to: '橘井', label: '学习', description: '医理传承' }],
    });
  });

  it('normalizes concept, argument, quiz, summary, and markdown results without raw JSON', () => {
    expect(normalizeToolResult('concept', { graph: { nodes: [{ id: 'n1', label: '阴阳', def: '相反相成' }], edges: [] } }).kind).toBe('concept');
    expect(normalizeToolResult('argument', { map: { claims: [{ id: 'c1', text: '核心主张', type: '主论点' }], evidence: [], counter: [] } }).kind).toBe('argument');
    expect(normalizeToolResult('quiz', { quiz_id: 1, questions: [{ id: 2, stem: '问题', options: ['甲', '乙'], answer: 0, reason: '解析' }] }).kind).toBe('quiz');
    expect(normalizeToolResult('summary', { mindmap_md: '# 书名\n- 要点', chapters: [{ title: '第一章', summary: '- 摘要' }] }).kind).toBe('summary');
    expect(normalizeToolResult('markdown', { filename: 'book.md', markdown: '# 笔记' })).toEqual({ kind: 'markdown', filename: 'book.md', markdown: '# 笔记' });
  });

  it('returns a readable error model for malformed or failed server results', () => {
    expect(normalizeToolResult('characters', { ok: false, msg: '无法提取文本' })).toEqual({ kind: 'error', message: '无法提取文本' });
    expect(normalizeToolResult('concept', {})).toEqual({ kind: 'error', message: '服务端没有返回可展示的分析结果' });
  });
});
