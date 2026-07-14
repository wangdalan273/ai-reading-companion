import { describe, expect, it } from 'vitest';
import { buildToolIndex, focusToolItem } from './toolPresentation';
import type { ToolResult } from './reliability';

describe('mobile tool presentation', () => {
  it('builds a searchable concept index ordered by importance', () => {
    const result: Extract<ToolResult, { kind: 'concept' }> = {
      kind: 'concept',
      nodes: [
        { id: 'yin', label: '阴阳', description: '相反相成', count: 12, chapterLabel: '第 1、2 章' },
        { id: 'qi', label: '气', description: '运行基础', count: 30, chapterLabel: '全书' },
      ],
      edges: [{ from: 'yin', to: 'qi', label: '相互作用' }],
    };

    expect(buildToolIndex(result, '')[0]).toMatchObject({ id: 'qi', title: '气', metric: 30 });
    expect(buildToolIndex(result, '阴')).toHaveLength(1);
    expect(focusToolItem(result, 'yin')).toMatchObject({
      title: '阴阳',
      related: [{ from: '阴阳', to: '气', label: '相互作用' }],
    });
  });

  it('shows only relations belonging to the selected character', () => {
    const result: Extract<ToolResult, { kind: 'characters' }> = {
      kind: 'characters', genre: 'novel',
      characters: [
        { name: '无疾', faction: '医者', description: '主人公', chapterLabel: '第 2、3 章' },
        { name: '青黛', faction: '', description: '同伴', chapterLabel: '第 3 章' },
        { name: '路人', faction: '', description: '', chapterLabel: '第 8 章' },
      ],
      relations: [
        { from: '无疾', to: '青黛', label: '同行', description: '共同求医' },
        { from: '青黛', to: '路人', label: '相识', description: '' },
      ],
    };

    expect(focusToolItem(result, '无疾').related).toEqual([
      { from: '无疾', to: '青黛', label: '同行', description: '共同求医' },
    ]);
    expect(buildToolIndex(result, '医者')).toEqual([
      { id: '无疾', title: '无疾', subtitle: '医者', metric: 1 },
    ]);
  });

  it('groups evidence and counterarguments under the selected claim', () => {
    const result: Extract<ToolResult, { kind: 'argument' }> = {
      kind: 'argument',
      claims: [
        { id: 'c1', text: '阅读需要主动加工', type: '主论点', challenge: '是否所有场景都成立？' },
        { id: 'c2', text: '重复也有价值', type: '分论点', challenge: '' },
      ],
      evidence: [{ claimId: 'c1', text: '主动回忆提升保持率', type: '研究' }],
      counters: [{ claimId: 'c1', text: '休闲阅读未必需要', type: '例外' }],
    };

    expect(focusToolItem(result, 'c1')).toMatchObject({
      title: '阅读需要主动加工',
      evidence: [{ text: '主动回忆提升保持率', type: '研究' }],
      counters: [{ text: '休闲阅读未必需要', type: '例外' }],
    });
    expect(buildToolIndex(result, '分论点')).toEqual([
      { id: 'c2', title: '重复也有价值', subtitle: '分论点', metric: 0 },
    ]);
  });

  it('falls back to the first item when a stale focus id no longer exists', () => {
    const result: Extract<ToolResult, { kind: 'concept' }> = {
      kind: 'concept',
      nodes: [{ id: 'first', label: '首项', description: '', count: 0, chapterLabel: '全书' }],
      edges: [],
    };

    expect(focusToolItem(result, 'removed')).toMatchObject({ id: 'first', title: '首项', related: [] });
  });

  it('returns readable empty focus states for incomplete server results', () => {
    expect(focusToolItem({ kind: 'concept', nodes: [], edges: [] }, 'none')).toMatchObject({
      id: '', title: '未找到概念', subtitle: '', description: '', related: [],
    });
    expect(focusToolItem({ kind: 'characters', genre: 'unknown', characters: [], relations: [] }, 'none')).toMatchObject({
      id: '', title: '未找到人物', subtitle: '', description: '', related: [],
    });
    expect(focusToolItem({ kind: 'argument', claims: [], evidence: [], counters: [] }, 'none')).toMatchObject({
      id: '', title: '未找到论点', subtitle: '', evidence: [], counters: [],
    });
  });
});
