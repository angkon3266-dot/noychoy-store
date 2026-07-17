<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OrderInvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param  ?string  $viewLink  signed URL to the gated confirmation page */
    public function __construct(public Order $order, public ?string $viewLink = null) {}

    public function build()
    {
        $store = store_name();

        return $this->from(config('mail.from.address'), $store)
            ->subject('Order confirmation & invoice — '.$store.' #'.$this->order->order_number)
            ->view('emails.invoice', ['viewLink' => $this->viewLink]);
    }
}
