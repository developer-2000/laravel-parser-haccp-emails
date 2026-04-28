<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class LanguageController extends BaseController
{
    private const SESSION_KEY = 'site_language';

    /**
     * Возвращает список доступных языков из конфига и текущий выбранный язык.
     * Текущий берётся из сессии, при отсутствии — дефолт из config/site/language.php.
     */
    public function index(): JsonResponse
    {
        return $this->getSuccessResponse('', [
            'languages' => config('site.language.languages'),
            'current' => session(self::SESSION_KEY, config('site.language.default')),
        ]);
    }

    /**
     * Сохраняет выбранный язык в сессии.
     * Принимает code, валидирует, что он есть в списке languages из конфига.
     */
    public function set(Request $request)
    {
        $codes = collect(config('site.language.languages'))->pluck('code')->all();

        $data = $request->validate([
            'code' => ['required', 'string', 'in:' . implode(',', $codes)],
        ]);

        session([self::SESSION_KEY => $data['code']]);

        return ['current' => $data['code']];
    }
}
