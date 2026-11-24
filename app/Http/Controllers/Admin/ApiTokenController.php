<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Mcp\StoreServer;
use App\Mcp\Tools\StoreTool;
use App\Models\McpToolCall;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Admin → Claude connector (route name kept as `api-tokens`, an admin-only
 * section): the connector URL with setup steps for each Claude app, every
 * connection (pasted tokens and claude.ai sign-ins) with who it acts as, and
 * the audit log of every tool Claude has called.
 */
class ApiTokenController extends Controller
{
    public function index(Request $request)
    {
        $tokenFilter = $request->integer('token') ?: null;

        return view('admin.api-tokens', [
            'tokens' => ApiToken::with('user')->latest()->get(),
            'mcpUrl' => url('/mcp'),
            'calls' => McpToolCall::with('user')
                ->when($tokenFilter, fn ($q) => $q->where('api_token_id', $tokenFilter))
                ->latest('id')->paginate(50)->withQueryString(),
            'tokenFilter' => $tokenFilter,
            'callsToday' => McpToolCall::where('created_at', '>=', now()->subDay())->count(),
            'failedToday' => McpToolCall::where('created_at', '>=', now()->subDay())->where('ok', false)->count(),
        ]);
    }

    /**
     * "How it's built" — the blueprint for building the same connector into
     * another store. One Markdown source (docs/CLAUDE-CONNECTOR-BLUEPRINT.md)
     * for both this page and the download, plus the tool list read live from
     * StoreServer so it can never fall behind the code.
     */
    public function blueprint()
    {
        return view('admin.connector-blueprint', [
            'html' => Str::markdown(self::blueprintMarkdown(), ['html_input' => 'escape', 'allow_unsafe_links' => false]),
            'tools' => self::toolCatalogue(),
            'mcpUrl' => url('/mcp'),
        ]);
    }

    public function blueprintDownload()
    {
        $md = rtrim(self::blueprintMarkdown())."\n\n## Appendix — the tools in this store\n\n"
            ."| Tool | Section | Kind | What it does |\n|---|---|---|---|\n"
            .collect(self::toolCatalogue())->map(fn ($t) => '| `'.$t['name'].'` | '.($t['section'] ?? 'any').' | '.$t['kind'].' | '.str_replace('|', '/', $t['description']).' |')->implode("\n")."\n";

        return response($md, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="claude-connector-blueprint.md"',
        ]);
    }

    private static function blueprintMarkdown(): string
    {
        return (string) @file_get_contents(base_path('docs/CLAUDE-CONNECTOR-BLUEPRINT.md'));
    }

    /** @return array<int,array{name:string,section:?string,kind:string,description:string,class:string}> */
    private static function toolCatalogue(): array
    {
        $classes = (new \ReflectionClass(StoreServer::class))->getDefaultProperties()['tools'];

        return collect($classes)->map(function (string $class) {
            /** @var StoreTool $tool */
            $tool = app($class);
            $annotations = $tool->annotations();

            return [
                'name' => $tool->name(),
                'section' => $tool->section(),
                'kind' => match (true) {
                    ! empty($annotations['readOnlyHint']) => 'read-only',
                    ! empty($annotations['destructiveHint']) => 'destructive',
                    default => 'changes data',
                },
                'description' => $tool->description(),
                'class' => Str::after($class, 'App\\Mcp\\Tools\\'),
            ];
        })->all();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'expires_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        // A token acts as the admin who makes it — their role is its reach.
        [, $plain] = ApiToken::issue(
            $data['name'],
            null,
            filled($data['expires_days'] ?? null) ? now()->addDays((int) $data['expires_days']) : null,
            $request->user(),
        );

        // Flashed, not stored: this is the only time the plaintext exists
        // outside the caller's clipboard.
        return back()->with('new_api_token', $plain)
            ->with('success', 'Token created. Copy it now — it cannot be shown again.');
    }

    public function destroy(ApiToken $apiToken)
    {
        $apiToken->delete();

        return back()->with('success', 'Disconnected. Anything using that connection stops working immediately.');
    }
}
