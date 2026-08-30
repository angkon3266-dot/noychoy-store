<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendBroadcastSms;
use App\Models\Customer;
use App\Models\SmsLog;
use App\Services\Meta\MetaQueueRunner;
use App\Services\SmsService;
use Illuminate\Http\Request;

class SmsController extends Controller
{
    public function index(SmsService $sms)
    {
        $logs = SmsLog::latest()->paginate(30);
        $balance = $sms->isEnabled() ? $sms->getBalance() : [];

        return view('admin.sms.index', [
            'logs' => $logs,
            'balance' => $balance,
            'enabled' => $sms->isEnabled(),
            // Exactly what a broadcast would send, counted the same way — a
            // headline of 636 above a send of 634 is a bug report waiting to
            // happen.
            'customerCount' => count($this->broadcastNumbers()),
        ]);
    }

    public function send(Request $request, SmsService $sms)
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:500'],
            'message' => ['required', 'string', 'max:600'],
        ]);

        $ok = $sms->send($data['phone'], $data['message']);

        return back()->with($ok ? 'success' : 'error',
            ($ok ? 'SMS sent.' : 'SMS failed.').$sms->explainLast());
    }

    public function broadcast(Request $request, SmsService $sms, MetaQueueRunner $runner)
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:600']]);

        if (! $sms->isEnabled()) {
            return back()->with('error', 'SMS is switched off or missing credentials.'.$sms->explainLast());
        }

        $phones = $this->broadcastNumbers();

        if (empty($phones)) {
            return back()->with('error', 'No customers to message.');
        }

        foreach (array_chunk($phones, SendBroadcastSms::CHUNK) as $chunk) {
            SendBroadcastSms::dispatch($chunk, $data['message']);
        }

        // The scheduler drains the queue within a minute; this just makes the
        // usual case feel immediate. It is a no-op where exec() is disabled.
        $runner->kick();

        $count = count($phones);

        return back()->with('success',
            "Broadcast queued to {$count} customers — it sends in the background over the next few minutes. "
            .'Each batch appears in the log below as it goes out.');
    }

    /**
     * Every number a broadcast would reach, exactly once.
     *
     * Customer::phone is stored canonically and uniquely indexed, so the
     * ->unique() here should never fire. It stays because the cost of being
     * wrong is asymmetric: a redundant filter costs nothing, and a duplicate
     * costs a second charge and a customer who got the same advert twice.
     *
     * @return array<int,string>
     */
    protected function broadcastNumbers(): array
    {
        return Customer::whereNotNull('phone')
            ->where('blacklisted', false)
            ->pluck('phone')
            ->map(fn ($p) => SmsService::normalizeNumber((string) $p))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
