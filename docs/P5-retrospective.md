# P5 复盘：真机反馈修复轮（白屏 / 上传 / AI接入 / 三栏 / 移动端）

> 背景：P4 收官后用户**在真浏览器里实测**，反馈 5 类问题。本轮不再反问，直接落地修复。

## 1. 上传 413（已根治）
- 受管 PHP 默认 `post_max_size=8M / upload_max_filesize=2M`，EPUB 稍大即被拒。
- 修复：直接改受管 `C:\Users\86155\.workbuddy\binaries\php\8.4\php.ini` →
  `upload_max_filesize=128M / post_max_size=128M / memory_limit=256M`（对 `artisan serve` 永久生效）。
- 同时对书架上传框加**客户端预校验**：`@change` 检测 >120MB 直接提示「请压缩后再上传」并清空，不再裸奔 413。
- 注意：若用户在**自己的机器**跑环境，需自行调大这两项（或生产用 Nginx `client_max_body_size`）。

## 2. AI 接入做成应用内界面（不再改 .env）
- 新增 `ai_configs` 表 + `AiConfig` 模型（`api_key` 用 `encrypted` 加密落库，绝不下发前端）。
- 新增 `/settings/ai` 设置页（Volt 组件 `livewire/ai-settings.blade.php`）：选厂商
  OpenAI / DeepSeek / Kimi / 自定义 + 填 key / base_url / model，内置「保存并测试连接」。
- `LlmService` 改为**优先读当前用户 DB 配置**，回退 `config('companion.*')`；密钥只在服务端使用。
- 书架页、阅读器工具栏、AI 面板均放「⚙️ AI 设置」入口。

## 3. 阅读器白屏（根因 + 硬化）
- 原 `reader.js` 靠 `x-init="window.CompanionReader.start(...)"` 启动，且**错误只在 `display()` 抛错时显示**；
  若失败更底层（动态 import 时序、iframe 未注入），容器为空 = 纯白屏，浮层不触发。
- 重写 `reader.js`：
  - **自初始化**：`DOMContentLoaded` 后扫描 `[data-reader-url]` 启动，消除 Alpine 时序竞态。
  - **加载态**：打开即显示 spinner + 「正在打开本书…」，不再是空白。
  - **全局错误横幅**：`window.error` / `unhandledrejection` 捕获后弹出红色错误框，显示
    「阶段 + 具体错误信息 + 出错 URL + 重新加载按钮」——**任何失败都可见，杜绝白屏**。
  - 暴露 `companion:toc` 事件给目录面板。

## 4. 三栏阅读 + 目录 + 划线持久化
- `read.blade.php` 改为**桌面三栏** `[目录 280px | 阅读区 | AI共读 360px]`；
  手机阅读全屏 + 目录左滑抽屉 + AI 底部抽屉（沿用既有模式）。
- 目录从 epub.js `navigation.toc` 渲染，点击章节 `rendition.display(href)` 跳转。
- **划线持久化**（核心痛点落地）：选中文字 → 浮条「🖍 划线 / 💬 问 AI」；
  「划线」调用 `rendition.getSelectionCfi()` 存为 CFI 到 `annotations` 表（接口 GET/POST/DELETE
  `/book/{book}/annotations`）；打开书自动还原历史划线；点划线可「问 AI / 删除」。
- 注：`annotations` 表在 P0 迁移里已预留，本轮直接复用。

## 5. 移动端书架首页改版
- 顶部加说明行 + 「⚙️ AI 设置」入口；上传区保留清晰主操作。
- 空状态更友好（图标 + 引导文案）。
- 卡片网格 `grid-cols-2 → lg:grid-cols-5` 沿用，动作按钮可换行。

## 踩坑
- **FluxUI 2 没有 `<flux:select>` 组件**（导致 `/settings/ai` 500）。改用原生 `<select>` + `wire:model`/`wire:change`。
  → 经验：Flux 可用组件以 card/button/input/badge/callout 为准，下拉用原生 select。
- 受管 PHP 的 `php.ini` 在用户 home 下，改它就对 `artisan serve` 永久生效。
- `artisan serve` 登录是 Livewire 组件（非普通表单 POST），用 curl 直接 POST `/login` 会 405，
  应用内登录正常；验收/调试一律走 in-process `Auth::login`。

## 验证
- `php artisan migrate` 建 `ai_configs`；`npm run build` 成功。
- `p4_acceptance.php` 重跑 **13/13 PASS**（无回归）。
- 进程内请求确认：`/settings/ai` GET 200、`/book/{id}/annotations` GET 200 返回 `{"annotations":[]}`。
- **未覆盖**：epub.js 真浏览器渲染 / 流式打字机 / 选中划线气泡 —— 仍需用户在预览面板实走一遍；
  但已保证「失败可见」而非白屏。
