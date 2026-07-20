# Changelog

## Unreleased

- Clarified the hosted and self-hosted installation paths, including one-prompt Agent setup and Android-to-local-server connection requirements.

## 1.1.12 - 2026-07-20

### Android 阅读与导入

- 修复 Android 端 PDF / EPUB 表单上传兼容性，两种格式均可从系统文件选择器导入。
- 正式包默认连接 HTTPS 生产服务，修复明文 HTTP 被 Android 网络安全策略拦截的问题。
- 导入后的书籍使用本地缓存直接阅读，完善缓存完整性校验、失败提示和重试流程。
- 阅读位置和书签加入账号同步，同时保留本地状态用于离线恢复。

### 划线、笔记与复习

- 普通划线改为下划线显示，带笔记内容的标注使用高亮显示，阅读时可以直接区分。
- 统一长按选文操作栏，支持划线、写笔记、问 AI、复制、分享、制成闪卡和删除。
- 划线、笔记、AI 回答收藏统一进入笔记库与复习数据源，保存后立即刷新界面。
- 移动端补充闪卡创建与复习入口，划线和收藏内容可按类型查看。

### AI 对话

- 选文问 AI 会恢复已保存回答，不再因再次点击而重复请求大模型。
- 选文对话历史只显示与当前原文有关的内容，并移入独立管理面板，避免遮挡正文。
- AI 伴读支持真正独立的新对话、历史切换和对话删除。
- 删除操作增加二次确认、处理中状态、即时界面更新和失败反馈。
- 优化 AI 回答的流式显示和 Markdown 阅读渲染，回答可直接保存到笔记库。

### 同步与界面

- 完善移动端与 Web 端的笔记库、阅读状态和保存内容同步接口。
- “我的”数据状态调整为同步状态展示，并提供主动更新入口。
- 移除移动端不可用的外部资料上传入口，减少重复和无效操作。

- Prepared clean open-source package.
- Added local co-reading frontend and dashboard frontend assets.
- Added reading workspace initializer.
- Added local Python bridge and launcher.
- Added generic local model CLI auto-detection for `claude`, `codex`, and `gemini`.
- Added privacy, configuration, development, contribution, and security docs.
