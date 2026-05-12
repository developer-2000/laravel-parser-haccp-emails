<?php

namespace App\Jobs;

use App\Mail\CompanyLetterMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Отправка письма компании-лиду через очередь 'mail'.
 * Диспатчится из CompanyEmailController@store после успешного сохранения
 * записи в company_emails. Параметры queue/tries/timeout определены в
 * config/horizon.php (supervisor 'mail').
 */
class SendCompanyLetterJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly string $recipientEmail,
        private readonly string $companyName,
        private readonly string $title,
        private readonly string $letter,
    ) {
        // Прибиваем job к отдельной очереди 'mail' — её обслуживает
        // одноимённый supervisor в Horizon (см. config/horizon.php).
        $this->onQueue('mail');
    }

    public function handle(): void
    {
        Mail::send(new CompanyLetterMail(
            $this->recipientEmail,
            $this->companyName,
            $this->title,
            $this->letter,
        ));
    }
}
