<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolError;
use App\Models\ApiToken;
use App\Models\McpToolCall;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Support\ValidationMessages;

/**
 * Base for every connector tool.
 *
 * Three jobs, so no tool can forget them:
 *  1. Role scoping — a tool belongs to an admin section (User::sectionsFor).
 *     It is not even listed for a token whose user cannot open that section,
 *     and a call by name is refused the same way.
 *  2. Audit — every call, allowed or not, lands in mcp_tool_calls with its
 *     arguments, outcome and timing (Admin → Claude connector).
 *  3. Errors Claude can act on — ToolError and validation failures come back
 *     as a tool error with the message; anything unexpected is logged at
 *     error level (production logs nothing below that) and summarised.
 */
abstract class StoreTool extends Tool
{
    /** Admin section the user's role must reach; null = any admin user. */
    protected ?string $section = null;

    abstract protected function run(Request $request): Response|ResponseFactory;

    public function shouldRegister(): bool
    {
        return $this->allows(Auth::user());
    }

    public function allows(?User $user): bool
    {
        return $user !== null && ($this->section === null || $user->canAccess($this->section));
    }

    public function section(): ?string
    {
        return $this->section;
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $started = hrtime(true);
        $user = Auth::user();

        if (! $this->allows($user)) {
            $message = 'Not allowed: this connector acts as '.($user?->name ?? 'nobody')
                .' ('.($user?->role ?? 'no role').'), whose role cannot reach '.$this->section.'.';
            $this->audit($request, false, $message, $started);

            return Response::error($message);
        }

        try {
            $response = $this->run($request);
        } catch (ToolError $e) {
            $this->audit($request, false, $e->getMessage(), $started);

            return Response::error($e->getMessage());
        } catch (ValidationException $e) {
            $message = ValidationMessages::from($e);
            $this->audit($request, false, $message, $started);

            return Response::error($message);
        } catch (\Throwable $e) {
            Log::error('Claude connector: '.$this->name().' failed', ['exception' => $e]);
            $this->audit($request, false, class_basename($e).': '.$e->getMessage(), $started);

            return Response::error('The store could not complete '.$this->name().': '.$e->getMessage());
        }

        $failed = $response instanceof Response && $response->isError();
        $this->audit($request, ! $failed, $failed ? (string) $response->content() : null, $started);

        return $response;
    }

    /** JSON payload as the tool result. */
    protected function json(mixed $payload): Response
    {
        return Response::json($payload);
    }

    /** A required argument, or a ToolError naming it. */
    protected function need(Request $request, string $key): mixed
    {
        $value = $request->get($key);
        if ($value === null || $value === '' || $value === []) {
            throw new ToolError("{$this->name()} needs `{$key}`.");
        }

        return $value;
    }

    /** Guard for destructive and customer-facing tools. */
    protected function requireConfirm(Request $request, string $what): void
    {
        if (! filter_var($request->get('confirm', false), FILTER_VALIDATE_BOOLEAN)) {
            throw new ToolError("Pass confirm=true to {$what}. Ask the store owner before you do.");
        }
    }

    private function audit(Request $request, bool $ok, ?string $error, int $started): void
    {
        try {
            /** @var ApiToken|null $token */
            $token = request()->attributes->get('api_token');

            McpToolCall::create([
                'api_token_id' => $token?->id,
                'user_id' => Auth::id(),
                'token_name' => $token?->name,
                'tool' => $this->name(),
                'arguments' => $this->trimArguments($request->all()),
                'ok' => $ok,
                'error' => $error !== null ? Str::limit($error, 480) : null,
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'ip' => request()->ip(),
            ]);
        } catch (\Throwable $e) {
            // The audit must never be the reason a tool call fails.
            Log::error('Claude connector: audit write failed', ['exception' => $e]);
        }
    }

    /** Long descriptions are cut so the audit table stays small. */
    private function trimArguments(array $args): array
    {
        array_walk_recursive($args, function (&$v) {
            if (is_string($v)) {
                $v = Str::limit($v, 300);
            }
        });

        return $args;
    }
}
