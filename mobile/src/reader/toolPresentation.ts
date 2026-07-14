import type { ToolResult } from './reliability';

export type ToolIndexItem = { id: string; title: string; subtitle: string; metric: number };
export type ToolRelation = { from: string; to: string; label: string; description?: string };
export type ToolFocus = {
  id: string;
  title: string;
  subtitle: string;
  description: string;
  related: ToolRelation[];
  evidence: { text: string; type: string }[];
  counters: { text: string; type: string }[];
  challenge?: string;
};

type FocusableResult = Extract<ToolResult, { kind: 'concept' | 'characters' | 'argument' }>;
const matches = (query: string, ...values: string[]) => !query.trim() || values.some((value) => value.toLocaleLowerCase().includes(query.trim().toLocaleLowerCase()));

export function buildToolIndex(result: FocusableResult, query: string): ToolIndexItem[] {
  if (result.kind === 'concept') return result.nodes
    .filter((item) => matches(query, item.label, item.description, item.chapterLabel))
    .map((item) => ({ id: item.id, title: item.label, subtitle: item.chapterLabel, metric: item.count }))
    .sort((a, b) => b.metric - a.metric || a.title.localeCompare(b.title));
  if (result.kind === 'characters') return result.characters
    .filter((item) => matches(query, item.name, item.faction, item.description))
    .map((item) => ({ id: item.name, title: item.name, subtitle: item.faction || item.chapterLabel, metric: result.relations.filter((relation) => relation.from === item.name || relation.to === item.name).length }))
    .sort((a, b) => b.metric - a.metric || a.title.localeCompare(b.title));
  return result.claims
    .filter((item) => matches(query, item.text, item.type))
    .map((item) => ({ id: item.id, title: item.text, subtitle: item.type, metric: result.evidence.filter((entry) => entry.claimId === item.id).length }))
    .sort((a, b) => b.metric - a.metric);
}

export function focusToolItem(result: FocusableResult, id: string): ToolFocus {
  if (result.kind === 'concept') {
    const item = result.nodes.find((node) => node.id === id) ?? result.nodes[0];
    const names = new Map(result.nodes.map((node) => [node.id, node.label]));
    return {
      id: item?.id ?? '', title: item?.label ?? '未找到概念', subtitle: item?.chapterLabel ?? '', description: item?.description ?? '',
      related: result.edges.filter((edge) => edge.from === item?.id || edge.to === item?.id).map((edge) => ({ from: names.get(edge.from) ?? edge.from, to: names.get(edge.to) ?? edge.to, label: edge.label })),
      evidence: [], counters: [],
    };
  }
  if (result.kind === 'characters') {
    const item = result.characters.find((character) => character.name === id) ?? result.characters[0];
    return {
      id: item?.name ?? '', title: item?.name ?? '未找到人物', subtitle: item?.faction || item?.chapterLabel || '', description: item?.description ?? '',
      related: result.relations.filter((relation) => relation.from === item?.name || relation.to === item?.name), evidence: [], counters: [],
    };
  }
  const item = result.claims.find((claim) => claim.id === id) ?? result.claims[0];
  return {
    id: item?.id ?? '', title: item?.text ?? '未找到论点', subtitle: item?.type ?? '', description: '', related: [],
    evidence: result.evidence.filter((entry) => entry.claimId === item?.id).map(({ text, type }) => ({ text, type })),
    counters: result.counters.filter((entry) => entry.claimId === item?.id).map(({ text, type }) => ({ text, type })),
    challenge: item?.challenge,
  };
}
