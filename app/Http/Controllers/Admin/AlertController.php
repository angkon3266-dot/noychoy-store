<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAlertRead;
use App\Services\AdminAlerts;
use Illuminate\Http\Request;

/**
 * The notification bell's read state.
 *
 * The alerts themselves are computed on demand by AdminAlerts; all this does is
 * record that somebody has seen one, then send them where it points.
 */
class AlertController extends Controller
{
    /** Mark one alert read and continue to whatever it was about. */
    public function read(Request $request, AdminAlerts $alerts)
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:120'],
            'url' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->markRead($request, [$data['key']]);

        // Only ever redirect within this site: `url` is submitted by the
        // browser, and following an arbitrary one would make the admin panel an
        // open redirect.
        $target = $data['url'] ?? null;

        return $target && $this->isOwnHost($target)
            ? redirect()->to($target)
            : back();
    }

    public function readAll(Request $request, AdminAlerts $alerts)
    {
        $this->markRead($request, $alerts->all()->pluck('key')->all());

        return back()->with('success', 'All notifications marked as read.');
    }

    /** @param  array<int,string>  $keys */
    protected function markRead(Request $request, array $keys): void
    {
        $rows = collect($keys)->filter()->unique()
            ->map(fn ($key) => [
                'user_id' => $request->user()->getKey(),
                'alert_key' => $key,
                'created_at' => now(),
            ])->all();

        if ($rows === []) {
            return;
        }

        // Re-reading an alert is a no-op rather than a duplicate-key error.
        AdminAlertRead::upsert($rows, ['user_id', 'alert_key'], ['created_at']);

        AdminAlerts::flush();
    }

    protected function isOwnHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return $host === null || $host === parse_url(config('app.url'), PHP_URL_HOST);
    }
}
