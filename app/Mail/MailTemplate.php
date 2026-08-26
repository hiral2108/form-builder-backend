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

class MailTemplate extends Mailable
{
    use Queueable, SerializesModels;

    protected $emailTemplate;

    protected $email;

    protected $unique_id;

    protected $newSubject;

    protected $newContent;

    /**
     * Create a new message instance.
     */
    public function __construct($emailTemplate, $email, $unique_id)
    {
        $this->emailTemplate = $emailTemplate;
        $this->email = $email;
        $this->unique_id = $unique_id;

        // Decode subject & content
        $subject = json_decode($this->emailTemplate['subject'] ?? '', true);
        $content = json_decode($this->emailTemplate['content'] ?? '', true);

        $subjectShopify = '';
        if (is_array($subject) && isset($subject['shopify'])) {
            $subjectShopify = $subject['shopify'];
        } else {
            if (preg_match('/"shopify"\s*:\s*"(.*)/i', $this->emailTemplate['subject'] ?? '', $matches)) {
                $subjectShopify = stripcslashes(rtrim($matches[1], '"} '));
            } else {
                $subjectShopify = $this->emailTemplate['subject'] ?? '';
            }
        }

        $contentShopify = '';
        if (is_array($content) && isset($content['shopify'])) {
            $contentShopify = $content['shopify'];
        } else {
            if (preg_match('/"shopify"\s*:\s*"(.*)/i', $this->emailTemplate['content'] ?? '', $matches)) {
                $contentShopify = stripcslashes(rtrim($matches[1], '"} '));
            } else {
                $contentShopify = $this->emailTemplate['content'] ?? '';
            }
        }

        $this->newSubject = str_replace(
            '[APP NAME]',
            config('services.shopify.name'),
            $subjectShopify
        );

        $this->newContent = str_replace(
            '[APP NAME]',
            config('services.shopify.name'),
            $contentShopify
        );

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
