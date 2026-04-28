<?php

namespace Database\Seeders;

use App\Models\Language;
use Illuminate\Database\Seeder;

class LanguageSeeder extends Seeder
{
    /**
     * Заполняет таблицу languages базовым справочником из 25 кодов и названий.
     * Идемпотентен: повторный запуск не создаёт дубликаты, обновляет name по уникальному code.
     */
    public function run(): void
    {
        $languages = config('site.language.languages');

        foreach ($languages as $row) {
            Language::updateOrCreate(
                ['code' => $row['code']],
                ['name' => $row['name'], 'is_active' => true],
            );
        }
    }
}
