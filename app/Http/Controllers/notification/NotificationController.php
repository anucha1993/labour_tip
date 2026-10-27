<?php

namespace App\Http\Controllers\notification;

use App\Http\Controllers\Controller;
use App\Services\ExpiryService;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function __construct(private ExpiryService $expiry)
    {
        $this->middleware('auth');
    }

    public function index()
    {
        // Each tab gets its own page parameter so paging one list does not reset the others.
        $lists = [];
        foreach (ExpiryService::TYPES as $type => $column) {
            $lists[$type] = $this->expiry->query($type)->paginate(10, ['*'], $type . '_page')->withQueryString();
        }

        $stats = DB::table('labour')
            ->selectRaw("COUNT(*) AS total,
                SUM(CASE WHEN labour_status_job = 'job' THEN 1 ELSE 0 END) AS job,
                SUM(CASE WHEN labour_status_job = 'resign' THEN 1 ELSE 0 END) AS resign,
                SUM(CASE WHEN labour_status_job = 'escape' THEN 1 ELSE 0 END) AS escape")
            ->first();

        return view('notification.index', [
            'lists' => $lists,
            'labels' => ExpiryService::LABELS,
            'columns' => ExpiryService::TYPES,
            'thresholds' => $this->expiry->thresholds(),
            'counts' => $this->expiry->counts(),
            'stats' => [
                'total' => (int) ($stats->total ?? 0),
                'job' => (int) ($stats->job ?? 0),
                'resign' => (int) ($stats->resign ?? 0),
                'escape' => (int) ($stats->escape ?? 0),
                'company' => DB::table('company')->count(),
                'agency' => DB::table('agency')->count(),
            ],
            'exportRoutes' => [
                'visa' => route('ExportExpire.Visa'),
                'passport' => route('ExportExpire.passport'),
                'work' => route('ExportExpire.workpremit'),
                'ninety' => route('ExportExpire.day90'),
            ],
        ]);
    }

    /** Total number of expiring documents (JSON for any client that polls it). */
    public function notify()
    {
        return response()->json($this->expiry->counts());
    }
}
