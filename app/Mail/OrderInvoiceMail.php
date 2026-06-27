<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OrderInvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order) {}

    public function build()
    {
        $store = \App\Models\Setting::get('store_name', config('store.name'));

        return $this->from(config('mail.from.address'), $store)
            ->subject('Order confirmation & invoice — '.$store.' #'.$this->order->order_number)
            ->view('emails.invoice');
    }
}
