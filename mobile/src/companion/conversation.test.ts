import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { buildConversationContext, consumeSseChunk, createThreadMessage, formatMarkdownForReading, markThreadMessageSaved, removeConversationById, visibleThreadMessages } from './conversation';

describe('conversation context', () => {
  it('keeps a newly-created conversation isolated from stale server history', () => {
    expect(visibleThreadMessages(['旧消息'], [], true)).toEqual([]);
    expect(visibleThreadMessages(['当前历史'], ['待发送'], false)).toEqual(['当前历史', '待发送']);
  });

  it('removes a deleted conversation immediately from a cached history list', () => {
    expect(removeConversationById([
      { id: 'first', title: '第一组' },
      { id: 'second', title: '第二组' },
    ], 'first')).toEqual([{ id: 'second', title: '第二组' }]);
  });

  it('decodes SSE incrementally even when a frame is split across network chunks', () => {
    const first = consumeSseChunk('', 'data: "你"\n\ndata: "好');
    expect(first.tokens).toEqual(['你']);
    const second = consumeSseChunk(first.buffer, '呀"\n\ndata: "[DONE]"\n\n');
    expect(second.tokens).toEqual(['好呀']);
    expect(second.done).toBe(true);
  });
  it('does not inject preset follow-up questions into the reader UI', () => {
    const readerSource = readFileSync(new URL('../screens/ReaderScreen.tsx', import.meta.url), 'utf8');

    expect(readerSource).not.toMatch(/READER_AI_FOLLOW_UPS|举一个例子|它与全书有什么关系|这个观点可能错在哪里/);
  });

  it('keeps the selected source and previous turns for a follow-up question', () => {
    const messages = [
      createThreadMessage('user', '这句话在说什么？', 'u1'),
      createThreadMessage('assistant', '它强调知识需要经过检验。', 'a1'),
    ];

    expect(buildConversationContext('知识不是信息的堆积。', messages)).toContain(
      '原文：\n知识不是信息的堆积。\n\n此前对话：\n读者：这句话在说什么？\nAI：它强调知识需要经过检验。',
    );
  });

  it('bounds context for the server while retaining the most recent turns', () => {
    const messages = Array.from({ length: 12 }, (_, index) => createThreadMessage(
      index % 2 ? 'assistant' : 'user',
      `${index}-${'很长的内容'.repeat(120)}`,
      String(index),
    ));
    const context = buildConversationContext('原文'.repeat(1200), messages, 3600);

    expect(context.length).toBeLessThanOrEqual(3600);
    expect(context).toContain('11-');
  });

  it('marks one assistant response as collected without changing other turns', () => {
    const messages = [
      createThreadMessage('assistant', '第一条', 'a1'),
      createThreadMessage('assistant', '第二条', 'a2'),
    ];

    expect(markThreadMessageSaved(messages, 'a2')).toEqual([
      expect.objectContaining({ id: 'a1', saved: false }),
      expect.objectContaining({ id: 'a2', saved: true }),
    ]);
  });

  it('turns common markdown into clean mobile reading text', () => {
    expect(formatMarkdownForReading('# 结论\n\n- **重点**\n- [资料](https://example.com)\n\n`概念`')).toBe(
      '结论\n\n• 重点\n• 资料（https://example.com）\n\n概念',
    );
  });
});
