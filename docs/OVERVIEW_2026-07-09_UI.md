# UI 整理与功能合并（2026-07-09）

## 做了什么

本次根据用户截图反馈，从「第一性原理」出发做了一次功能归并与 UI 重排：

1. **分析台卡片化**
   - `book-tools` 的「进入本书分析」大卡片改成 4 个紧凑子功能卡片（概念图谱 / 人物关系 / 论证地图 / 思维导图），状态 badge 与描述一目了然。
   - 伴读入口从「建设中」改为可点击的「跨书问答」卡片。

2. **阅读器布局重排**
   - 翻页按钮从底部移到阅读器顶部，避免遮挡文字、影响复制。
   - 顶部工具栏用「工具」下拉菜单收拢工作台 / 脑图 / 图谱 / 人物 / 论证 / 设置。
   - 右侧 AI 面板顶部用「⋯」菜单收纳零散入口，避免图标拥挤遮挡。
   - `reader.js` 中选区漂浮栏、术语解释气泡、高亮菜单全部改为「水平居中 + 贴近选区（4–6px）」，不再离选区太远。

3. **知识库-伴读合并（逻辑自洽）**
   - 知识库只保留「数据来源 / 索引」：连接器配置 + 重建索引 + 片段统计。
   - 删除知识库里的「自定义伴读人格」和「跨书/跨笔记问答」UI——这些功能统一归到「💬 伴读」页面。
   - 伴读页面继续承载：人格选择、检索范围（全部 / 仅笔记）、跨书问答、好回答一键加入知识库。

4. **每本书阅读时长**
   - 书架概览显示累计总阅读分钟。
   - 卡片 / 列表两种视图都显示单本书已读时长。

## 改动文件

- `resources/views/book-tools.blade.php`
- `resources/views/book-analyze.blade.php`
- `resources/views/livewire/reader.blade.php`
- `resources/views/read.blade.php`
- `resources/js/reader.js`
- `resources/views/partials/kb-rag.blade.php`
- `resources/views/knowledge-base.blade.php`
- `resources/views/livewire/dashboard.blade.php`
- `resources/views/livewire/dashboard.blade.php`（引入 `ReadingLog`）
- `.workbuddy/memory/MEMORY.md` & `2026-07-09.md`

## 验证

- 语法检查：PHP lint 全部通过。
- 前端构建：`npm run build` 成功。
- 真 HTTP：`/dashboard`、`/book/24/tools`、`/book/24/analyze`、`/read/24`、`/knowledge-base?tab=rag`、`/companion` 均 200，无异常。

## 真机测试步骤

浏览器打开 **http://127.0.0.1:8123/dashboard**：
1. 点任意书进入「🧰 功能中心」→ 确认 4 个分析功能入口卡片整齐排列。
2. 点「打开阅读」→ 确认顶部有「上一页 / 下一页」，右侧 AI 面板顶部只剩对话标题 + 「⋯」菜单。
3. 在书中选中文字 → 确认漂浮栏紧跟选区上方/下方，并水平居中。
4. 对已划线内容点击 → 确认删除/问 AI 菜单紧贴高亮。
5. 打开 **/knowledge-base?tab=rag** → 确认只剩「连接器配置 + 重建索引」，没有问答/人格 UI。
6. 打开 **/companion** → 确认人格、检索范围、对话、加入知识库均可用。

## 备注

- 知识库的后端 `/rag/prompts` 等旧 API 仍保留注册，但前端已不调用，不影响当前逻辑。
- 阅读时长依赖 `ReadingLog` 表心跳数据；若某书暂无阅读记录，显示为 0 分钟。
