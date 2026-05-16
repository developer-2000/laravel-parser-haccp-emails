<?php

namespace App\Jobs;

use App\Mail\CompanyLetterMail;
use App\Services\AppLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Отправка письма компании-лиду через очередь 'mail'.
 * Диспатчится из CompanyEmailController@store после успешного сохранения
 * записи в company_emails. Параметры queue/tries/timeout определены в
 * config/horizon.php (supervisor 'mail').
 *
 * Логирование (send_mail.log) идёт по трём точкам:
 *   - "поставлено в очередь" — в контроллере, сразу после dispatch;
 *   - "Отправлено" — здесь, в handle() после успешного Mail::send;
 *   - "Ошибка отправки" — здесь, в failed() (вызывается Laravel после
 *     исчерпания всех ретраев).
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

        app(AppLogger::class)->writeFile(
            "Отправлено письмо на {$this->recipientEmail} | title=\"{$this->title}\"",
            'send_mail.log',
        );
    }

    /**
     * Вызывается Laravel'ем, когда исчерпаны все попытки выполнения job'а
     * (см. tries в config/horizon.php). Если Mail::send бросил исключение —
     * сюда прилетит этот же exception в $e.
     */
    public function failed(Throwable $e): void
    {
        app(AppLogger::class)->writeFile(
            "Ошибка отправки на {$this->recipientEmail} | title=\"{$this->title}\" | error: {$e->getMessage()}",
            'send_mail.log',
        );
    }
}
