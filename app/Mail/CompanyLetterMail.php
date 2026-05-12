<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Письмо компании-лиду с произвольным текстом (letter), который оператор
 * набрал в модалке EmailLetterModal на фронте. Отправляется через очередь
 * 'mail' после успешного сохранения записи в company_emails.
 */
class CompanyLetterMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly string $recipientEmail,
        private readonly string $companyName,
        private readonly string $title,
        private readonly string $letter,
    ) {}

    /**
     * Envelope: from = наш адрес (SPF/DKIM/DMARC alignment), to = email компании,
     * subject = title из формы (фоллбэк на дефолтную строку, если оператор
     * прислал пустой title — на случай ручных вызовов в обход формы).
     */
    public function envelope(): Envelope
    {
        $subject = trim($this->title) !== ''
            ? $this->title
            : 'Message from ' . config('app.name');

        return new Envelope(
            from: new Address(
                config('mail.from.address'),
                config('mail.from.name'),
            ),
            to: [$this->recipientEmail],
            subject: $subject,
        );
    }

    /**
     * Шаблон письма: companyName в приветствие, title заголовком над body,
     * letter — основной текст.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.company-letter',
            with: [
                'companyName' => $this->companyName,
                'title'       => $this->title,
                'letter'      => $this->letter,
            ],
        );
    }
}
