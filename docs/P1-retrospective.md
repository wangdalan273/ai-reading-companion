# P1 阶段：EPUB 阅读器骨架

> 目标（用户拍板）：先做到「打开一本书 → 翻页 → 正文渲染」。划线(CFI) 留到下一步。

## 做了什么
- 安装 `epubjs`（npm）。
- 新增两个路由（均在 `routes/web.php`，auth 中间件 + `user_id` 归属校验）：
  - `GET /read/{book}`：阅读器页，渲染 `views/read.blade.php`（普通页面）→ 内嵌 Volt 组件 `views/livewire/reader.blade.php`。
  - `GET /book/{book}/file`：鉴权后流式返回书籍文件。用 `Storage::disk('local')->response()`（Laravel 11 的 `serve` 机制，自动 content-type + 支持范围请求），供 epub.js 同源 fetch。
- 阅读器 Volt 组件：标题 / 返回书架链接 + `wire:ignore` 的 `#viewer` 容器 + 上一页/下一页按钮 + PDF 占位提示。
- `resources/js/reader.js`：封装 `CompanionReader`，**动态 `import('epubjs')`**（不进全局包），负责 `book.renderTo` + `display` + 翻页 + 方向键；由 Alpine `x-init` 调用 `window.CompanionReader.start(url, container)`。
- 书架「打开」按钮从 flash 提示改为跳转到 `/read/{book}`。
- `npm run build` 生成 `public/build`，单靠 `php artisan serve` 即可加载真实 JS（含 epub.js）。

## 关键坑与教训
### 1. Livewire × epub.js 共存（计划里标记的高风险，已化解）
- **风险**：epub.js 会把 iframe 注入 DOM；Livewire 每次请求做 DOM 差分，可能把 iframe 冲掉。
- **解法**：viewer 容器标 `wire:ignore`；epub.js 的初始化与翻页**全部走原生 JS**（Alpine `x-init` + 全局 `CompanionReader`），不碰 Livewire 属性/方法。这是该风险的最小代价化解。

### 2. Laravel 11 的 local 磁盘根目录
- `Storage::disk('local')->path('books/1/x')` 解析到 `storage/app/private/books/1/x`（**不是** `storage/app/books`），因为 Laravel 11 把 local 根改到了 `storage/app/private`。
- 真实上传用 `$file->store('books/...','local')` 也落在 `private/` 下，两者一致；但**测试脚本若手动用 `storage_path('app/books/...')` 建文件会 404** —— 必须用 `Storage::disk('local')->path()` 拿真实绝对路径。

## 验证（`p1_smoke.php`，in-process 模拟登录用户）全绿
- `/read/{epub}` → 200，含 `x-ref="viewer"`、`CompanionReader.start`、`wire:ignore`
- `/book/{book}/file` → 200，`content-type: application/epub+zip`
- `/read/{pdf}` → 200，显示「二期」占位

**未覆盖**：真实浏览器里 epub.js 的视觉渲染（iframe 内容）。需 build 完成 + 浏览器走一遍（建议 P4 浏览器验收，或本地 `php artisan serve` 后访问）。

## 当前进度
- P0 ✅、P1 阅读器骨架 ✅
- 下一步 P2：旁边问 AI（SSE 薄代理）
