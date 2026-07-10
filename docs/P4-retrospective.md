# P4 阶段：手机打磨 + 整链验收 + 小测验（收官）

> 目标（用户拍板，P4 收官）：手机端体验打磨（iOS 安全区适配）、全链路自动化验收、最后用「找未知工作法」小测验验收用户对产品/架构关键决策的理解。

## 做了什么

### P4-1 手机打磨（iOS 安全区适配）
- `resources/css/app.css` 加三类安全区工具类：
  - `.safe-t` → `padding-top: env(safe-area-inset-top)`
  - `.safe-b` → `padding-bottom: env(safe-area-inset-bottom)`
  - `.safe-b-lg` → `padding-bottom: calc(env(safe-area-inset-bottom) + 1rem)`
- `resources/views/read.blade.php` 应用：
  - 浮动「问 AI」FAB 加 `safe-b-lg`，避免 iPhone 底部 Home 指示条遮挡按钮。
  - 手机端底部抽屉 `aside`（上滑抽屉）加 `safe-b`，让抽屉内容与底部指示条留出安全间距。
- `npm run build` 成功，`public/build` 已含最新 CSS/JS。

### P4-2 整链验收脚本（`p4_acceptance.php`）
- 覆盖 7 个环节、13 项断言：
  - S1 游客访问 `/dashboard` 必须 302 跳登录（auth 门）
  - S2 登录后书架 200 + 有上传表单
  - S3 创建书 + 书架列出该书
  - S4 阅读器 200 + 有 `x-ref="viewer"` + `companionChat(` + 书架有「导出 MD」「推 Obsidian」
  - S5 `/api/companion/ask` 200 + 落库
  - S6 导出 MD 200 + 含引用 + 含 AI 解读
  - S7 推 Obsidian 成功（临时 vault）
- 首跑 `OVERALL: FAIL`，两处 FAIL 均为**测试脚本自身 bug**（非业务代码）：
  1. `S1_guest_dashboard_redirects`：in-process `Auth::login` 后 guard 粘住，游客请求被当已登录。修复 → 游客检查挪到 `Auth::login` 之前执行。
  2. `S4_has_export_btn`：导出按钮在书架页（卡片上），不在阅读器页。修复 → 改查 `reqAuth('/dashboard')` 正文。
- 修正后重跑：13/13 PASS，`OVERALL: PASS ✅ 全链路通畅`。

## 关键坑
- **iOS 安全区**：底部上滑抽屉 / FAB 必须加 `env(safe-area-inset-bottom)`，否则 iPhone 底部指示条会物理遮挡交互区。这是「手机主战场」用户的第一性体验，不能省。
- **验收脚本的断言顺序 / 定位**：in-process 测试里 `Auth::login` 会改变全局 guard 状态，游客断言必须在其之前；按钮位置要按真实 DOM 落点查（导出在书架页而非阅读器页）。这提醒：测试代码也是代码，自身也要 review。
- **诚实 caveat（待办）**：本验收是服务端 in-process 模拟（路由/响应/落库/文件生成全真），但 **epub.js 真实视觉渲染、Alpine 流式打字机效果、选区气泡浮现** 尚未在真机/真实浏览器走一遍。沙箱无 GUI 浏览器，需后续在本地/真机打开 `php artisan serve` 地址肉眼确认交互。

## 验证结果
- `p4_acceptance.php`：13/13 PASS，`OVERALL: PASS ✅`
- `npm run build`：成功

## 当前进度
- P0 ✅、P1 ✅、P2 ✅、P3 ✅、P4 ✅（打磨 + 验收 + 笔记）
- **唯一待办**：真实浏览器/真机视觉实测（P0–P4 各阶段用户均选「直接进下一阶段」，未单独跑；属体验确认，不影响链路正确性）
- 收官后由「小测验」验收用户对产品定位与关键架构决策的理解（满分才算完）
