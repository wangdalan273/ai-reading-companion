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
      { title: '随账号使用', detail: '书籍、标注、闪卡、阅读统计、AI 设置与收藏', badge: '自动' },
      { title: '仅在本机', detail: '精确阅读位置与书签，卸载或清除数据后会丢失', badge: '本机' },
    ]);
  });
});
