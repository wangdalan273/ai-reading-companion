# P3 阶段：导出 + Obsidian 推送

> 目标（用户拍板，A 导出 Markdown + C 直推 Obsidian）：把划线与 AI 解读导出成 Obsidian 友好的 Markdown，并可直推 Obsidian vault。

## 做了什么
- `config/companion.php` 加 `obsidian_vault_path`（env `COMPANION_OBSIDIAN_VAULT`）。
- `app/Services/ExportService.php`：
  - `toMarkdown(Book)`：生成 Obsidian 友好 Markdown——YAML frontmatter（`title`/`author`/`date`/`tags`/`source` 双链 `[[书名]]`）+ 每条划线（引用块 `>`）+ 批注 + 标签 + 关联该划线的 AI 解读（按 `context == quote` 匹配最近的 assistant 回答）。
  - `pushToObsidian(Book)`：vault 路径为空/不可写时优雅报错；否则写 `{书名}-伴读.md`。
- 路由 `GET /book/{book}/export/markdown`（auth + 归属）：返回 `text/markdown` 下载响应（`Content-Disposition: attachment`）。
- 书架 Volt 组件加 `pushObsidian(Book)` 动作 + 卡片「导出 MD」链接与「推 Obsidian」按钮（带成功/失败 flash）。

## 关键坑
- **导出关联 AI 解读**：靠 `chats.context` 存"选中的原文"，导出时按 `context == annotation.quote` 匹配最近的 assistant 回答。因此 P2「选中问 AI」必须把原文原样回存，才能对上号（已如此实现）。
- **Obsidian 推送前提**：需服务端能访问 vault 目录（自用 / 本地场景最稳）。沙箱或纯手机端无法直接写——这是"直推"的工程现实，已用优雅降级（未配置则提示）处理。

## 验证（`p3_smoke.php`）全绿
- `toMarkdown` 含 frontmatter / 引用 / 批注 / AI 解读
- 下载路由 200 + `text/markdown` + attachment + 正文
- 推送到临时 vault 文件生成且含原文；未配置时优雅失败
- 书架渲染「导出 MD」「推 Obsidian」

## 当前进度
- P0 ✅、P1 ✅、P2 ✅、P3 ✅
- 下一步 P4：手机打磨 + 验收 + 小测验（收官）
