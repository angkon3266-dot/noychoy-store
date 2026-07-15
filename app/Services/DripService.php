<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DripCampaign;
use App\Models\DripEnrollment;

/**
 * Scheduled drip campaigns: a member is enrolled (on registration or manually),
 * then each step's push fires once its delay (hours since enrolment) elapses.
 */
class DripService
{
    public function __construct(protected NotificationService $notifications) {}

    /** Enrol a customer into a campaign (idempotent). */
    public function enroll(DripCampaign $campaign, Customer $customer): void
    {
        DripEnrollment::firstOrCreate(
            ['drip_campaign_id' => $campaign->id, 'customer_id' => $customer->id],
            ['enrolled_at' => now(), 'next_step' => 0],
        );
    }

    /** Enrol a freshly-registered member into every active registration campaign. */
    public function enrollRegistration(Customer $customer): void
    {
        DripCampaign::where('is_active', true)->where('trigger', 'registration')->get()
            ->each(fn ($c) => $this->enroll($c, $customer));
    }

    /** Enrol every member of a segment (manual campaigns). */
    public function enrollSegment(DripCampaign $campaign, \App\Models\CustomerSegment $segment): int
    {
        $members = app(SegmentService::class)->query($segment)->get();
        foreach ($members as $m) {
            $this->enroll($campaign, $m);
        }

        return $members->count();
    }

    /** Send any due steps across all active campaigns. Returns count sent. */
    public function sendDue(): int
    {
        if (! app(WebPushService::class)->ready()) {
            return 0;
        }

        $sent = 0;
        DripCampaign::where('is_active', true)->with('steps')->get()->each(function ($campaign) use (&$sent) {
            $steps = $campaign->steps;   // ordered by position
            if ($steps->isEmpty()) {
                return;
            }

            DripEnrollment::where('drip_campaign_id', $campaign->id)
                ->whereNull('completed_at')
                ->with('customer')
                ->chunkById(200, function ($enrollments) use ($steps, &$sent) {
                    foreach ($enrollments as $e) {
                        $step = $steps->get($e->next_step);   // 0-indexed position
                        if (! $step || ! $e->customer) {
                            $e->update(['completed_at' => now()]);
                            continue;
                        }
                        if ($e->enrolled_at->copy()->addHours((int) $step->delay_hours)->gt(now())) {
                            continue;   // not due yet
                        }

                        $this->notifications->pushToCustomer($e->customer_id, [
                            'title' => $step->title,
                            'body' => (string) $step->body,
                            'url' => $step->url ?: route('shop'),
                            'image' => $step->image ?: null,
                            'tag' => 'drip-'.$step->id,
                        ]);
                        $sent++;

                        $next = $e->next_step + 1;
                        $e->update($next >= $steps->count()
                            ? ['next_step' => $next, 'completed_at' => now()]
                            : ['next_step' => $next]);
                    }
                });
        });

        return $sent;
    }
}
