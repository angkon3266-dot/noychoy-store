<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ReviewRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param  string  $reviewLink  signed URL to the guest-safe review page */
    public function __construct(public Order $order, public string $reviewLink) {}

    public function build()
    {
        $store = store_name();

        // Both strings are editable in Admin → Notifications; blank falls back
        // rather than sending an empty subject line.
        $subject = trim((string) Setting::get('review_request_email_subject', ''))
            ?: 'How did we do? — '.$store;

        return $this->from(config('mail.from.address'), $store)
            ->subject($subject)
            ->view('emails.review-request', [
                'reviewLink' => $this->reviewLink,
                'intro' => trim((string) Setting::get('review_request_email_body', ''))
                    ?: 'Your order has arrived. Tell other shoppers what you think — it takes about 30 seconds.',
            ]);
    }
}
