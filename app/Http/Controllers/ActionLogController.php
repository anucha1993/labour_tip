<?php

namespace App\Http\Controllers;

use App\Models\ActionLog;
use App\Models\labour\LabourModel;
use App\Models\User;
use Illuminate\Http\Request;

class ActionLogController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->input('search'));
        $action = $request->input('action');
        $userId = (int) $request->input('user_id');
        $from = $request->input('from');
        $to = $request->input('to');

        if (! in_array($action, ['created', 'updated', 'deleted'], true)) {
            $action = null;
        }

        $logs = ActionLog::query()
            ->with('user')
            ->when($search, function ($q, $search) {
                $like = '%' . $search . '%';
                $q->where(function ($w) use ($like) {
                    $w->where('model_id', 'LIKE', $like)
                        ->orWhere('old_values', 'LIKE', $like)
                        ->orWhere('new_values', 'LIKE', $like)
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'LIKE', $like));
                });
            })
            ->when($action, fn ($q, $v) => $q->where('action_type', $v))
            ->when($userId, fn ($q, $v) => $q->where('user_id', $v))
            ->when($from, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($to, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        // Resolve the labour each log refers to in one query instead of one per row.
        $labourIds = $logs->getCollection()
            ->filter(fn ($log) => $log->model_type === LabourModel::class)
            ->pluck('model_id')
            ->unique()
            ->all();

        $labours = $labourIds
            ? LabourModel::whereIn('labour_id', $labourIds)->get(['labour_id', 'labour_prefix', 'labour_fullname', 'labour_passport_number'])->keyBy('labour_id')
            : collect();

        $logs->getCollection()->transform(function ($log) use ($labours) {
            $log->record = $labours->get($log->model_id);
            // For deleted records the name only survives inside old_values.
            if (! $log->record && is_array($log->old_values)) {
                $log->record = (object) [
                    'labour_prefix' => $log->old_values['labour_prefix'] ?? null,
                    'labour_fullname' => $log->old_values['labour_fullname'] ?? null,
                    'labour_passport_number' => $log->old_values['labour_passport_number'] ?? null,
                    'deleted' => true,
                ];
            }

            return $log;
        });

        return view('action_logs.index', [
            'logs' => $logs,
            'search' => $search,
            'action' => $action,
            'userId' => $userId,
            'from' => $from,
            'to' => $to,
            'users' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
