# Phase 3 实施计划：通用跨书/跨笔记 RAG + 知识库图谱（Obsidian 为头等连接器）

> 文档目标：把原排期里的 P13（跨书 RAG + 自定义 prompt）和 N12（知识库图谱 / Zettelkasten）**以「通用 RAG 为地基 + Obsidian 为头等连接器」重做设计**。
> 配套：原 `功能详细设计与排期-第一性原理-2026-07-08.md`（Phase 3/4 章节）。
> 用户硬约束（2026-07-09 明确）：「通用的版本也要做，但 Obsidian 要重点结合——因为我个人需要。不是放弃通用版只做 Obsidian。」

---

## 0. 第一性原理：通用 RAG 是地基，Obsidian 是你在地基上优先盖的那栋楼

1. **RAG 内核必须 source-agnostic（来源无关）**：一个 chunk = 「一段文本 + 元数据」。引擎不关心它来自书、来自 Obsidian vault、还是来自你随手粘贴的笔记。地基通用，谁都能用、不绑定任何软件。
2. **Obsidian 是「头等连接器 + 默认导出格式」，不是唯一连接器**：因为你个人知识工作流就在 Obsidian（本地 `.md` + `[[双链]]` + 图谱），所以把它做成：
   - 导入侧的第 1 个连接器：vault `.md` 回灌检索，保留 `[[双链]]`；
   - 导出侧的默认语法：`[[双链]]` + frontmatter + callout，你拖进 vault 即连网。
   - **但系统没有 Obsidian 也能完整跑**：换成任意 markdown 文件夹、或粘贴笔记，一样索引、一样问答。
3. **伴读 vs 普通 Chat 的分水岭 = 跨连接**：读过的书之间、书与笔记之间互相印证，知识才长成网。Obsidian 是这个网的最佳宿主之一，但不是唯一宿主——地基保证「换连接器也不崩」。

> 一句话架构：**通用 RAG（chunk 来源无关）= 地基；Obsidian 连接器 + 导出范式 = 你优先用的那套家具；将来加书源/Notion/其他连接器只是再挂一套家具。**

---

## 1. 已验证的环境约束（扫盲区结论，决定方案）

| 项目 | 现状 | 影响 |
|---|---|---|
| `ExportService` | 已有 `toMarkdown`/`toConversationMarkdown`/`pushToObsidian`，用 frontmatter + `> [!note]`/`> [!question]` callout + `source: "[[书名]]"` | **直接复用**，原子卡不必重造导出范式 |
| `LlmService` | 仅有 `stream()` / `complete()`，**无 `embeddings()`** | 需新增 embedding 调用方法（可插拔） |
| `sqlite-vec` | Windows PHP 8.4 **装不上**（`vec0 NOT loadable`） | **不能用 sqlite-vec**，改 SQLite 存向量 JSON + PHP 层算余弦 |
| embedding 端点 | 取决于用户 provider；CloudBase 网关大概率**无 `/embeddings`** | 无端点时**降级 BM25 关键词检索**，检索永不假死 |

**结论**：放弃「sqlite-vec + 云端 embedding」原设想，改用「SQLite 存向量 + PHP 余弦 + BM25 兜底双路」零依赖方案。完全契合「本地优先、栈统一、不假死」。

---

## 2. 架构设计（通用优先 + Obsidian 强调）

### 2.1 向量存储：SQLite JSON + PHP 余弦（零新增依赖）
- 新表 `rag_chunks`：`id, user_id, source_type(book|obsidian|note|other), source_path, book_id(nullable), title, content, chunk_index, links(json 解析出的[[双链]]目标), meta(json 标签/页码/连接器), embedding(json nullable), created_at`。
- `source_type` 明确是**枚举而非硬编码 Obsidian**：将来加 `notion`/`web` 连接器只是新增一个值。
- 相似度在 **PHP 内存层算余弦**（个人书库 + 一个 vault 量级几千~几万片段，毫秒级）。

### 2.2 Embedding：有则用，无则降级（一贯铁律）
- `LlmService::embeddings()`：调 `/embeddings`；失败 / 无端点 / 无 key → 返回 `null`，chunk `embedding` 置 `null`。
- **检索双路融合**：向量路（余弦，仅 embedding 非空）+ 关键词路（BM25，始终可用）。归一化加权。即使零 embedding，BM25 也能答。

### 2.3 索引源（连接器化，Obsidian 是第 1 个）
- **书（已有数据，通用）**：章节文本、划线 `annotations`、AI 对话 `chats`。
- **Obsidian vault（头等连接器）**：递归扫 `.md`（排除 `.obsidian/`、`.trash/`、`.git/`），按 `# 标题`/空行切原子片段；解析 `[[ ]]` → `links`；解析 frontmatter `tags`。
- **通用 markdown 文件夹（通用，不绑定 Obsidian）**：任意目录的 `.md`/`.txt`，切法与 vault 一致，`source_type=note`。
- **粘贴笔记（通用）**：将来 UI 支持粘贴文本直接入库，`source_type=note`。

### 2.4 回灌 → 检索（双向打通，Obsidian 优先成网）
- 索引后检索库 = 书片段 ∪ 各连接器片段。
- 回答带引用：书 → 「据《书名》第 N 章」；Obsidian → 「据你的笔记 `[[笔记名]]`」；通用 note → 「据笔记《标题》」。
- 你在 Obsidian 点 `[[笔记名]]` 即跳原笔记——形成「伴读提问 → 回链第二大脑」闭环；非 Obsidian 笔记则给文件路径。

### 2.5 导出 → Obsidian（默认格式，复用既有范式）
- 原子卡/对话/图谱导出 = 标准 Obsidian `.md`：frontmatter + `> [!note]` callout + `source: "[[书名/笔记名]]"` + 相关卡用 `[[双链]]` 呈现。
- 复用 `ExportService::pushToObsidian` 写文件逻辑，新增 `toAtomicCard()` / `pushAtomicCard()`。
- 非 Obsidian 用户：同样导出标准 markdown，只是 `[[ ]]` 在他工具里是纯文本（不影响通用版）。

### 2.6 自定义 System Prompt（通用）
- `settings.ai` 加多行框「自定义伴读人格」，存 `user_prompts` 表。
- 优先级高于全局 `companion.system_prompt`；注入所有问答（跨书问答、苏格拉底、常规问）。

---

## 3. 实施步骤拆解

| # | 任务 | 关键产出 |
|---|---|---|
| 1 | 迁移：`rag_chunks` + `user_prompts` 表 | 落库结构（source_type 枚举通用） |
| 2 | `EmbeddingService`（`LlmService::embeddings`）+ `Bm25` 工具类 | 向量与关键词双引擎（可插拔） |
| 3 | `VaultIndexer` / 通用 `NoteIndexer`：扫目录、切原子片段、解析 `[[ ]]` 与 frontmatter、增量更新 | vault 与通用文件夹都能进索引 |
| 4 | `RagService`：双路检索融合 + 拼 context（带引用）+ 降级 | 检索核心（来源无关） |
| 5 | `RagController` + 「🧠 记忆」页：索引状态、vault/文件夹路径配置、自定义 prompt、流式问答带引用 | 问答入口 + 配置面 |
| 6 | 导出：`toAtomicCard` / `pushAtomicCard`（Obsidian 双链成网） | Obsidian 成网 |
| 7 | 自测（php -l / 迁移 / vite build / 真 HTTP 索引+问答）+ 重启服务 | 闭环 |

---

## 4. 待你确认（非阻塞，已给默认即可开工）

- **A. vault 路径**：你的 Obsidian vault Windows 绝对路径？（如 `D:/Notes/我的vault`）。设置页留输入框，自测用临时 vault，不阻塞。
- **B. embeddings provider**：若无 `/embeddings` 端点，P13 先用 **BM25 跑通**（离线能答），语义检索作为可插拔增强，不阻塞。
- **C. 索引范围**：默认「所有书 + vault 整库」；排除 `.obsidian/`/`.trash/`/`.git/` 自动跳过。

---

## 5. 验收标准

1. 配置 vault 路径 → 点「重建索引」→ `rag_chunks` 同时出现书片段（source_type=book）与 vault 片段（source_type=obsidian，含 links 双链）。
2. 跨书/跨笔记问答：问「X 在我读过的书和笔记里分别怎么讲」→ 带书名 / `[[笔记名]]` 引用（**离线 BM25 也能答**）。
3. 原子卡导出 → vault 生成 `.md`，Obsidian 中 `[[双链]]` 可点成网。
4. 自定义 prompt 生效：改设置后问答语气/角色随之变。
5. 降级验证：无 key / 无 embedding 时，检索与问答不假死（BM25 兜底）。
6. **通用性验证**：换一个非 Obsidian 的 markdown 文件夹做目录索引，同样能检索问答（证明地基通用）。

---

## 6. 风险与降级

- `sqlite-vec` 不可装 → **已决策** SQLite JSON + PHP 余弦，零依赖。
- embedding 无端点 → **BM25 降级**，检索永不假死。
- vault/目录不可读 → 仅索引书，UI 提示配置，不影响现有功能。
- 量级瓶颈 → 个人 vault < 5 万片段，PHP 余弦足够；真到瓶颈再评估。

---

## 7. 后续（本计划不含，已就位可接）

- **N12 知识库图谱**：吃 `rag_chunks`，Obsidian `[[双链]]` 优先成网，复用 N3 canvas 力导向。
- **P15 苏格拉底 + 测验**：吃 `rag_chunks` 与划线。
- **书源接入（参考项）**：Phase 3 文本结构化后，可接 `hectorqin/reader` / legado 书源做「在线找书 → 一键导入伴读」POC。
- **N14 语音 / N15 荐书**（Phase 4）：独立可后置。
