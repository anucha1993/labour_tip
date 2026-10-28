<?php

namespace App\Http\Controllers\ai;

use App\Http\Controllers\Controller;
use App\Models\AiChatLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * MasterAdmin: browse what people ask the AI assistant and what it answered.
 */
class AiChatLogController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->input('search'));
        $userId = (int) $request->input('user_id');
        $status = in_array($request->input('status'), ['ok', 'error'], true) ? $request->input('status') : null;
        $from = $request->input('from');
        $to = $request->input('to');
        $conversation = trim((string) $request->input('conversation'));
        $tool = trim((string) $request->input('tool'));

        $query = AiChatLog::query()
            ->with('user')
            ->when($search, function ($q, $search) {
                $like = '%' . $search . '%';
                $q->where(function ($w) use ($like) {
                    $w->where('question', 'LIKE', $like)
                        ->orWhere('answer', 'LIKE', $like)
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'LIKE', $like));
                });
            })
            ->when($userId, fn ($q, $v) => $q->where('user_id', $v))
            ->when($status, fn ($q, $v) => $q->where('status', $v))
            ->when($tool, fn ($q, $v) => $q->whereRaw('tools_used LIKE ? ESCAPE ?', ['%"' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v) . '"%', '\\']))
            ->when($conversation, fn ($q, $v) => $q->where('conversation_id', $v))
            ->when($from, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($to, fn ($q, $v) => $q->whereDate('created_at', '<=', $v));

        // A conversation reads top-down; everything else newest first.
        $logs = (clone $query)
            ->orderBy('id', $conversation ? 'asc' : 'desc')
            ->paginate(25)
            ->withQueryString();

        $summary = (clone $query)
            ->selectRaw('COUNT(*) AS total, SUM(status = "error") AS errors, SUM(prompt_tokens + completion_tokens) AS tokens, COUNT(DISTINCT user_id) AS users')
            ->first();

        $topUsers = AiChatLog::query()
            ->select('user_id', DB::raw('COUNT(*) AS total'), DB::raw('MAX(created_at) AS last_at'))
            ->whereDate('created_at', '>=', now()->subDays(30)->toDateString())
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->limit(5)
            ->with('user')
            ->get();

        return view('ai.logs', [
            'logs' => $logs,
            'summary' => $summary,
            'topUsers' => $topUsers,
            'users' => User::orderBy('name')->get(['id', 'name']),
            'tools' => ['count_labours', 'search_labours', 'get_labour_detail', 'list_companies', 'list_agencies', 'create_excel_report'],
            'filters' => compact('search', 'userId', 'status', 'from', 'to', 'conversation', 'tool'),
        ]);
    }
}
