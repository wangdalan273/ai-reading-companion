<?php

namespace Tests\Feature;

use App\Models\Annotation;
use App\Models\Book;
use App\Models\CompanionMessage;
use App\Models\RagChunk;
use App\Models\ReadingState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileReadingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_import_accepts_an_epub_reported_as_zip(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->post('/api/v1/books', [
            'title' => '移动端测试书',
            'file' => UploadedFile::fake()->create('reader.epub', 12, 'application/zip'),
        ])->assertCreated()
            ->assertJsonPath('title', '移动端测试书')
            ->assertJsonPath('format', 'epub');

        $book = Book::where('user_id', $user->id)->firstOrFail();
        Storage::disk('local')->assertExists($book->path);
    }

    public function test_saved_content_contains_only_the_current_users_highlights_and_chat_saves(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $book = $this->bookFor($user, '我的书');
        $otherBook = $this->bookFor($other, '别人的书');
        Annotation::create(['user_id' => $user->id, 'book_id' => $book->id, 'loc' => 'cfi-1', 'quote' => '我的划线']);
        Annotation::create(['user_id' => $other->id, 'book_id' => $otherBook->id, 'loc' => 'cfi-2', 'quote' => '别人的划线']);
        RagChunk::create(['user_id' => $user->id, 'source_type' => 'companion', 'title' => '我的收藏', 'content' => 'AI 回答', 'chunk_index' => 0]);
        RagChunk::create(['user_id' => $other->id, 'source_type' => 'companion', 'title' => '别人的收藏', 'content' => '不可见', 'chunk_index' => 0]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/saved-content')
            ->assertOk()
            ->assertJsonCount(1, 'annotations')
            ->assertJsonCount(1, 'conversation_saves')
            ->assertJsonPath('annotations.0.quote', '我的划线')
            ->assertJsonPath('conversation_saves.0.title', '我的收藏')
            ->assertJsonMissing(['quote' => '别人的划线'])
            ->assertJsonMissing(['title' => '别人的收藏']);
    }

    public function test_companion_history_is_partitioned_into_independent_threads(): void
    {
        $user = User::factory()->create();
        foreach ([['thread-a', '问题 A'], ['thread-b', '问题 B']] as [$threadId, $question]) {
            CompanionMessage::create(['user_id' => $user->id, 'thread_id' => $threadId, 'scope' => 'all', 'role' => 'user', 'content' => $question]);
            CompanionMessage::create(['user_id' => $user->id, 'thread_id' => $threadId, 'scope' => 'all', 'role' => 'assistant', 'content' => "回答 {$question}"]);
        }
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/companion/messages?thread_id=thread-a')
            ->assertOk()
            ->assertJsonCount(2, 'threads')
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('active_thread_id', 'thread-a')
            ->assertJsonPath('messages.0.content', '问题 A')
            ->assertJsonMissing(['content' => '问题 B']);
    }

    public function test_mobile_can_delete_one_companion_thread_without_touching_other_threads(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        foreach ([[$user, 'thread-a'], [$user, 'thread-b'], [$other, 'thread-a']] as [$owner, $thread]) {
            CompanionMessage::create(['user_id' => $owner->id, 'thread_id' => $thread, 'scope' => 'all', 'role' => 'user', 'content' => $thread]);
        }
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/companion/threads/thread-a')->assertOk()->assertJsonPath('deleted', 1);
        $this->assertDatabaseMissing('companion_messages', ['user_id' => $user->id, 'thread_id' => 'thread-a']);
        $this->assertDatabaseHas('companion_messages', ['user_id' => $user->id, 'thread_id' => 'thread-b']);
        $this->assertDatabaseHas('companion_messages', ['user_id' => $other->id, 'thread_id' => 'thread-a']);
    }

    public function test_mobile_can_edit_a_highlight_note_but_not_another_users_highlight(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $book = $this->bookFor($user, '可编辑的书');
        $annotation = Annotation::create(['user_id' => $user->id, 'book_id' => $book->id, 'loc' => 'cfi-1', 'quote' => '原文']);
        Sanctum::actingAs($user);

        $this->putJson("/api/v1/books/{$book->id}/annotations/{$annotation->id}", ['note' => '新的理解', 'tag' => 'ai'])
            ->assertOk();
        $this->assertDatabaseHas('annotations', ['id' => $annotation->id, 'note' => '新的理解', 'tag' => 'ai']);

        Sanctum::actingAs($other);
        $this->putJson("/api/v1/books/{$book->id}/annotations/{$annotation->id}", ['note' => '越权修改'])
            ->assertForbidden();
    }

    public function test_mobile_can_edit_user_notes_but_keeps_imported_book_content_read_only(): void
    {
        $user = User::factory()->create();
        RagChunk::create(['user_id' => $user->id, 'source_type' => 'companion', 'source_path' => 'companion', 'title' => '旧标题', 'content' => '旧内容', 'chunk_index' => 0]);
        RagChunk::create(['user_id' => $user->id, 'source_type' => 'book', 'book_id' => $this->bookFor($user, '知识书')->id, 'title' => '书籍原文', 'content' => '不可改', 'chunk_index' => 0]);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/knowledge/notes?type=companion&source_path=companion&title='.urlencode('旧标题'), [
            'title' => '新标题',
            'content' => '新内容',
        ])->assertOk();
        $this->assertDatabaseHas('rag_chunks', ['user_id' => $user->id, 'title' => '新标题', 'content' => '新内容']);

        $this->putJson('/api/v1/knowledge/notes?type=book&title='.urlencode('书籍原文'), [
            'title' => '篡改',
            'content' => '篡改',
        ])->assertUnprocessable();
    }

    public function test_reading_position_and_bookmarks_sync_without_stale_device_overwrite(): void
    {
        $user = User::factory()->create();
        $book = $this->bookFor($user, '同步阅读位置');
        Sanctum::actingAs($user);

        $this->putJson("/api/v1/books/{$book->id}/reading-state", [
            'format' => 'epub', 'locator' => 'epubcfi(/6/10)', 'progress' => 0.42,
            'bookmarks' => [['id' => 'b1', 'locator' => 'epubcfi(/6/8)', 'label' => '第二章', 'createdAt' => '2026-07-20T08:00:00Z']],
            'client_updated_at' => '2026-07-20T09:00:00Z',
        ])->assertOk()->assertJsonPath('stale', false);

        $this->putJson("/api/v1/books/{$book->id}/reading-state", [
            'format' => 'epub', 'locator' => 'epubcfi(/6/2)', 'progress' => 0.1,
            'bookmarks' => [], 'client_updated_at' => '2026-07-20T08:30:00Z',
        ])->assertOk()->assertJsonPath('stale', true)->assertJsonPath('state.locator', 'epubcfi(/6/10)');

        $this->getJson("/api/v1/books/{$book->id}/reading-state")
            ->assertOk()->assertJsonPath('state.progress', 0.42)->assertJsonCount(1, 'state.bookmarks');
        $this->assertDatabaseCount('reading_states', 1);
    }

    private function bookFor(User $user, string $title): Book
    {
        return Book::create([
            'user_id' => $user->id,
            'title' => $title,
            'format' => 'epub',
            'path' => "books/{$title}.epub",
            'size' => 100,
        ]);
    }
}
