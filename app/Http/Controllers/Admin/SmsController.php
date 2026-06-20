<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\SmsLog;
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
            'customerCount' => Customer::whereNotNull('phone')->where('blacklisted', false)->count(),
        ]);
    }

    public function send(Request $request, SmsService $sms)
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:500'],
            'message' => ['required', 'string', 'max:600'],
        ]);

        $ok = $sms->send($data['phone'], $data['message']);

        return back()->with($ok ? 'success' : 'error', $ok ? 'SMS sent.' : 'SMS failed (check settings/logs).');
    }

    public function broadcast(Request $request, SmsService $sms)
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:600']]);

        $phones = Customer::whereNotNull('phone')
            ->where('blacklisted', false)
            ->pluck('phone')
            ->all();

        if (empty($phones)) {
            return back()->with('error', 'No customers to message.');
        }

        // Chunk to keep each request reasonable for the gateway.
        $sent = 0;
        foreach (array_chunk($phones, 100) as $chunk) {
            if ($sms->send(implode(',', $chunk), $data['message'])) {
                $sent += count($chunk);
            }
        }

        return back()->with('success', "Broadcast queued for {$sent} customers.");
    }
}
