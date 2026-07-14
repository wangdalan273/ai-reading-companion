import type { User } from '../types';

export function createProfilePresentation(user?: User | null) {
  return {
    avatar: user?.name?.trim().slice(0, 1) || '阅',
    name: user?.name?.trim() || '阅读者',
    email: user?.email || '账号信息暂不可用',
    aiSettings: { title: 'AI 服务', action: '设置', accountWide: true },
    dataStatus: [
      { title: '随账号使用', detail: '书籍、标注、闪卡、阅读统计、AI 设置与收藏', badge: '自动' },
      { title: '仅在本机', detail: '精确阅读位置与书签，卸载或清除数据后会丢失', badge: '本机' },
    ],
  };
}
