import type { BookFormat } from './readerMachine';

export type ToolKind = 'summary' | 'concept' | 'characters' | 'argument' | 'quiz' | 'markdown';
type Row = Record<string, unknown>;

export type ToolResult =
  | { kind: 'error'; message: string }
  | { kind: 'summary'; mindmap: string; chapters: { title: string; summary: string }[] }
  | { kind: 'concept'; nodes: { id: string; label: string; description: string; count: number; chapterLabel: string }[]; edges: { from: string; to: string; label: string }[] }
  | { kind: 'characters'; genre: string; characters: { name: string; faction: string; description: string; chapterLabel: string }[]; relations: { from: string; to: string; label: string; description: string }[] }
  | { kind: 'argument'; claims: { id: string; text: string; type: string; challenge: string }[]; evidence: { claimId: string; text: string; type: string }[]; counters: { claimId: string; text: string; type: string }[] }
  | { kind: 'quiz'; quizId: number; questions: { id: number; stem: string; options: string[]; answer: number; reason: string }[] }
  | { kind: 'markdown'; filename: string; markdown: string };

const row = (value: unknown): Row => value && typeof value === 'object' && !Array.isArray(value) ? value as Row : {};
const rows = (value: unknown): Row[] => Array.isArray(value) ? value.map(row) : [];
const text = (value: unknown, fallback = '') => typeof value === 'string' ? value : fallback;
const number = (value: unknown, fallback = 0) => typeof value === 'number' && Number.isFinite(value) ? value : fallback;
const chapterLabel = (value: unknown) => {
  const chapters = Array.isArray(value) ? value.filter((item): item is number | string => typeof item === 'number' || typeof item === 'string') : [];
  return chapters.length ? `第 ${chapters.join('、')} 章` : '全书';
};

export function isTransferStalled(lastProgressAt: number, now: number, stallTimeoutMs = 45_000) {
  return now - lastProgressAt > stallTimeoutMs;
}

export function isCachedBookComplete(actualBytes: number, expectedBytes?: number) {
  if (actualBytes <= 0) return false;
  if (!expectedBytes || expectedBytes <= 0) return true;
  return actualBytes >= expectedBytes * 0.99;
}

export function getRenderTimeoutMs(format: BookFormat, bytes: number) {
  if (format === 'pdf') return 60_000;
  const extra = Math.floor(bytes / (8 * 1024 * 1024)) * 15_000;
  return Math.min(120_000, 45_000 + extra);
}

export function normalizeToolResult(kind: ToolKind, input: unknown): ToolResult {
  const data = row(input);
  if (data.ok === false) return { kind: 'error', message: text(data.msg, '分析失败，请稍后重试') };

  if (kind === 'markdown' && typeof data.markdown === 'string') {
    return { kind, filename: text(data.filename, '阅读笔记.md'), markdown: data.markdown };
  }
  if (kind === 'summary' && (typeof data.mindmap_md === 'string' || Array.isArray(data.chapters))) {
    return {
      kind,
      mindmap: text(data.mindmap_md),
      chapters: rows(data.chapters).map((item) => ({ title: text(item.title, '未命名章节'), summary: text(item.summary, '暂无摘要') })),
    };
  }
  if (kind === 'concept') {
    const graph = row(data.graph);
    if (Array.isArray(graph.nodes)) return {
      kind,
      nodes: rows(graph.nodes).map((item, index) => ({ id: text(item.id, `n${index}`), label: text(item.label, '未命名概念'), description: text(item.def, '暂无概念解释'), count: number(item.count), chapterLabel: chapterLabel(item.chapters) })),
      edges: rows(graph.edges).map((item) => ({ from: text(item.from), to: text(item.to), label: text(item.label, '相关') })),
    };
  }
  if (kind === 'characters') {
    const graph = row(data.graph);
    if (Array.isArray(graph.characters)) return {
      kind,
      genre: text(graph.genre) === 'unknown' ? '类型待识别' : text(graph.genre, '类型待识别'),
      characters: rows(graph.characters).map((item) => ({ name: text(item.name).trim() || '未命名人物', faction: text(item.faction), description: text(item.desc).trim() || '暂无人物简介', chapterLabel: chapterLabel(item.chapters) })),
      relations: rows(graph.relations).map((item) => ({ from: text(item.from), to: text(item.to), label: text(item.type, '相关'), description: text(item.desc) })),
    };
  }
  if (kind === 'argument') {
    const map = row(data.map);
    if (Array.isArray(map.claims)) return {
      kind,
      claims: rows(map.claims).map((item, index) => ({ id: text(item.id, `c${index}`), text: text(item.text, '未命名主张'), type: text(item.type, '论点'), challenge: text(item.challenge) })),
      evidence: rows(map.evidence).map((item) => ({ claimId: text(item.claim_id), text: text(item.text), type: text(item.type, '证据') })),
      counters: rows(map.counter).map((item) => ({ claimId: text(item.claim_id), text: text(item.text), type: text(item.type, '反驳') })),
    };
  }
  if (kind === 'quiz' && Array.isArray(data.questions)) return {
    kind,
    quizId: number(data.quiz_id),
    questions: rows(data.questions).map((item, index) => ({ id: number(item.id, index), stem: text(item.stem, '未命名问题'), options: Array.isArray(item.options) ? item.options.map((option) => text(option)) : [], answer: number(item.answer), reason: text(item.reason, '暂无解析') })),
  };
  return { kind: 'error', message: '服务端没有返回可展示的分析结果' };
}
