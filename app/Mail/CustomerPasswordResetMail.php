<?php

namespace App\Mail;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CustomerPasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Customer $customer, public string $url) {}

    public function build()
    {
        return $this->subject('Reset your '.store_name().' password')
            ->view('emails.password-reset');
    }
}
