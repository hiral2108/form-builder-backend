<?php

namespace App\Mail;

use App\Models\ClientMailLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LimitMailTemplate extends Mailable
{
    use Queueable, SerializesModels;

    protected $mailMessage;
    protected $newTitle;
    protected $email;
    protected $unique_id;

    /**
     * Create a new message instance.
     */
    public function __construct($userData, $currentPlanDetail, $email, $unique_id)
    {
        $this->email = $email;
        $this->unique_id = $unique_id;

        $arr1 = ['{plan name}','[APP NAME]'];
        $arr2 = [$currentPlanDetail['name'], config('services.shopify.name')];

        $this->newTitle = str_replace($arr1, $arr2, $currentPlanDetail['limit_title']);

        $arr3 = ['{plan name}','[APP NAME]','{VISITOR DATE}'];
        $arr4 = [
            $currentPlanDetail['name'],
            config('services.shopify.name'),
            date("m/d/Y", strtotime($userData['next_reset_date']))
        ];

        $this->mailMessage = str_replace($arr3, $arr4, $currentPlanDetail['limit_text']);

        // Save Mail Log
        $mail_log = new ClientMailLog();
        $mail_log->to_mail = $email;
        $mail_log->subject = $this->newTitle;
        $mail_log->content = $this->mailMessage;
        $mail_log->unique_id = $unique_id;
        $mail_log->save();
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address('contact@klaxon.app', 'Kim Garth'),
            subject: $this->newTitle
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
                'mailMessage' => $this->mailMessage,
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
