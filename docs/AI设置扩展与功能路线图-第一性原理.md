# AI 伴读 · 自定义 Provider 扩展 + 功能路线图（第一性原理）

> 文档目的：① 把「自定义 AI 只支持 OpenAI」这个现状的根因讲清楚，并给出最小可用的改造方案；② 基于 GitHub 同类项目调研，从第一性原理推导我们还能加什么功能、按什么顺序加。
> 阅读对象：产品负责人（非技术背景也能看懂方案与取舍）。

---

## 0. 一句话结论

- **现状痛点**：「自定义」现在只能填 OpenAI 兼容的接口。根因不是数据库限制，而是 `LlmService` 把「请求地址 / 鉴权方式 / 请求体 / 流式响应解析」全部写死成了 OpenAI 一种格式。想接 Claude、Gemini 这类**不同协议**的厂商，光改设置页没用，必须改服务层。
- **改造方向**：引入一个「协议适配层」，让每个配置带上一个 `format` 字段（openai / anthropic / gemini），服务层按格式分流。这样「自定义」就能选协议，Claude / Gemini 也能做成一键预设。
- **功能方向**：GitHub 上同类产品验证过——我们缺的**不是「问答」本身**，而是「把读过的东西结构化沉淀并复用」：章节总结、思维导图、跨书知识库问答、苏格拉底式追问、音频概览、双语对照。

---

## 1. 现状事实（基于源码，避免拍脑袋）

| 文件 | 现状 | 痛点 |
|---|---|---|
| `app/Models/AiConfig.php` | 只有 4 个字段：`provider / api_key(加密) / base_url / model`；`presets()` 有 18 个内置 + `custom`（标注"OpenAI 兼容"） | 没有「协议/格式」字段；`custom` 仅是"手填 OpenAI 地址"的代名词 |
| `app/Services/LlmService.php` | `realStream()` 写死 `POST {base_url}/chat/completions` + `Authorization: Bearer` + 解析 `choices[0].delta.content`；`testConnection()` 同样写死 | **全链路 OpenAI 格式**，Anthropic / Gemini 的 URL、鉴权头、响应路径都不同，跑不通 |
| `resources/views/livewire/ai-settings.blade.php` | 表单只有 `provider / apiKey / baseUrl / model`；`applyPreset()` 只带出 base_url 和 model | 没有「协议」下拉；用户无法声明"我用的是 Anthropic 还是 Gemini" |
| `database/migrations/...create_ai_configs_table.php` | 表结构无 `format` 列 | 即使前端想存协议，也没地方存 |

**结论**：要接非 OpenAI 厂商，必须改 4 处（迁移加列 → 模型加字段 → 服务层分流转发/解析 → 前端加协议选择）。改动集中、风险可控。

---

## 2. 从第一性原理看「为什么是这三套协议」

### 2.1 LLM API 的本质

抛开品牌，任何一家大模型对外只暴露两件事：

1. **怎么发请求**：请求地址（URL）、鉴权方式（Header 还是 URL 拼 key）、请求体长什么样（字段名、角色名、系统提示放哪）。
2. **怎么收流式回答**：服务器用 SSE（一行行 `data:` 推送）把字吐回来，但每家吐出来的 JSON 字段路径不同。

所以"支持一家新厂商" = 写好两个函数：
- `buildRequest(cfg, messages)` —— 按格式拼出正确的 URL / Header / Body
- `parseStream(body)` —— 按格式从 SSE 里抠出 `text`

这就是**适配器（Adapter）模式**的第一性原理：协议千变万化，但"发消息→收文字流"这个接口不变。

### 2.2 业界实际上只有约 4 种「形状」

| 形状 | 代表厂商 | 鉴权 | 请求体特征 | 流式响应路径 |
|---|---|---|---|---|
| **OpenAI Chat Completions** | OpenAI、DeepSeek、Kimi、智谱、通义、本地 Ollama/LM Studio/vLLM | `Bearer` Header | `messages:[{role,content}]`，`system` 也是一条 message | `choices[0].delta.content` |
| **Anthropic Messages** | Claude 系列 | `x-api-key` + `anthropic-version` Header | `system` 是**顶层字段**，`messages` 只含 user/assistant | `delta.text`（事件名 `content_block_delta`） |
| **Google Gemini** | Gemini 系列 | key 拼在 **URL query**（`?key=`） | `contents:[{role:"user"/"model",parts:[{text}]}]`，系统提示用 `systemInstruction` | `candidates[0].content.parts[0].text` |
| **Azure OpenAI** | 微软云 | OpenAI 兼容 + `api-version` 参数 + 部署名 | 同 OpenAI | 同 OpenAI |

> 第一性原理推论：**绝大多数国产大模型（DeepSeek/Kimi/智谱/通义/百川…）主动做了 OpenAI 兼容**，所以"OpenAI 形状"一家就覆盖了 90% 场景。真正的"异类"只有 Anthropic 和 Gemini 两家。因此我们只要多做 2 个适配器，就能覆盖几乎全部主流厂商。

---

## 3. 改造方案（P11 · 最小可用）

### 3.1 数据库迁移：加 `format` 列
`ai_configs` 表新增 `string('format')->default('openai')`。取值：`openai / anthropic / gemini`（未来可加 `azure`）。

### 3.2 `AiConfig::presets()`：每个预设带 `format`，并升级 Claude / Gemini 为一等公民
- 现有 18 个预设 `format` 全部标 `openai`（它们本就兼容）。
- 新增两个预设：
  - `claude` → `format: anthropic`，`base_url: https://api.anthropic.com`（自动拼 `/v1/messages`）
  - `gemini` → `format: gemini`，`base_url: https://generativelanguage.googleapis.com/v1beta`
- `custom` 不再写死"OpenAI 兼容"，而是**由用户在协议下拉里自己选**。

### 3.3 `LlmService`：抽出三个适配器分支
把 `realStream()` 拆成调度 + 三个实现：

```
stream() 
  └─ resolveConfig()  // 同时取出 format
       └─ 按 format 分派：
            openai   → openAiStream()    // 现有逻辑原样保留
            anthropic→ anthropicStream() // 新：x-api-key + /v1/messages + max_tokens 必填 + delta.text
            gemini   → geminiStream()    // 新：?key= + contents 数组 + candidates[].content.parts[].text
```

`testConnection()` 同样按 `format` 分流（三家的"连通性探测"请求体略有差异，尤其是 Anthropic 必须带 `max_tokens`）。

**三个适配器的关键差异（实现时照此填）：**

| 差异点 | OpenAI | Anthropic | Gemini |
|---|---|---|---|
| 完整 URL | `{base}/chat/completions` | `{base}/v1/messages` | `{base}/models/{model}:streamGenerateContent?alt=sse&key={key}` |
| 鉴权 | `Authorization: Bearer` | `x-api-key` + `anthropic-version: 2023-06-01` | key 在 URL |
| 系统提示位置 | `messages` 里 role=system | **顶层 `system` 字段** | 顶层 `systemInstruction` |
| 角色名 | user / assistant / system | user / assistant（无 system 在数组内） | user / **model**（非 assistant） |
| 必填参数 | — | **`max_tokens` 必填** | — |
| 响应解析 | `choices[0].delta.content` | `delta.text` | `candidates[0].content.parts[0].text` |

### 3.4 前端 `ai-settings.blade.php`：加协议选择
- 选预设（含新增的 Claude / Gemini）时，`applyPreset()` 一并带出 `format`，用户无感。
- 「自定义」下新增**「API 协议」下拉**：OpenAI 兼容 / Anthropic / Gemini。
- 根据所选协议，**动态显示鉴权提示**：
  - Anthropic：提示"使用 x-api-key 鉴权，无需 Bearer"
  - Gemini：提示"API Key 会拼到请求 URL，Base URL 填 `https://generativelanguage.googleapis.com/v1beta`"

### 3.5 验证
- 沿用现有 `smoke_test.php`（in-process 渲染 /settings/ai 检查 200）。
- 真实 HTTP：起 `artisan serve`，用探针脚本确认三种 `format` 的 `testConnection()` 分支可达（无 key 时回落离线演示，不崩）。

> 这一步正好顺手把"应用内 AI 设置"从"只能接 OpenAI 系"升级成"全厂商开放"，符合你"除了 OpenAI 以外也可以"的核心诉求。

---

## 4. GitHub 同类项目调研（事实清单）

| 项目 | 类型 | 值得借鉴的功能 |
|---|---|---|
| **Koodo Reader** / **koodo-reader-AIpowered** | 跨平台电子书管理+阅读器 | 划线与笔记、TTS 听书、AI 对话、翻译、PDF/OCR、AI 总结、知识库、双向链接 |
| **Foliate** | Linux EPUB 阅读器 | TTS、翻译、标注（轻量标杆） |
| **Readest** | 开源 EPUB/PDF 阅读器 | 高亮标注、笔记、分屏阅读、TTS、云同步 |
| **ai-reader-pro** (tangchunwu) | AI 电子书阅读器 | 智能总结、**思维导图生成** |
| **ebook-to-mindmap** | AI 阅读工具 | PDF/EPUB **自动转分章节思维导图/文字总结**，BYOK + 提示词自定义 |
| **chapterAI** | EPUB 分析 | **分章节 AI 总结** |
| **Obsidian Copilot / Knowledge AI / Vault AI Chat** | Obsidian AI 插件 | **RAG 带引用**、多格式索引（MD/PDF/DOCX…）、图片 OCR/Vision、BM25+向量混合检索、**跨文档问答**、AI 生成内容/文件 |
| **Socratic_Tutor / SocraticAI / Socranotes** | AI 学习伙伴 | **苏格拉底式提问**、概念提取、自动生成闪卡/测验、间隔重复 |
| **NotebookLM Audio Overview** | Google AI | 把文档变成**双人对话播客式音频概览**（听书新形态） |
| **Aka-xiaosheng/ai-reading-companion** | 读书管理 | 书单跟踪、笔记捕捉、发现下一本书 |

---

## 5. 从第一性原理推导的能力链 & 我们缺什么

### 5.1 核心人类需求（第一性原理起点）
> 当人读书遇到"读不懂 / 有感触 / 想深挖"的地方，希望有个**懂这本书、懂我、还能延展思考**的伙伴在旁边。

把这个需求拆成一条**价值链条**（每一层都依赖上一层先把数据准备好）：

| 层 | 本质 | 我们现状 | 缺口（GitHub 已验证需求） |
|---|---|---|---|
| **1. 接入层** | 把书变成可检索/可引用的最小单元（章节、段落、概念） | ✅ EPUB/PDF 导入、划线持久化 | 划线只是存文本，未做结构化索引 |
| **2. 理解层** | 对选中内容解读/翻译/答疑 | ✅ 旁边问 AI（但仅 OpenAI 协议） | 协议开放度（即 P11） |
| **3. 记忆层** | 划线/笔记/问答沉淀成可复用知识 | △ 划线、导出 Markdown/Obsidian | **跨书/跨笔记 RAG 问答**、自定义提示词 |
| **4. 复用层** | 知识→卡片/导图/总结/复习 | △ 闪卡、金句卡、阅读时长 | **章节总结、全书思维导图** |
| **5. 延展层** | 听觉化/对照/生成 | △ TTS 朗读 | **双语对照、词汇本、音频概览** |

### 5.2 关键洞察
- 我们 P2–P10 已经把**第 1、2 层**和复用层的"卡片/金句"做扎实了。
- GitHub 上被反复验证、而我们**还没做**的高价值功能，集中在**第 3、4、5 层**：结构化沉淀与复用（这是"伴读"和"普通 Chat 工具"的分水岭）。
- 越靠后的层越依赖前面的结构化数据——所以路线图必须**自下而上**推进。

---

## 6. 功能路线图（最小 MVP 分阶段，手机优先）

> 原则：每期一个清晰价值、可独立验收；先做"数据基建"，再做"炫酷产出"。

### P11 · 自定义 Provider 三协议（你明确要的）
- **价值**：接入层开放度，解锁 Claude / Gemini / 任意厂商。
- **依赖**：无。
- **工作量**：小。

### P12 · 复用层：章节级 AI 总结 + 全书思维导图
- **价值**：把线性阅读变成结构化知识；一键生成"这本书讲了什么"的导图/大纲。对应 ai-reader-pro、ebook-to-mindmap、chapterAI 的共性需求。
- **依赖**：P11（需真实模型）、EPUB 章节解析（阅读器已有）。
- **工作量**：中。

### P13 · 记忆层：跨书/跨笔记 RAG 问答 + 自定义 System Prompt
- **价值**：让 AI 不只答"当前选中这句"，还能答"我读过的所有书里，关于 X 的观点有哪些"（知识复利）。对应 Obsidian 插件核心。自定义提示词让用户调"语气/角色"。
- **依赖**：P12 的结构化索引（先有可检索的笔记库）。
- **工作量**：中–大（需轻量向量检索，SQLite + 本地嵌入或调用模型）。

### P14 · 延展层：双语对照翻译 + 词汇本
- **价值**：整段/整章机翻对照（学外语/读外文书）；自动收集生词并间隔复习。手机上读外文书刚需。
- **依赖**：P11。
- **工作量**：中。

### P15 · 苏格拉底模式：对话式追问 + 自动测验
- **价值**：从"被动解读"升级为"主动思考"——AI 不直接给答案，而是连问几个问题引导你悟；并据划线自动出选择题测验。对应 Socratic 系列。
- **依赖**：P13 的记忆层。
- **工作量**：中。

### P16 · 音频概览：把本书总结/笔记生成双人播客式音频
- **价值**：通勤/家务时"听"完一本书的精华与自己的笔记。对应 NotebookLM Audio Overview。
- **依赖**：P12 的总结 + TTS/语音合成（可接模型 TTS 或本地）。
- **工作量**：中–大。

---

## 7. 下一步建议（待你拍板）

1. **先落地 P11**：这是你这次明确要的"自定义支持非 OpenAI"，且是后面所有功能的数据/接入基础，建议作为下一期的第一步直接实现。
2. **功能优先级**：按"复用层(P12) → 记忆层(P13) → 延展层(P14/P16) → 苏格拉底(P15)"推进，因为越往后越依赖前面的结构化数据。
3. **请你定两件事**：
   - 是否现在就实现 **P11（自定义三协议）**？
   - 功能路线图里，**下一项优先做哪一个**（P12 章节总结+导图 / P13 跨书 RAG / P14 双语+词汇本 / P16 音频概览）？

> 备注：所有新增功能都会沿用现有架构约定（每表带 user_id、白屏硬化、整页导航、移动端安全区），不会破坏已验收的 13/13 链路。
