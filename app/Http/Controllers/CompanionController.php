<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\Chat;
use App\Models\CompanionMessage;
use App\Models\Conversation;
use App\Models\KnowledgeGraph;
use App\Models\Persona;
use App\Models\RagChunk;
use App\Services\LlmService;
use App\Services\RagService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompanionController extends Controller
{
    /**
     * Streaming "ask the AI" endpoint (SSE).
     * The API key never leaves the server; the browser only receives tokens.
     *
     * 阶段2 扩展：
     *  - persona_id：选用哪套伴读人格（自定义系统提示词）。
     *  - scope：book（仅本书上下文）/ vault（Obsidian+vault）/ all（跨书跨笔记检索）。
     *    当 scope≠book 时，自动用 RagService 做跨源检索并拼进上下文。
     *  - 伴读对话统一落库到 companion_messages（与书内 chats 解耦）。
     */
    public function ask(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'message' => 'required|string|max:4000',
            'context' => 'nullable|string|max:4000',
            'book_id' => 'nullable|integer',
            'conversation_id' => 'nullable|integer',
            'mode' => 'nullable|string|in:devil,socratic',
            'persona_id' => 'nullable|integer',
            'scope' => 'nullable|string|in:book,vault,all',
        ]);

        $user = $request->user();
        $bookId = $data['book_id'] ?? null;
        $book = null;
        $scope = $data['scope'] ?? 'book';
        $personaId = $data['persona_id'] ?? null;
        $isCompanion = $personaId !== null || $scope !== 'book';

        if ($bookId) {
            $book = Book::find($bookId);
            abort_unless($book && $book->user_id === $user->id, 403);
        }

        // 解析人格的系统提示词（仅本人可用）
        $persona = null;
        $systemOverride = '';
        if ($personaId) {
            $persona = Persona::where('id', $personaId)->where('user_id', $user->id)->first();
            if ($persona) {
                $systemOverride = $persona->system_prompt;
            }
        }

        // 跨书 / 跨笔记检索（scope≠book 时生效）
        $ragContext = '';
        if ($scope !== 'book') {
            try {
                $rag = new RagService(new LlmService());
                $hits = $rag->search($data['message'], $user->id, 8);
                if (! empty($hits)) {
                    $parts = [];
                    foreach ($hits as $h) {
                        $parts[] = '【' . $rag->citation($h['chunk']) . "】\n" . $h['chunk']->content;
                    }
                    $ragContext = "以下是跨书 / 跨笔记检索到的相关片段，请优先结合它们来回答：\n\n"
                        . implode("\n\n---\n\n", $parts);
                }
            } catch (\Throwable $e) {
                logger()->warning('Companion RAG retrieval failed: ' . $e->getMessage());
            }
        }

        // 书内对话：仅非伴读（本书）场景才走 chats + Conversation。
        $convId = null;
        if (! $isCompanion && $bookId) {
            if (! empty($data['conversation_id'])) {
                $conversation = Conversation::where('id', $data['conversation_id'])
                    ->where('user_id', $user->id)
                    ->where('book_id', $bookId)
                    ->first();
            }
            if (empty($conversation)) {
                $conversation = Conversation::firstOrCreate(
                    ['user_id' => $user->id, 'book_id' => $bookId, 'title' => '默认对话']
                );
            }
            $convId = $conversation->id;

            Chat::where('user_id', $user->id)
                ->where('book_id', $bookId)
                ->whereNull('conversation_id')
                ->update(['conversation_id' => $convId]);
        }

        $context = $data['context'] ?? '';
        $message = $data['message'];
        $mode = $data['mode'] ?? '';

        // 最终给模型的上下文：用户给的引用 + 跨源检索片段
        $contextForLlm = $context;
        if ($ragContext !== '') {
            $contextForLlm = trim(($context ? $context . "\n\n" : '') . $ragContext);
        }

        $service = new LlmService();
        $full = '';

        return response()->stream(function () use ($service, $message, $contextForLlm, $mode, $systemOverride, $user, $bookId, $convId, $isCompanion, $personaId, $scope, &$full) {
            foreach ($service->stream($message, $contextForLlm, $mode, $systemOverride) as $token) {
                $full .= $token;
                echo 'data: ' . json_encode($token, JSON_UNESCAPED_UNICODE) . "\n\n";
                ob_flush();
                flush();
            }

            // 落库：伴读对话 → companion_messages；书内对话 → chats。
            try {
                if ($isCompanion) {
                    CompanionMessage::create([
                        'user_id' => $user->id,
                        'persona_id' => $personaId,
                        'scope' => $scope,
                        'book_id' => ($scope === 'book' && $bookId) ? $bookId : null,
                        'role' => 'user',
                        'content' => $message,
                        'context' => $contextForLlm,
                    ]);
                    CompanionMessage::create([
                        'user_id' => $user->id,
                        'persona_id' => $personaId,
                        'scope' => $scope,
                        'book_id' => ($scope === 'book' && $bookId) ? $bookId : null,
                        'role' => 'assistant',
                        'content' => $full,
                        'context' => $contextForLlm,
                    ]);
                } elseif ($user && $bookId) {
                    Chat::create([
                        'user_id' => $user->id,
                        'book_id' => $bookId,
                        'conversation_id' => $convId,
                        'role' => 'user',
                        'content' => $message,
                        'context' => $context,
                        'mode' => $mode ?: null,
                    ]);
                    Chat::create([
                        'user_id' => $user->id,
                        'book_id' => $bookId,
                        'conversation_id' => $convId,
                        'role' => 'assistant',
                        'content' => $full,
                        'context' => $context,
                        'mode' => $mode ?: null,
                    ]);
                }
            } catch (\Throwable $e) {
                logger()->warning('Chat persistence failed: ' . $e->getMessage());
            }

            echo "data: \"[DONE]\"\n\n";
            ob_flush();
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * N5 术语悬停：选中一个词/短语，返回「结合语境的通俗解释」。
     */
    public function define(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'term' => 'required|string|max:200',
            'context' => 'nullable|string|max:4000',
            'book_id' => 'nullable|integer',
        ]);

        $term = $data['term'];
        $context = $data['context'] ?? '';

        $svc = new LlmService();

        if ($svc->isMockConfig()) {
            $definition = $this->mockDefine($term, $context);
        } else {
            $prompt = "你是一个有温度的伴读助手。下面读者选中了一个术语/短语，并给出了它出现的语境。"
                . "请用通俗、口语化、有温度的中文解释这个术语在该语境里的含义；先用大白话讲清楚，"
                . "再举一两个生活化例子帮助理解；不要堆砌术语；控制在 120 字以内。\n\n术语：{$term}";
            $definition = $svc->complete($prompt, $context);
        }

        return response()->json([
            'ok' => true,
            'term' => $term,
            'definition' => $definition,
        ]);
    }

    /**
     * Return a book's past AI conversation (user + assistant turns, in order),
     * so re-opening the book restores the chat panel history.
     */
    public function history(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        $bookId = $request->query('book_id');
        $conversationId = $request->query('conversation_id');

        if ($bookId) {
            $book = Book::find($bookId);
            abort_unless($book && $book->user_id === $user->id, 403);
        }

        $chats = Chat::where('user_id', $user->id)
            ->when($bookId, fn ($q) => $q->where('book_id', $bookId))
            ->when($conversationId, fn ($q) => $q->where('conversation_id', $conversationId))
            ->orderBy('id')
            ->get(['role', 'content', 'context', 'mode']);

        return response()->json(['ok' => true, 'messages' => $chats]);
    }

    // ===== 多对话：同一本书下创建 / 切换 / 重命名 / 删除 =====

    public function listConversations(Book $book): \Illuminate\Http\JsonResponse
    {
        abort_unless($book->user_id === auth()->id(), 403);
        $conversations = Conversation::where('user_id', auth()->id())
            ->where('book_id', $book->id)
            ->orderBy('created_at')
            ->get(['id', 'title', 'created_at']);
        foreach ($conversations as $c) {
            $last = Chat::where('conversation_id', $c->id)
                ->where('role', 'user')
                ->orderByDesc('id')
                ->value('content');
            $c->preview = $last ? mb_substr($last, 0, 40) : '';
        }

        return response()->json(['ok' => true, 'conversations' => $conversations]);
    }

    public function createConversation(Request $request, Book $book): \Illuminate\Http\JsonResponse
    {
        abort_unless($book->user_id === auth()->id(), 403);
        $data = $request->validate(['title' => 'required|string|max:60']);
        $conv = Conversation::create([
            'user_id' => auth()->id(),
            'book_id' => $book->id,
            'title' => $data['title'],
        ]);

        return response()->json(['ok' => true, 'conversation' => $conv]);
    }

    public function renameConversation(Request $request, Conversation $conversation): \Illuminate\Http\JsonResponse
    {
        abort_unless($conversation->user_id === auth()->id(), 403);
        $data = $request->validate(['title' => 'required|string|max:60']);
        $conversation->update(['title' => $data['title']]);

        return response()->json(['ok' => true]);
    }

    public function deleteConversation(Conversation $conversation): \Illuminate\Http\JsonResponse
    {
        abort_unless($conversation->user_id === auth()->id(), 403);
        Chat::where('conversation_id', $conversation->id)->delete();
        $conversation->delete();

        return response()->json(['ok' => true]);
    }

    // ===== 阶段2：伴读人格管理 =====

    public function personasIndex(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        Persona::ensureDefaults($user->id);

        $personas = Persona::where('user_id', $user->id)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get(['id', 'name', 'emoji', 'description', 'system_prompt', 'is_default']);

        return response()->json(['ok' => true, 'personas' => $personas]);
    }

    public function personasStore(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:40',
            'emoji' => 'nullable|string|max:8',
            'description' => 'nullable|string|max:200',
            'system_prompt' => 'required|string|max:4000',
        ]);

        $persona = Persona::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'emoji' => $data['emoji'] ?? '🤖',
            'description' => $data['description'] ?? null,
            'system_prompt' => $data['system_prompt'],
            'is_default' => false,
        ]);

        return response()->json(['ok' => true, 'persona' => $persona]);
    }

    public function personasUpdate(Request $request, Persona $persona): \Illuminate\Http\JsonResponse
    {
        abort_unless($persona->user_id === auth()->id(), 403);
        $data = $request->validate([
            'name' => 'required|string|max:40',
            'emoji' => 'nullable|string|max:8',
            'description' => 'nullable|string|max:200',
            'system_prompt' => 'required|string|max:4000',
        ]);

        $persona->update([
            'name' => $data['name'],
            'emoji' => $data['emoji'] ?? '🤖',
            'description' => $data['description'] ?? null,
            'system_prompt' => $data['system_prompt'],
        ]);

        return response()->json(['ok' => true, 'persona' => $persona]);
    }

    public function personasDestroy(Request $request, Persona $persona): \Illuminate\Http\JsonResponse
    {
        abort_unless($persona->user_id === auth()->id(), 403);
        // 避免孤儿引用：把引用它的伴读消息的人格置空
        CompanionMessage::where('persona_id', $persona->id)->update(['persona_id' => null]);
        $persona->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * 把伴读对话里的一条优质回答一键加入知识库：
     * 写入 rag_chunks（source_type=companion），并失效知识库图谱缓存（下次进入自动重建）。
     */
    public function addToKb(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'content' => 'required|string|max:8000',
            'title' => 'nullable|string|max:200',
            'book_id' => 'nullable|integer',
            'scope' => 'nullable|string|in:all,vault,book',
        ]);

        $user = $request->user();
        // 注意：$data 里 nullable 字段若未随请求传入，键本身不存在（validate 不会补默认），
        // 必须用 empty() 安全判断，避免 "Undefined array key"。
        $title = ! empty($data['title'])
            ? $data['title']
            : mb_substr(preg_replace('/\s+/', ' ', strip_tags($data['content'])), 0, 40);

        $chunk = RagChunk::create([
            'user_id' => $user->id,
            'source_type' => 'companion',
            'source_path' => 'companion',
            'book_id' => $data['book_id'] ?? null,
            'title' => $title,
            'content' => $data['content'],
            'chunk_index' => 0,
            'links' => null,
            'meta' => ['scope' => $data['scope'] ?? 'all', 'added_from' => 'companion'],
        ]);

        // 失效知识库图谱缓存，并尽量同步按 rag_chunks 重建（聚合无需 LLM，个人量级很快），
        // 让「🕸 知识库」在下次进入时立即体现这条新内容；失败则降级为「需点生成」。
        KnowledgeGraph::where('user_id', $user->id)->delete();
        try {
            $rag = new RagService(new LlmService());
            $graph = $rag->buildKnowledgeGraph($user->id);
            KnowledgeGraph::create([
                'user_id' => $user->id,
                'graph_json' => $graph,
                'status' => 'done',
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            logger()->warning('Knowledge graph rebuild after add-to-kb failed: ' . $e->getMessage());
        }

        return response()->json(['ok' => true, 'chunk_id' => $chunk->id]);
    }

    /**
     * 拉取伴读（跨书）对话历史，供独立「💬伴读」页面回显。
     */
    public function companionMessages(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        $msgs = CompanionMessage::where('user_id', $user->id)
            ->when($request->query('persona_id'), fn ($q, $p) => $q->where('persona_id', $p))
            ->orderBy('id')
            ->get(['role', 'content', 'context', 'scope', 'persona_id']);

        return response()->json(['ok' => true, 'messages' => $msgs]);
    }

    /**
     * 离线演示用的术语解释（无密钥时给出可阅读的占位说明）。
     */
    protected function mockDefine(string $term, string $context): string
    {
        $ctxNote = $context ? '（结合你选中的语境）' : '';

        return "「{$term}」{$ctxNote}大概是这个意思：\n\n"
            . "它在书中出现的那段文字里，作者用这个词来表达一种特定的概念或状态。"
            . "用大白话说，就像我们平时说的某句约定俗成的讲法，是一种特定的表达。\n\n"
            . "（这是离线演示解释；在「AI 设置」填入密钥后，会由真实模型结合上下文给出精准、有温度的解释。）";
    }
}
