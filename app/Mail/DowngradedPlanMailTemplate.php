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

class DowngradedPlanMailTemplate extends Mailable
{
    use Queueable, SerializesModels;

    public string $newSubject;

    public string $newContent;

    /**
     * Create a new message instance.
     */
    public function __construct(
        protected $downgradedMailTemplate,
        protected string $newPlanName = '',
        protected ?string $oldPlanName = null,
        protected string $email = '',
        protected string $unique_id = ''
    ) {
        $arr1 = ['[new_plan]', '[APP NAME]'];
        $arr2 = [$this->newPlanName, config('services.shopify.name') ?? ''];
        $this->newSubject = str_replace($arr1, $arr2, $this->downgradedMailTemplate['downgraded_mail_title'] ?? '');

        if (empty($this->oldPlanName)) {
            $arr1 = ['from [current_plan] ', '[new_plan]', '[APP NAME]'];
            $arr2 = ['', $this->newPlanName, config('services.shopify.name') ?? ''];
            $this->newContent = str_replace($arr1, $arr2, $this->downgradedMailTemplate['downgraded_mail_text'] ?? '');
        } else {
            $arr1 = ['[current_plan]', '[new_plan]', '[APP NAME]'];
            $arr2 = [$this->oldPlanName, $this->newPlanName, config('services.shopify.name') ?? ''];
            $this->newContent = str_replace($arr1, $arr2, $this->downgradedMailTemplate['downgraded_mail_text'] ?? '');
        }

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
                'Kim Garth'
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
