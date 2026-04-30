<?php

namespace App\Http\Controllers;

use App\Services\BackupService;
use Illuminate\Http\JsonResponse;

class BackupController extends BaseController
{
    /**
     * Идемпотентный ежедневный бэкап.
     *
     * Вызывается фронтом при загрузке главной страницы. Если файла
     * на сегодняшнюю дату ещё нет — создаёт его, иначе ничего не делает.
     */
    public function daily(BackupService $backupService): JsonResponse
    {
        $result = $backupService->dailyBackupIfNeeded();

        return $this->getSuccessResponse('', [
            'created' => $result['done'],
            'reason'  => $result['reason'],
            'file'    => $result['file'] ? basename($result['file']) : null,
        ]);
    }
}
