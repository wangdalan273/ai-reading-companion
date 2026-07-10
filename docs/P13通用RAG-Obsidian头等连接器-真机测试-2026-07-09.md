# P13 通用跨书/跨笔记 RAG + Obsidian 头等连接器 · 真机测试

> 交付日期：2026-07-09
> 核心理念：**通用 RAG 是地基（chunk 来源无关），Obsidian 是头等连接器 + 默认导出格式，非唯一路径**。

## 一、前置
- 服务已在 `http://127.0.0.1:8123` 运行（后台 task `9icBnR`）。
- 浏览器开 `http://127.0.0.1:8123/` 或 `/enter` 免注册进入。
- 已有一本导入的书（如《零起点学中医》）更符合演示效果。

## 二、验证通用版（不绑 Obsidian）
1. 仪表盘点右上角 **🧠 记忆 / 知识库**。
2. 「连接器配置」里**只填「通用笔记文件夹」**（任意本地放 `.md` 的目录，例如 `D:/my-notes`），**不填** Obsidian vault。
3. 点 **💾 保存路径** → 点 **🔄 重建索引**（约数秒~数十秒，取决于书与笔记量）。
4. 看顶部三张统计卡：📚 书片段 / 📝 通用笔记 应有数字，🔗 Obsidian 为 0。
5. 在问答框问「你笔记里关于 XX 怎么讲」→ 应带 `《笔记标题》` 引用回答（证明通用版独立可用）。

## 三、验证 Obsidian 双向（你个人重点）
1. 「连接器配置」填 **Obsidian vault 路径**（你 vault 的 Windows 绝对路径，如 `D:/Notes/我的vault`）。
2. 保存 → 重建索引。统计卡 🔗 Obsidian 笔记出现数字。
3. 问任意跨书+笔记问题（如「我读过的书与笔记里，对『气血』分别怎么讲？」）。
4. 回答应同时引用：**《书名》第N章**（书）+ **[[笔记标题]]**（Obsidian 双链）+ **《笔记标题》**（通用笔记）。
5. 点 📎 参考来源可看检索到的片段预览。

## 四、自定义伴读人格
1. 「🎭 自定义伴读人格」下填名称+System Prompt（如「苏格拉底导师：只问不答，引导我思考」），可勾「设为默认」。
2. 问答框上方下拉选该人格 → 提问，回答语气/角色随之变。
3. 删除/切换默认即时生效。

## 五、离线降级验证（不假死铁律）
- 未填密钥（mock 模式）：重建索引/问答仍正常，问答走离线演示流，带引用。
- 填了密钥但 429 限流：LlmService 自动退避重试，耗尽降级离线回复，面板不假死。

## 六、技术验收（自测已闭环）
- 迁移 `rag_chunks`+`user_prompts` DONE；`ai_configs` 加 `vault_path`/`note_folder` 列。
- `php -l` 全过；`vite build` 成功；`book-rag` 视图渲染 41k 字符无白屏。
- 离线自测：通用文件夹(2块)+Obsidian vault(1块,`[[双链]]`解析成功) 索引；BM25 检索命中；流式回答同时引用书/通用笔记/Obsidian 且内联 `[[中医笔记]]`。
- 路由：`/rag`(302 auth)、`/rag/index` `/rag/ask` `/rag/hits` `/rag/settings` `/rag/prompts*` 均注册（POST 需 CSRF token，浏览器自动带）。

## 七、实现要点（给维护者）
- `RagService`：来源无关。索引三路 `indexBook`/`indexVault`/`indexNoteFolder`；`search` 用 BM25（中文 字+二元切分），`embeddings()` 可插拔（有则余弦融合，无则纯 BM25）；`answer` 流式带引用。
- Obsidian 只是 `source_type=obsidian` 的连接器之一；换任意 md 文件夹（`source_type=note`）走同一引擎。
- 导出侧复用既有 `ExportService` 的 `[[双链]]`+frontmatter+callout 范式（N12 原子卡将复用）。
