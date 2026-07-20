<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Annotation;
use App\Models\Book;
use App\Models\CompanionMessage;
use App\Models\Flashcard;
use App\Models\Persona;
use App\Models\ReadingLog;
use App\Models\ReadingState;
use Illuminate\Http\Request;
use Carbon\Carbon;

/**
 * 增量同步拉取（服务端权威）。
 * 以 updated_at 为游标：?since=ISO时间戳 仅返回该时间之后变化的记录，
 * 含软删（deleted_at 非 null）作为墓碑下发，App 本地同样软删。
 */
class SyncController extends Controller
{
    public function pull(Request $request)
    {
        // 只同步「当前登录用户」的数据：多租户隔离是同步契约的底线，
        // 否则 /v1/sync 会泄露其它用户的书籍/划线/笔记（严重安全漏洞）。
        $userId = auth()->id();
        $since = $request->query('since');
        $sinceCarbon = $since ? Carbon::parse($since) : null;

        // 说明：当前 6 张同步表模型尚未启用 SoftDeletes trait（为避免影响电脑端既有查询行为），
        // 因此 ->get() 不会自动过滤 deleted_at，软删记录（若有）会随增量自然下发；
        // 若后续为模型启用 SoftDeletes，请在此处链 ->withTrashed() 以保证墓碑下发。
        $scope = fn ($q) => $sinceCarbon
            ? $q->where('updated_at', '>', $sinceCarbon)
            : $q;

        return response()->json([
            'server_time' => now()->toISOString(),
            'books' => $scope(Book::where('user_id', $userId))->get(),
            'annotations' => $scope(Annotation::where('user_id', $userId))->get(),
            'flashcards' => $scope(Flashcard::where('user_id', $userId))->get(),
            'reading_logs' => $scope(ReadingLog::where('user_id', $userId))->get(),
            'reading_states' => $scope(ReadingState::where('user_id', $userId))->get(),
            'companion_messages' => $scope(CompanionMessage::where('user_id', $userId))->get(),
            'personas' => $scope(Persona::where('user_id', $userId))->get(),
        ]);
    }
}
