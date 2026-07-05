<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MetaSyncLog;
use Illuminate\Http\Request;

/**
 * Read-only, searchable/filterable view of every Meta sync attempt, plus a
 * "retry failed" action that re-queues everything currently in a failed state.
 */
class MetaSyncLogController extends Controller
{
    public function index(Request $request)
    {
        $logs = MetaSyncLog::query()
            ->search($request->query('q'))
            ->status($request->query('status'))
            ->action($request->query('action'))
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return view('admin.meta.logs', [
            'logs' => $logs,
            'actions' => ['create', 'update', 'delete', 'refresh', 'sync_all', 'test'],
            'statuses' => ['success', 'failed', 'skipped'],
            'failedCount' => MetaSyncLog::where('status', 'failed')->count(),
        ]);
    }

    /** Re-queue every product currently in a failed sync state. */
    public function retryFailed()
    {
        \App\Jobs\Meta\RetryFailedMetaSyncs::dispatch();

        return back()->with('success', 'Failed syncs have been re-queued.');
    }
}
