<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ConfirmsAdminPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\SystemConfig\SaveConfigRequest;
use App\Models\ConfigAuditLog;
use App\Services\SystemConfig\ConnectionTester;
use App\Services\SystemConfig\DatabaseConfigManager;
use App\Services\SystemConfig\SystemConfigService;
use Illuminate\Http\Request;

/**
 * System Configuration Manager — the central, Super-Admin-only place to edit
 * platform settings without touching .env. Values are stored encrypted in the
 * DB and applied as runtime overrides; the database section uses the safe
 * Test→Apply→Rollback wizard.
 */
class SystemConfigController extends Controller
{
    use ConfirmsAdminPassword;

    public function __construct(
        private readonly SystemConfigService $config,
        private readonly ConnectionTester $tester,
        private readonly DatabaseConfigManager $database,
    ) {}

    public function index()
    {
        return view('admin.system-config.index', [
            'sections' => $this->config->schema()->sections(),
        ]);
    }

    public function edit(string $section)
    {
        $def = $this->config->schema()->section($section);
        abort_unless($def, 404);

        return view('admin.system-config.section', [
            'key' => $section,
            'section' => $def,
            'fields' => $this->config->uiValues($section),
            'testResult' => session('meta_config_test'),
        ]);
    }

    public function save(SaveConfigRequest $request, string $section)
    {
        abort_unless($this->config->schema()->hasSection($section), 404);

        // Mandatory password confirmation.
        $confirm = $this->confirmSecurity($request);
        if (! $confirm['ok']) {
            return back()->withErrors(['security_password' => $confirm['message']])->withInput();
        }

        $result = $this->config->save($section, (array) $request->input('values', []), $request->input('notes'));

        ConfigAuditLog::record('save', [
            'section' => $section,
            'success' => $result['ok'],
            'message' => $result['message'],
            'detail' => ['keys' => $result['changed']], // keys only — never values
        ]);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /** Live "Test Connection" for a section (no save, no password needed). */
    public function test(Request $request, string $section)
    {
        $def = $this->config->schema()->section($section);
        abort_unless($def, 404);

        // Merge submitted values over current so blank (unchanged) fields resolve.
        $submitted = array_filter((array) $request->input('values', []), fn ($v) => $v !== null && $v !== '');
        $merged = array_merge($this->config->sectionCurrent($section), $submitted);

        $result = ! empty($def['env_managed'])
            ? $this->database->test($merged)
            : $this->tester->test($def['test'] ?? '', $merged);

        ConfigAuditLog::record('test', [
            'section' => $section,
            'success' => $result['ok'],
            'message' => $result['message'],
        ]);

        return back()->with('meta_config_test', $result);
    }

    /** Audit log page. */
    public function audit(Request $request)
    {
        $logs = ConfigAuditLog::query()
            ->filter($request->only('user', 'action', 'section', 'date'))
            ->latest()
            ->paginate(40)
            ->withQueryString();

        return view('admin.system-config.audit', [
            'logs' => $logs,
            'users' => \App\Models\User::orderBy('name')->get(['id', 'name']),
            'sections' => array_keys($this->config->schema()->sections()),
            'actions' => ['save', 'restore', 'import', 'export', 'test', 'backup', 'security_failed'],
        ]);
    }
}
