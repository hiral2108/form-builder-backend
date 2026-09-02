<?php

namespace App\Mail;

use App\Models\ClientMailLog;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SubscriptionMailTemplate extends Mailable
{
    use Queueable, SerializesModels;

    public string $newSubject;

    public string $newContent;

    /**
     * Create a new message instance.
     */
    public function __construct(
        protected $subscriptionMailTemplate,
        protected string $email = '',
        protected string $unique_id = ''
    ) {
        $this->newSubject = str_replace('[APP NAME]', config('services.shopify.name') ?? '', $this->subscriptionMailTemplate['subscription_title'] ?? '');
        $this->newContent = str_replace('[APP NAME]', config('services.shopify.name') ?? '', $this->subscriptionMailTemplate['subscription_text'] ?? '');

        // Save mail log
        ClientMailLog::create([
            'to_mail' => $this->email,
            'subject' => $this->newSubject,
            'content' => $this->newContent,
            'unique_id' => $this->unique_id,
        ]);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                'contact@klaxon.app',
                'Kim from Klaxon App'
            ),
            subject: mb_encode_mimeheader($this->newSubject, 'UTF-8'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'user.email-template',
            with: [
                'mailMessage' => $this->newContent,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
