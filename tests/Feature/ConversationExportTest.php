<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationExportTest extends TestCase
{
    use RefreshDatabase;

    private function seedBookWithChats(): Book
    {
        $user = User::factory()->create();
        $book = Book::create([
            'user_id' => $user->id,
            'title' => '测试书',
            'author' => '测试作者',
            'format' => 'epub',
            'path' => 'books/test.epub',
            'size' => 1234,
        ]);

        // 普通对话一轮
        Chat::create(['user_id' => $user->id, 'book_id' => $book->id, 'role' => 'user', 'content' => '什么是阴阳？']);
        Chat::create(['user_id' => $user->id, 'book_id' => $book->id, 'role' => 'assistant', 'content' => '阴阳是中医里一对互相对立又依存的概念。']);

        // 魔鬼代言人一轮
        Chat::create(['user_id' => $user->id, 'book_id' => $book->id, 'role' => 'user', 'content' => '我觉得这本书说得很对', 'mode' => 'devil']);
        Chat::create(['user_id' => $user->id, 'book_id' => $book->id, 'role' => 'assistant', 'content' => '且慢，作者的这个论点有几个漏洞值得推敲。', 'mode' => 'devil']);

        return $book;
    }

    public function test_conversation_export_includes_turns_and_devil_label(): void
    {
        $book = $this->seedBookWithChats();
        $user = $book->user;

        $this->actingAs($user)
            ->get(route('book.export.conversation', $book))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/markdown; charset=utf-8')
            ->assertSee('AI 对话记录')
            ->assertSee('阴阳是中医里一对互相对立又依存的概念。')
            ->assertSee('魔鬼代言人')
            ->assertSee('[!note]');   // Obsidian callout 格式
    }

    public function test_history_endpoint_returns_ordered_messages(): void
    {
        $book = $this->seedBookWithChats();
        $user = $book->user;

        $this->actingAs($user)
            ->get('/api/companion/history?book_id='.$book->id)
            ->assertOk()
            ->assertJsonStructure(['ok', 'messages' => [['role', 'content', 'context', 'mode']]])
            ->assertJsonFragment(['role' => 'user', 'content' => '什么是阴阳？'])
            ->assertJsonFragment(['mode' => 'devil']);
    }

    public function test_history_is_scoped_to_owner(): void
    {
        $book = $this->seedBookWithChats();
        $other = User::factory()->create();

        $this->actingAs($other)
            ->get('/api/companion/history?book_id='.$book->id)
            ->assertForbidden();
    }
}
