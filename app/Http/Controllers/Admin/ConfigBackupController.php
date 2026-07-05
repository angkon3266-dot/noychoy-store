<?php

namespace App\Http\Controllers\Admin;

use App\Events\ConfigurationRestored;
use App\Http\Controllers\Concerns\ConfirmsAdminPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\SystemConfig\ImportConfigRequest;
use App\Http\Requests\SystemConfig\RestoreConfigRequest;
use App\Models\ConfigAuditLog;
use App\Models\ConfigBackup;
use App\Services\SystemConfig\ConfigBackupService;
use App\Services\SystemConfig\ConfigSchema;
use Illuminate\Http\Request;

/**
 * Backup & Restore + encrypted Import/Export for the whole configuration store.
 * Every restore/import auto-creates a backup first, so nothing is ever lost.
 */
class ConfigBackupController extends Controller
{
    use ConfirmsAdminPassword;

    public function __construct(
        private readonly ConfigBackupService $backups,
        private readonly ConfigSchema $schema,
    ) {}

    public function index()
    {
        return view('admin.system-config.backups', [
            'backups' => ConfigBackup::latest()->paginate(20),
            'sections' => $this->schema->sections(),
        ]);
    }

    /** Manual backup with an optional name. */
    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['nullable', 'string', 'max:100']]);
        $backup = $this->backups->create($data['name'] ?: 'Manual backup '.now()->format('d M Y H:i'), false);

        ConfigAuditLog::record('backup', ['message' => 'Created backup #'.$backup->id]);

        return back()->with('success', 'Backup created.');
    }

    /** Confirmation + change preview before restoring. */
    public function restorePreview(ConfigBackup $backup)
    {
        return view('admin.system-config.restore', [
            'backup' => $backup,
            'changes' => $this->backups->previewImport($backup->decodedPayload()),
        ]);
    }

    public function restore(RestoreConfigRequest $request, ConfigBackup $backup)
    {
        $confirm = $this->confirmSecurity($request);
        if (! $confirm['ok']) {
            return back()->withErrors(['security_password' => $confirm['message']]);
        }

        $result = $this->backups->restore($backup);
        event(new ConfigurationRestored('backup', $result['restored']));

        ConfigAuditLog::record('restore', [
            'message' => "Restored backup #{$backup->id} ({$result['restored']} values). Pre-restore backup #{$result['before_backup_id']} created.",
            'detail' => ['backup_id' => $backup->id, 'safety_backup' => $result['before_backup_id']],
        ]);

        return redirect()->route('admin.system-config.backups')
            ->with('success', "Configuration restored from backup #{$backup->id}. A safety backup of the previous state was created. Caches are being rebuilt.");
    }

    /** Download an encrypted export (all sections, or ?sections[]=). */
    public function export(Request $request)
    {
        $sections = array_values(array_intersect(
            (array) $request->query('sections', []),
            array_keys($this->schema->sections()),
        )) ?: null;

        $payload = $this->backups->export($sections);

        ConfigAuditLog::record('export', ['message' => 'Exported '.($sections ? implode(',', $sections) : 'all').' config.']);

        return response()->streamDownload(
            fn () => print($payload),
            'config-export-'.now()->format('Ymd-His').'.json',
            ['Content-Type' => 'application/json'],
        );
    }

    /** Upload a backup file → validate compatibility → preview changes. */
    public function importPreview(ImportConfigRequest $request)
    {
        $contents = (string) file_get_contents($request->file('backup_file')->getRealPath());

        try {
            $data = $this->backups->decodeImport($contents);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return view('admin.system-config.import', [
            'changes' => $this->backups->previewImport($data),
            'meta' => ['app' => $data['app'] ?? '—', 'created_at' => $data['created_at'] ?? null],
            'payload' => $contents, // round-tripped (already app-key encrypted)
        ]);
    }

    /** Apply a previewed import (password-confirmed). */
    public function import(Request $request)
    {
        $request->validate([
            'payload' => ['required', 'string'],
            'security_password' => ['required', 'string'],
        ]);

        $confirm = $this->confirmSecurity($request);
        if (! $confirm['ok']) {
            return back()->withErrors(['security_password' => $confirm['message']]);
        }

        try {
            $data = $this->backups->decodeImport($request->input('payload'));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $count = $this->backups->applyImport($data);
        event(new ConfigurationRestored('import', $count));

        ConfigAuditLog::record('import', ['message' => "Imported {$count} configuration values."]);

        return redirect()->route('admin.system-config.backups')
            ->with('success', "Imported {$count} settings. A safety backup was created and caches are being rebuilt.");
    }
}
