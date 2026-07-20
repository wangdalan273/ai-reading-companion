import type { User } from '../types';

export function createProfilePresentation(user?: User | null) {
  return {
    avatar: user?.name?.trim().slice(0, 1) || '阅',
    name: user?.name?.trim() || '阅读者',
    email: user?.email || '账号信息暂不可用',
    aiSettings: { title: 'AI 服务', action: '设置', accountWide: true },
    dataStatus: [
      { title: '账号数据', detail: '书籍、划线、笔记、闪卡、AI 对话与收藏', badge: '已同步' },
      { title: '阅读状态', detail: '精确阅读位置、书签和阅读统计会同步到电脑端', badge: '已同步' },
    ],
  };
}
