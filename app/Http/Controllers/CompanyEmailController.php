<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexCompanyEmailRequest;
use App\Http\Requests\StoreCompanyEmailRequest;
use App\Jobs\SendCompanyLetterJob;
use App\Models\Company;
use App\Models\CompanyEmail;
use App\Services\AppLogger;
use Illuminate\Http\JsonResponse;

class CompanyEmailController extends BaseController
{
    /**
     * Возвращает историю писем для пары (company_id + email) — для отображения
     * в гармошке на фронте: новые сверху, body — letter.
     */
    public function index(IndexCompanyEmailRequest $request): JsonResponse
    {
        $data = $request->validated();

        $items = CompanyEmail::query()
            ->where('company_id', $data['company_id'])
            ->where('email', $data['email'])
            ->orderByDesc('id')
            ->get(['id', 'title', 'letter', 'created_at'])
            ->map(fn ($e) => [
                'id'         => $e->id,
                'title'      => $e->title,
                'letter'     => $e->letter,
                'created_at' => $e->created_at?->toIso8601String(),
            ])
            ->values();

        return $this->getSuccessResponse('', ['items' => $items]);
    }

    /**
     * Сохраняет письмо (letter) для пары (company_id + email).
     * Каждое сохранение — новая запись в company_emails (история переписки),
     * без upsert'а: если в будущем потребуется только одна актуальная версия,
     * заменить на updateOrCreate по company_id+email.
     */
    public function store(StoreCompanyEmailRequest $request): JsonResponse
    {
        $data = $request->validated();

        $item = CompanyEmail::query()->create([
            'company_id' => $data['company_id'],
            'email'      => $data['email'],
            'title'      => $data['title'],
            'letter'     => $data['letter'],
        ]);

        // Имя компании нужно для приветствия в шаблоне. Если name пустой
        // (например, парсер не нашёл title) — передаём пустую строку, шаблон
        // отрендерит обезличенное "Здравствуйте!".
        $companyName = (string) (Company::query()
            ->whereKey($data['company_id'])
            ->value('name') ?? '');

        SendCompanyLetterJob::dispatch(
            $data['email'],
            $companyName,
            $data['title'],
            $data['letter'],
        );

        return $this->getSuccessResponse('Письмо поставлено в очередь');
    }
}
