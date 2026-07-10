# P0 阶段踩坑笔记与验收

> 面向团队（非技术视角也能看懂的"为什么"）+ 给后续开发的避坑清单。

## 一、P0-4 书架页 500 错误：三连击

把 dashboard 从 500 修到 200，连续踩了三个坑，每个都是"以为对了其实还差一步"。

### 坑 1：Volt 组件不在挂载目录 → `$books` 未定义
- **现象**：视图报 `Undefined variable $books`。
- **根因**：Volt 只在 `VoltServiceProvider` 声明的目录（`views/livewire`、`views/pages`）里扫描组件。dashboard 一开始放在 `views/dashboard.blade.php`（根目录），`Route::view` 就把它当**普通 Blade** 渲染，PHP 块在独立作用域跑完，`$books` 没传给模板。
- **修法**：拆成「普通 Blade 页面 + 内嵌 Volt 组件」：
  - `views/livewire/dashboard.blade.php`：真正的 Volt 组件（上传表单 + 书架网格），落在已挂载的 `livewire/` 目录。
  - `views/dashboard.blade.php`：普通页面，用 `<x-app-layout>` 包裹并 `<livewire:dashboard />`。
  - 路由回到 `Route::view('dashboard', 'dashboard')`。

### 坑 2：Livewire 整页组件找不到 layout
- **现象**：改用 `Volt::route()` 后报 `Livewire page component layout view not found: [components.layouts.app]`。
- **根因**：`Volt::route()` 把组件当"整页 page 组件"，Livewire 会去找一个 `$slot` 契约的 page layout（默认 `components.layouts.app`）；而 Breeze 的 `layouts.app` 用 `@yield/@section`，不兼容。
- **修法**：放弃整页组件，改回「页面 + 内嵌组件」模式（同坑 1）。这也和现有 navigation 组件（同在 `livewire/`）结构一致。

### 坑 3：Volt 组件只能有一个根元素
- **现象**：报 `Livewire only supports one HTML element per component`。
- **根因**：组件里有 callout / 上传卡片 / 书架网格三个并列根节点，Livewire 要求整段内容包在**唯一**根元素里。
- **修法**：最外层套一个 `<div class="space-y-8">` 把全部内容包起来。

### 给团队的教训
- Laravel + Livewire/Volt 项目里，"页面"和"组件"是两套机制：
  - 全站页面 = 普通 Blade + `<x-app-layout>` + `<livewire:xxx />`；
  - 有状态/可复用部分 = Volt 组件，必须放在 `livewire/`（或 `pages/`）下，且**只能有一个根元素**。
- 注册/登录等 Breeze 页面也是 Volt 组件（`Volt::route('register', 'pages.auth.register')`），所以是 **GET-only** 的，提交走 `wire:submit` 而非原生表单 POST——这解释了为什么用传统 `POST /register` 测会得到 405（不是 bug）。

## 二、环境层坑（影响所有人）

- 沙箱里 `artisan serve` 起不来或跑着跑着挂：根因是某个超大环境变量（`ACC_PRODUCT_CONFIG_V3`，约 300KB）溢出 PHP Windows 版 `proc_open` 的栈缓冲，导致 artisan 拉起内置服务器子进程时崩溃。
- **临时解决**：启动前 `unset ACC_PRODUCT_CONFIG_V3` 再跑 `php artisan serve`。
- **受影响命令**：任何会让 PHP 派生子进程的 artisan/composer 命令。

## 三、P0-4 验收结果

冒烟脚本（`smoke_test.php`，in-process 模拟已登录用户渲染 `/dashboard`）全部通过：
- `STATUS: 200` ✅
- 书架标题、Flux 卡片、上传表单（`wire:model="upload"`）、主题切换、Livewire 组件 全部渲染 ✅
- 数据层：Book 新建 → 读回 → 删除 全部 OK ✅

真实 HTTP 链路：`artisan serve` 启动后 `/`、`/login`、`/flux/flux.js` 均 200；未登录访问 `/dashboard` 正确 302 跳转登录页（auth 中间件生效）。

**结论**：P0-4（书架列表 + 书籍上传）功能完成。文件上传走标准 Livewire `WithFileUploads`，其"存入数据库并出现在书架"的等价逻辑已用 DB 测试验证；真实点击上传建议在 P4 浏览器验收时走一遍。

## 四、当前进度

| 阶段 | 内容 | 状态 |
|------|------|------|
| P0-1 | 环境搭建（PHP/Composer/Laravel/Livewire/Flux/Breeze/Tailwind v4） | ✅ |
| P0-2 | 前端管线打通（CSS 235KB，Flux 类 + 主色 + dark 变体） | ✅ |
| P0-3 | 数据库 4 张表迁移（books/annotations/chats/exports） | ✅ |
| P0-4 | 书架列表 + 书籍上传 | ✅（本次修复） |
| P0-5 | 踩坑笔记 + P0 验收（本文档） | ✅ |
| P1   | EPUB 阅读器 + 划线（epub.js + CFI） | 待开工 |
| P2   | 旁边问 AI（SSE 薄代理） | 待开工 |
| P3   | 导出 + Obsidian 推送 | 待开工 |
| P4   | 手机打磨 + 验收 + 小测验 | 待开工 |
