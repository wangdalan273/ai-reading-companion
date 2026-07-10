<?php

namespace App\Services;

use App\Models\Annotation;
use App\Models\Book;
use App\Models\Chat;
use App\Models\Quiz;

/**
 * Turns a book's highlights + AI conversations into Obsidian-friendly Markdown,
 * and optionally pushes the file into a configured Obsidian vault folder.
 */
class ExportService
{
    /**
     * Build the Markdown document for a book.
     */
    public function toMarkdown(Book $book): string
    {
        $annotations = Annotation::where('book_id', $book->id)
            ->where('user_id', $book->user_id)
            ->orderBy('loc')
            ->get();

        $lines = [];
        $lines[] = '---';
        $lines[] = 'title: "《'.$this->yaml($book->title).'》"';
        $lines[] = 'author: '.$this->yaml($book->author ?? '未知作者');
        $lines[] = 'date: '.now()->toDateString();
        $lines[] = 'tags: [伴读, '.$this->tag($book->title).']';
        $lines[] = 'source: "[['.$this->yaml($book->title).']]"';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = '# 《'.$book->title.'》· 伴读笔记';
        $lines[] = '';
        $lines[] = '_'.($book->author ?? '未知作者').'_';
        $lines[] = '';

        if ($annotations->isEmpty()) {
            $lines[] = '> 还没有划线。读到戳中你的地方，选中后问 AI，再回来导出吧。';
            $lines[] = '';
        } else {
            foreach ($annotations as $i => $a) {
                $lines[] = '## 划线 '.($i + 1);
                $lines[] = '';
                $lines[] = '> '.$a->quote;
                $lines[] = '';

                if ($a->note) {
                    $lines[] = '**批注：** '.$a->note;
                    $lines[] = '';
                }
                if ($a->tag) {
                    $lines[] = '`#'.$a->tag.'`';
                    $lines[] = '';
                }

                // Attach the latest AI explanation for this exact quote.
                $ai = Chat::where('book_id', $book->id)
                    ->where('user_id', $book->user_id)
                    ->where('role', 'assistant')
                    ->where('context', $a->quote)
                    ->orderByDesc('id')
                    ->value('content');

                if ($ai) {
                    $lines[] = '**AI 解读：** '.$ai;
                    $lines[] = '';
                }

                $lines[] = '---';
                $lines[] = '';
            }
        }

        // 附：本书 AI 自由对话记录（与划线无直接关联的提问 / 魔鬼代言人），
        // 避免和上面的「划线 + AI 解读」重复展示。
        $annotationQuotes = $annotations->pluck('quote')->map(fn ($q) => trim($q))->all();
        $turns = $this->conversationTurns($book);
        $extra = array_filter($turns, fn ($t) => empty($t['context']) || ! in_array(trim($t['context']), $annotationQuotes, true));

        if (! empty($extra)) {
            $lines[] = '## AI 对话记录';
            $lines[] = '';
            $lines[] = '_以下是不针对某条划线、或在「魔鬼代言人」模式下与 AI 的对话。_';
            $lines[] = '';
            foreach ($extra as $i => $t) {
                $modeLabel = $t['mode'] === 'socratic' ? ' 🧭苏格拉底' : ($t['mode'] === 'devil' ? ' 🎯魔鬼代言人' : '');
                $lines[] = '### 第 '.($i + 1).' 轮'.$modeLabel;
                $lines[] = '';
                if ($t['user'] !== '') {
                    $lines[] = '**我：** '.$t['user'];
                    $lines[] = '';
                }
                if ($t['assistant'] !== null) {
                    $lines[] = '**AI：** '.$t['assistant'];
                    $lines[] = '';
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Build a standalone Markdown doc of ONLY the book's AI conversation,
     * in an Obsidian-friendly callout format (reusing the established
     * "AI chat -> Obsidian" convention: frontmatter + > [!note] callouts).
     */
    public function toConversationMarkdown(Book $book): string
    {
        $turns = $this->conversationTurns($book);

        $lines = [];
        $lines[] = '---';
        $lines[] = 'title: "《'.$this->yaml($book->title).'》· AI 对话"';
        $lines[] = 'author: '.$this->yaml($book->author ?? '未知作者');
        $lines[] = 'date: '.now()->toDateString();
        $lines[] = 'tags: [伴读, AI对话, '.$this->tag($book->title).']';
        $lines[] = 'source: "[['.$this->yaml($book->title).']]"';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = '# 《'.$book->title.'》· AI 对话记录';
        $lines[] = '';

        if (empty($turns)) {
            $lines[] = '> 还没有和 AI 聊过。读到戳中你的地方，选中后问 AI，再回来导出吧。';
            $lines[] = '';

            return implode("\n", $lines);
        }

        $lines[] = '> 共 '.count($turns).' 轮对话，由 AI 伴读生成。';
        $lines[] = '';

        foreach ($turns as $i => $t) {
            $modeLabel = $t['mode'] === 'socratic' ? ' 🧭苏格拉底' : ($t['mode'] === 'devil' ? ' 🎯魔鬼代言人' : '');
            $lines[] = '## 第 '.($i + 1).' 轮'.$modeLabel;
            $lines[] = '';
            if ($t['user'] !== '') {
                $lines[] = '> [!question] 我';
                foreach (explode("\n", $t['user']) as $ul) {
                    $lines[] = '> '.$ul;
                }
                $lines[] = '';
            }
            if ($t['assistant'] !== null) {
                $lines[] = '> [!note] AI 伴读';
                foreach (explode("\n", $t['assistant']) as $al) {
                    $lines[] = '> '.$al;
                }
                $lines[] = '';
            }
            if ($t['context']) {
                $lines[] = '> _原文语境：'.$t['context'].'_';
                $lines[] = '';
            }
            $lines[] = '---';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Group a book's flat chat rows into user→assistant turns, robust to
     * interrupted streams (an assistant row without a preceding user opens a
     * new turn; a second assistant just attaches to the current turn).
     */
    protected function conversationTurns(Book $book): array
    {
        $chats = Chat::where('book_id', $book->id)
            ->where('user_id', $book->user_id)
            ->orderBy('id')
            ->get(['role', 'content', 'context', 'mode']);

        $turns = [];
        foreach ($chats as $c) {
            if ($c->role === 'user') {
                $turns[] = ['user' => $c->content, 'assistant' => null, 'context' => $c->context, 'mode' => $c->mode];
            } else {
                $idx = count($turns) - 1;
                if ($idx >= 0 && $turns[$idx]['assistant'] === null) {
                    $turns[$idx]['assistant'] = $c->content;
                    if (empty($turns[$idx]['mode'])) {
                        $turns[$idx]['mode'] = $c->mode;
                    }
                } else {
                    $turns[] = ['user' => '', 'assistant' => $c->content, 'context' => $c->context, 'mode' => $c->mode];
                }
            }
        }

        return $turns;
    }

    /**
     * Write the Markdown into the configured Obsidian vault folder.
     *
     * @return array{ok: bool, path?: string, msg?: string}
     */
    public function pushToObsidian(Book $book): array
    {
        $vault = $this->vaultPathFor($book->user_id);

        if (empty($vault)) {
            return ['ok' => false, 'msg' => '未配置 Obsidian vault 路径（请到「🧠 记忆」页填写 vault 文件夹路径）'];
        }
        if (! is_dir($vault) || ! is_writable($vault)) {
            return ['ok' => false, 'msg' => 'vault 目录不存在或不可写：'.$vault];
        }

        $filename = $this->safeFilename($book->title).'-伴读.md';
        $target = rtrim($vault, '/').'/'.$filename;

        $written = file_put_contents($target, $this->toMarkdown($book));
        if ($written === false) {
            return ['ok' => false, 'msg' => '写入失败：'.$target];
        }

        return ['ok' => true, 'path' => $target];
    }

    /**
     * Build a standalone Markdown doc of a saved quiz, in an Obsidian-friendly
     * format (frontmatter + > [!quiz] callouts + [[双链]] back to the book).
     */
    public function toQuizMarkdown(Quiz $quiz): string
    {
        $book = $quiz->book;
        $bookTitle = $book ? $book->title : '未关联书籍';
        $questions = $quiz->questions;

        $lines = [];
        $lines[] = '---';
        $lines[] = 'title: "《'.$this->yaml($bookTitle).'》· 测验 #'.$quiz->id.'"';
        $lines[] = 'date: '.now()->toDateString();
        $lines[] = 'tags: [伴读, 测验, '.$this->tag($bookTitle).']';
        $lines[] = 'source: "[['.$this->yaml($bookTitle).']]"';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = '# 《'.$bookTitle.'》· 自测题 #'.$quiz->id;
        $lines[] = '';
        $scope = match ($quiz->source_type) {
            'selection' => '选中文字',
            'chapter' => '章节：'.($quiz->chapter_title ?: '—'),
            default => '全书抽取',
        };
        $lines[] = '> [!quiz] 自测题';
        $lines[] = '> 共 '.count($questions).' 题 · 来源：'.$scope;
        $lines[] = '';

        foreach ($questions as $i => $q) {
            $lines[] = '## 第 '.($i + 1).' 题';
            $lines[] = '';
            $lines[] = '> [!question] '.$q->question;
            $lines[] = '';
            foreach ($q->options_json as $oi => $opt) {
                $marker = ((int) $oi === (int) $q->answer_index) ? '✅' : '';
                $lines[] = ('> '.chr(65 + $oi).'. '.$opt.' '.$marker);
            }
            $lines[] = '';
            $lines[] = '**答案：** '.chr(65 + (int) $q->answer_index);
            if ($q->explanation) {
                $lines[] = '';
                $lines[] = '**解析：** '.$q->explanation;
            }
            $lines[] = '';
            $lines[] = '---';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    // --- helpers ---

    /**
     * 解析 Obsidian vault 路径：优先用户「🧠 记忆」页配置的（AiConfig，DB），
     * 再回退到静态 env（COMPANION_OBSIDIAN_VAULT）。
     * 修复此前只读 env 导致「用户已配置却误报未配置」的问题。
     */
    public function vaultPathFor(int $userId): ?string
    {
        $vault = \App\Models\AiConfig::where('user_id', $userId)->value('vault_path');
        if (! empty($vault)) {
            return $vault;
        }

        $env = config('companion.obsidian_vault_path');

        return empty($env) ? null : $env;
    }

    protected function yaml(string $value): string
    {
        return str_replace('"', "'", $value);
    }

    protected function tag(string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}_-]/u', '-', $value) ?: 'book';
    }

    public function safeFilename(string $value): string
    {
        $name = preg_replace('/[\\/:*?"<>|]/', '_', $value);
        $name = trim($name);

        return $name === '' ? 'book' : $name;
    }
}
