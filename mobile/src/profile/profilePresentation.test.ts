import { describe, expect, it } from 'vitest';
import type { User } from '../types';
import { createProfilePresentation } from './profilePresentation';

describe('profile presentation', () => {
  it('presents account identity without duplicating the review page statistics', () => {
    const user: User = { id: 7, name: '林远', email: 'lin@example.com' };

    expect(createProfilePresentation(user)).toMatchObject({
      avatar: '林',
      name: '林远',
      email: 'lin@example.com',
      aiSettings: { title: 'AI 服务', action: '设置', accountWide: true },
    });
    expect(createProfilePresentation(user)).not.toHaveProperty('metrics');
  });

  it('labels automatic data behavior as status, not as a tappable setting', () => {
    const presentation = createProfilePresentation(undefined);

    expect(presentation.name).toBe('阅读者');
    expect(presentation.dataStatus).toEqual([
      { title: '账号数据', detail: '书籍、划线、笔记、闪卡、AI 对话与收藏', badge: '已同步' },
      { title: '阅读状态', detail: '精确阅读位置、书签和阅读统计会同步到电脑端', badge: '已同步' },
    ]);
  });
});
