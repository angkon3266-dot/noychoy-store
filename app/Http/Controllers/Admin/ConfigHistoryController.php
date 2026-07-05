<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ConfigVersion;
use App\Models\User;
use App\Services\SystemConfig\ConfigSchema;
use App\Services\SystemConfig\ConfigVersionService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Configuration version history: browse, filter, inspect a single change,
 * compare two versions, and download the history.
 */
class ConfigHistoryController extends Controller
{
    public function __construct(
        private readonly ConfigVersionService $versions,
        private readonly ConfigSchema $schema,
    ) {}

    public function index(Request $request)
    {
        $list = ConfigVersion::query()
            ->search($request->query('q'))
            ->when($request->query('user'), fn ($w, $u) => $w->where('user_id', $u))
            ->when($request->query('section'), fn ($w, $s) => $w->where('section', $s))
            ->when($request->query('date'), fn ($w, $d) => $w->whereDate('created_at', $d))
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return view('admin.system-config.history', [
            'versions' => $list,
            'users' => User::orderBy('name')->get(['id', 'name']),
            'sections' => array_keys($this->schema->sections()),
        ]);
    }

    public function show(ConfigVersion $version)
    {
        return view('admin.system-config.version', [
            'version' => $version,
            'changes' => $this->versions->selfDiff($version),
        ]);
    }

    public function compare(Request $request)
    {
        $data = $request->validate([
            'a' => ['required', 'integer', 'exists:config_versions,id'],
            'b' => ['required', 'integer', 'exists:config_versions,id'],
        ]);

        $a = ConfigVersion::findOrFail($data['a']);
        $b = ConfigVersion::findOrFail($data['b']);

        return view('admin.system-config.compare', [
            'a' => $a,
            'b' => $b,
            'rows' => $this->versions->compare($a, $b),
        ]);
    }

    /** Download the version history as CSV (metadata only — no secret values). */
    public function download(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'User', 'IP', 'Section', 'Notes']);
            ConfigVersion::latest()->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $v) {
                    fputcsv($out, [$v->created_at->toDateTimeString(), $v->user_name, $v->ip, $v->section, $v->notes]);
                }
            });
            fclose($out);
        }, 'config-history-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }
}
