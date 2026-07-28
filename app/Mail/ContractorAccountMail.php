<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ContractorAccountMail extends Mailable
{
    use Queueable, SerializesModels;

    public $email;

    public function __construct($email)
    {
        $this->email = $email;
    }

    public function build()
    {
        return $this->subject('Your Contractor Account is Ready')
                    ->view('emails.contractor_account');
    }
}