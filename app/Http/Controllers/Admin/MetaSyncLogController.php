<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\Meta\RetryFailedMetaSyncs;
use App\Jobs\Meta\SyncProductToMeta;
use App\Models\MetaSyncLog;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Searchable / filterable view of every Meta sync attempt, with retry (all
 * failed, or a selected set) and CSV export.
 */
class MetaSyncLogController extends Controller
{
    /** Build the filtered query shared by index + export. */
    private function filtered(Request $request)
    {
        return MetaSyncLog::query()
            ->search($request->query('q'))
            ->status($request->query('status'))
            ->action($request->query('action'))
            ->product($request->query('product_id'))
            ->dateFrom($request->query('date_from'))
            ->dateTo($request->query('date_to'))
            ->latest();
    }

    public function index(Request $request)
    {
        return view('admin.meta.logs', [
            'logs' => $this->filtered($request)->paginate(30)->withQueryString(),
            'actions' => ['create', 'update', 'delete', 'refresh', 'sync_all', 'test'],
            'statuses' => ['success', 'failed', 'skipped'],
            'failedCount' => MetaSyncLog::where('status', 'failed')->count(),
        ]);
    }

    /** Re-queue every product currently in a failed sync state. */
    public function retryFailed()
    {
        RetryFailedMetaSyncs::dispatch();

        return back()->with('success', 'Failed syncs have been re-queued.');
    }

    /** Re-queue a specific set of products (from selected log rows). */
    public function retrySelected(Request $request)
    {
        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer'],
        ]);

        $ids = collect($data['product_ids'])->unique();
        $ids->each(fn ($id) => SyncProductToMeta::dispatch((int) $id, 'update', true));

        return back()->with('success', $ids->count().' product(s) re-queued for sync.');
    }

    /** Stream the filtered logs as CSV. */
    public function export(Request $request): StreamedResponse
    {
        $filename = 'meta-sync-logs-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($request) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($out, ['Date', 'Product', 'Retailer ID', 'Action', 'Status', 'Retry count', 'Execution (ms)', 'API error']);

            $this->filtered($request)->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $log) {
                    fputcsv($out, [
                        $log->created_at->toDateTimeString(),
                        $log->product_name,
                        $log->retailer_id,
                        $log->action,
                        $log->status,
                        $log->retry_count,
                        $log->execution_ms,
                        $log->api_error,
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
