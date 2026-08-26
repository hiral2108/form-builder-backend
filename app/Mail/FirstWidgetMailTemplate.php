<?php

namespace App\Mail;

use App\Models\AdminUser;
use App\Models\ClientMailLog;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FirstWidgetMailTemplate extends Mailable
{
    use Queueable, SerializesModels;

    protected $newContent;

    protected $email;

    protected $unique_id;

    protected $newSubject;

    /**
     * Create a new message instance.
     */
    public function __construct($emailTemplate, $email, $unique_id)
    {
        $this->email = $email;
        $this->unique_id = $unique_id;

        $users = AdminUser::where('identifier', $this->unique_id)->first();

        $subject = json_decode($emailTemplate['subject'] ?? '', true);
        $content = json_decode($emailTemplate['content'] ?? '', true);

        $subjectShopify = '';
        if (is_array($subject) && isset($subject['shopify'])) {
            $subjectShopify = $subject['shopify'];
        } else {
            if (preg_match('/"shopify"\s*:\s*"(.*)/i', $emailTemplate['subject'] ?? '', $matches)) {
                $subjectShopify = stripcslashes(rtrim($matches[1], '"} '));
            } else {
                $subjectShopify = $emailTemplate['subject'] ?? '';
            }
        }

        $contentShopify = '';
        if (is_array($content) && isset($content['shopify'])) {
            $contentShopify = $content['shopify'];
        } else {
            if (preg_match('/"shopify"\s*:\s*"(.*)/i', $emailTemplate['content'] ?? '', $matches)) {
                $contentShopify = stripcslashes(rtrim($matches[1], '"} '));
            } else {
                $contentShopify = $emailTemplate['content'] ?? '';
            }
        }

        $this->newSubject = str_replace(
            '[APP NAME]',
            config('services.shopify.name'),
            $subjectShopify
        );

        $arr1 = ['[APP NAME]', "[User's Name]"];
        $arr2 = [config('services.shopify.name'), $users->shop_owner_name ?? ''];

        $this->newContent = str_replace($arr1, $arr2, $contentShopify);

        // Save mail log
        $mail_log = new ClientMailLog;
        $mail_log->to_mail = $this->email;
        $mail_log->subject = $this->newSubject;
        $mail_log->content = $this->newContent;
        $mail_log->unique_id = $this->unique_id;
        $mail_log->save();
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address('kim@klaxon.app', 'Kim Garth'),
            subject: $this->newSubject
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
