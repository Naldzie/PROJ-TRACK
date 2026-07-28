<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LoginPageLinkMail extends Mailable
{
    use SerializesModels;

    public $email;

    public function __construct($email)
    {
        $this->email = $email;
    }

    public function build()
    {
        return $this->subject('Your Staff Account Access')
                    ->view('emails.loginpagelink');
    }
}