<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Response;

class BaseController extends Controller
{
    /**
     * Формирует успешный JSON-ответ.
     *
     * @param string $message Сообщение (по умолчанию пустое)
     * @param array $data Дополнительные данные (по умолчанию пустой массив)
     * @param int $code HTTP-код состояния (по умолчанию 200)
     * @return JsonResponse
     */
    protected function getSuccessResponse(string $message = '', array $data = [], int $code = 200): JsonResponse
    {
        return Response::json([
            'status' => 'success',
            'message' => $message,
            'data' => $data
        ], $code);
    }

    /**
     * Формирует ошибочный JSON-ответ.
     *
     * @param string $message Сообщение об ошибке (по умолчанию пустое)
     * @param array $errors Дополнительные ошибки (по умолчанию пустой массив)
     * @param int $code HTTP-код состояния (по умолчанию 400)
     * @return JsonResponse
     */
    protected function getErrorResponse(string $message = '', array $errors = [], int $code = 400): JsonResponse
    {
        return Response::json([
            'status' => 'error',
            'message' => $message,
            'errors' => $errors
        ], $code);
    }
}
