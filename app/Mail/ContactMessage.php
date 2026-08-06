<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ContactMessage extends Mailable
{
    use Queueable, SerializesModels;

    public string $name;
    public string $email;
    public string $messageBody;

    /**
     * Create a new message instance.
     */
    public function __construct(string $name, string $email, string $messageBody)
    {
        $this->name = $name;
        $this->email = $email;
        $this->messageBody = $messageBody;
    }

    /**
     * Build the message.
     */
    public function build(): static
    {
        return $this->subject('Contact Form Submission from Ilusion Vault')
                    ->replyTo($this->email, $this->name)
                    ->view('emails.contact')
                    ->with([
                        'name' => $this->name,
                        'email' => $this->email,
                        'messageBody' => $this->messageBody,
                    ]);
    }
}
?>
