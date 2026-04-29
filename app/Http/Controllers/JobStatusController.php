<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Queue;

class JobStatusController extends BaseController
{
    private const QUEUES = ['search', 'crawl'];

    /**
     * Возвращает { running: bool } — идёт ли сейчас обработка задач
     * в очередях search/crawl.
     *
     * Используется фронтом, чтобы блокировать кнопку «Отправить»
     * пока идёт парсинг предыдущего запроса.
     *
     * Queue::size() работает для всех драйверов (database, redis, beanstalkd);
     * для sync вернёт 0, что корректно (там задачи выполняются синхронно).
     */
    public function index(): JsonResponse
    {
        $running = false;
        foreach (self::QUEUES as $queue) {
            if (Queue::size($queue) > 0) {
                $running = true;
                break;
            }
        }

        return $this->getSuccessResponse('', ['running' => $running]);
    }
}
