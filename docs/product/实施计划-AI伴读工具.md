# AI 伴读工具 · 分阶段实施计划（待审）

> 配套 PRD：`PRD-AI伴读工具-v1.0.md`（已定稿）
> 原则：**MVP 最小可用 + 架构按商用化预留**（多用户 / 数据隔离）
> 节奏：你确认后我从 P0 开工；实施中每里程碑记「踩坑笔记」，大方向变更先问你；完工后小测验满分才算完。

## 一、技术选型（已校准）
- **后端**：Laravel 11 + Livewire + FluxUI + MySQL；文件走 private storage（本地 / MinIO / S3）
- **前端阅读器**：epub.js（EPUB）+ PDF.js（PDF 文本层）；FluxUI 做外壳 / 书架 / 侧栏 / 主题
- **AI**：后端薄代理 `/api/ask`（SSE 流式），LLM key 在 .env 不落前端；provider 可配（默认接一个，商用后换）
- **为什么不全栈 SPA**：阅读器硬活在前端，但商用要账号 / 隔离 / 多用户，Laravel 一体栈最省事且质量可控；epub.js / pdf.js 作为 Alpine/JS 组件嵌在 Livewire 页内，Livewire 只管数据（存划线、代理 AI、推 Obsidian）
- **商用预留**：所有表带 `user_id`；账号 / 计费 / 多租户以后加，不返工

## 二、数据模型（要点）
- `users`（Laravel 自带）
- `books`：id, user_id, title, author, format(epub|pdf), path, created_at
- `annotations`：id, book_id, user_id, loc(CFI 或 PDF page+char), quote, note, created_at
- `chats`：id, book_id, user_id, role, content, created_at（AI 对话）
- `exports`：id, book_id, user_id, format, dest(obsidian|file), status, created_at

## 三、里程碑

**P0 脚手架**（交付：能注册登录、看到空书架）
- Laravel + Breeze + FluxUI 接入；MySQL 迁移；private storage
- 书架列表页（FluxUI 卡片网格）；light/dark/system 主题切换（premium 标配）
- 书籍上传（PDF/EPUB）→ 落库落盘

**P1 阅读器 + 划线**（核心①，交付：能读 EPUB 并划线持久化）
- epub.js 集成，三栏（目录｜书页｜侧栏占位）
- 选区气泡 → 划线（CFI 持久化）+ 可加备注；划线列表 / 跳转动点
- PDF：PDF.js 渲染（先能看）；**PDF 精准划线标注为 P1.5 风险项（见风险）**

**P2 旁边问 AI**（核心②，交付：选中即问，流式回答）
- 后端 `/api/ask` SSE；prompt = 选中文本 + 章节上下文 + 角色(解释/建议)
- 桌面：侧边常驻对话栏（就同一段可继续对话）
- 手机：底部抽屉 AI 面板（按提议实现，UI 阶段细调）
- 对话存入 `chats`

**P3 导出 + Obsidian**（交付：一键出 MD / 直推 Obsidian）
- 导出 Markdown（高亮 + AI 对话记录，Obsidian 友好格式）
- 直推 Obsidian：走 Local REST API（需你装插件 + 配 key），后端 job 推送；失败兜底 = 下载 .md

**P4 手机打磨 + 验收**（交付：全端顺滑 + 小测验）
- 手机端选中气泡 + 抽屉面板落地调优
- 响应式、玻璃拟态、60fps、无障碍（WCAG 2.1 AA）
- 全流程跑通 → 出小测验考你

## 四、关键技术风险 & 对策
- 🔴 **PDF 精准划线持久化**（最大坑）：PDF.js 文本层能选中，但高亮跨缩放 / 重渲染要对回原位需 page+char offset 映射。对策：P1 先 EPUB 全功能；PDF 用 PDF.js 渲染并做基础选中高亮，持久化在 P1.5 加固；若时间紧，PDF v1 先"能读 + 能选中问 AI"，精准划线留 P1.5。
- 🟡 **Livewire 与 epub.js 共存**：Livewire 整页刷新会打断阅读器。对策：阅读器作为静态 Alpine/JS 组件，Livewire 只做数据接口（wire 调 save），不包住渲染区。
- 🟡 **Obsidian 推送依赖**：需用户本地 Obsidian + Local REST API 插件 + key。对策：做成可选，缺配置时自动降级为下载 .md。
- 🟡 **LLM 上下文长度**：只注入选中文本 + 章节标题 + 近几轮，不塞整本；RAG 留商用二期。

## 五、明确「这一版不做」
- 不做社区 / 分享 / 多人协作
- 不做书籍级 RAG / 向量检索（商用二期）
- 不做 Web 剪藏（v1 仅 PDF/EPUB 导入）
- 不做多端实时同步（架构留接口，v1 单设备即可）

## 六、团队带教（全栈都会）
- 我定架构 + 代码评审 + 每里程碑「代码质量笔记」
- 分头：一人攻阅读器（epub.js / PDF.js / 选区），一人攻后端（迁移 / LLM 代理 / Obsidian），我带 UI 打磨
- 实施中撞坑记笔记，大方向变更先问你

## 七、下一步
你确认这份计划 → 我从 P0 开工，并开「踩坑笔记」。
