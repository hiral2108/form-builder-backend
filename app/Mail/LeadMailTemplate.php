<?php

namespace App\Mail;

use App\Models\ClientMailLog;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LeadMailTemplate extends Mailable
{
    use Queueable, SerializesModels;

    public string $newContent;

    public string $newSubject;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public array $emailSettings,
        public array $emailFields,
        public ?string $pageUrl = null,
        public ?string $storeIdentifier = null
    ) {
        $this->newSubject = $this->emailSettings['subject'] ?: 'New Form Submission';

        // Build email body content
        $body = $this->emailSettings['emailBody'] ?? '';

        // Create a nice HTML table for the fields
        $fieldsHtml = '<div style="margin-top: 20px; border-top: 1px solid #e2e8f0; padding-top: 20px;">';
        $fieldsHtml .= '<h3 style="color: #1e293b; margin-bottom: 12px; font-size: 16px;">Submission Details</h3>';
        $fieldsHtml .= '<table style="width: 100%; border-collapse: collapse; font-family: sans-serif; font-size: 14px; color: #334155;">';
        $fieldsHtml .= '<tbody>';

        foreach ($this->emailFields as $field) {
            $name = htmlspecialchars($field['field_name'] ?? 'Field');
            $val = $field['value'] ?? '';
            if (is_array($val)) {
                $val = implode(', ', $val);
            }
            $val = htmlspecialchars((string) $val);

            $fieldsHtml .= '<tr style="border-bottom: 1px solid #f1f5f9;">';
            $fieldsHtml .= '<td style="padding: 10px 0; font-weight: 600; width: 40%; color: #475569; vertical-align: top;">'.$name.'</td>';
            $fieldsHtml .= '<td style="padding: 10px 0; color: #0f172a; vertical-align: top;">'.nl2br($val).'</td>';
            $fieldsHtml .= '</tr>';
        }

        if ($this->pageUrl) {
            $fieldsHtml .= '<tr style="border-bottom: 1px solid #f1f5f9;">';
            $fieldsHtml .= '<td style="padding: 10px 0; font-weight: 600; color: #475569; vertical-align: top;">Submitted From Page</td>';
            $fieldsHtml .= '<td style="padding: 10px 0; color: #0f172a; vertical-align: top;"><a href="'.htmlspecialchars($this->pageUrl).'" target="_blank">'.htmlspecialchars($this->pageUrl).'</a></td>';
            $fieldsHtml .= '</tr>';
        }

        $fieldsHtml .= '</tbody></table></div>';

        // Replace custom field placeholder [fields] if exists, otherwise append
        if (stripos($body, '[fields]') !== false) {
            $this->newContent = str_ireplace('[fields]', $fieldsHtml, $body);
        } else {
            $this->newContent = $body.$fieldsHtml;
        }

        // Log email
        ClientMailLog::create([
            'to_mail' => $this->emailSettings['sendToEmail'] ?: '',
            'subject' => $this->newSubject,
            'content' => $this->newContent,
            'unique_id' => $this->storeIdentifier,
        ]);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $fromEmail = config('mail.from.address', 'contact@klaxon.app');
        $fromName = $this->emailSettings['name'] ?: config('mail.from.name', 'Form Builder');

        $envelope = new Envelope(
            from: new Address($fromEmail, $fromName),
            subject: $this->newSubject,
        );

        if (! empty($this->emailSettings['replyTo'])) {
            $envelope->replyTo = [new Address($this->emailSettings['replyTo'])];
        }

        if (! empty($this->emailSettings['cc'])) {
            $ccEmails = array_filter(array_map('trim', explode(',', $this->emailSettings['cc'])));
            $envelope->cc = array_map(fn ($email) => new Address($email), $ccEmails);
        }

        if (! empty($this->emailSettings['bcc'])) {
            $bccEmails = array_filter(array_map('trim', explode(',', $this->emailSettings['bcc'])));
            $envelope->bcc = array_map(fn ($email) => new Address($email), $bccEmails);
        }

        return $envelope;
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
     */
    public function attachments(): array
    {
        return [];
    }
}
