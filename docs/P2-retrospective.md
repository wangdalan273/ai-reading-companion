# P2 阶段：旁边问 AI（SSE 薄代理）

> 目标（用户拍板）：在阅读器旁加对话栏，选中书中句子即可问 AI 解释/建议；后端 SSE 薄代理转发 LLM，key 不落前端。

## 做了什么
- `config/companion.php`：provider / api_key / base_url / model / system_prompt / mock。API key 只存服务端（`.env`），永不进浏览器。
- `app/Services/LlmService.php`：`stream()` 生成器。有 key → OpenAI 兼容 Chat Completions SSE 流式（Guzzle `stream=>true` 逐块读 `data:` 帧）；无 key 或 `mock` → 中文兜底流式（按 3 字切块 + 微延迟，模拟打字）。
- `app/Http/Controllers/CompanionController.php`：`POST /api/companion/ask`（auth），`response()->stream()` 返回 `text/event-stream`（禁缓冲），流结束把「用户 + AI」两条写入 `chats` 表。
- 前端 `resources/js/companion.js`：Alpine `companionChat`——消息列表、输入、`fetch` POST 流式读 token 追加；捕获 epub iframe 内选区，浮动「问 AI」按钮，点击把原文带入对话上下文。
- `resources/js/reader.js`：渲染后（及每次翻页 `rendition.on('rendered')`）给 epub iframe 的 document 绑 `mouseup`，选区变化抛 `onSelection(text, pos)`。
- `read.blade.php`：响应式对话栏——桌面右侧常驻栏（grid 360px），手机底部上滑抽屉（FAB 切换 + `translate-y` 动画）；含浮动选区按钮。
- `npm run build` 重新打包（companion.js 进 bundle）。

## 关键坑
- **StreamedResponse 类型**：`Illuminate\Http\Response` 构造不接受闭包，必须用 `response()->stream()`（返回 `StreamedResponse`），方法返回类型也要声明 `StreamedResponse`，否则报类型错误。
- **epub.js iframe 内选区**：选区发生在 iframe 内部，须监听 `iframe.contentDocument` 的 `mouseup`（同域才能访问）；翻页会换 iframe，所以绑在 `rendition.on('rendered')` 上每次重绑。
- **Alpine 与 Livewire 分工**：聊天的逐 token 流式走纯 Alpine + `fetch`（不每次往返 Livewire），避免高频服务端请求；viewer 依旧 `wire:ignore`。

## 验证（`p2_smoke.php`，in-process）全绿
- LlmService mock 流式含「选中的句子」
- `/api/companion/ask` → 200，SSE 收集 194 字，含 `[DONE]`
- `chats` 表新增 user + assistant 两条
- `/read/{book}` 含 `companionChat(` 与「伴读对话」

**未覆盖**：真实浏览器里 Alpine 流式渲染 + 选区气泡的交互（需浏览器走一遍，建议 P4）；真实模型需配置 `COMPANION_API_KEY` + 网络。

## 当前进度
- P0 ✅、P1 ✅、P2 问 AI 骨架 ✅
- 下一步 P3：导出 + Obsidian 推送
