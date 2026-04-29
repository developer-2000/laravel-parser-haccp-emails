<?php

namespace Database\Seeders;

use App\Models\TypeBusiness;
use Illuminate\Database\Seeder;

class TypeBusinessSeeder extends Seeder
{
    /**
     * Заполняет таблицу type_business из config/site/business.php.
     *
     * Идемпотентен: проверяет наличие записи по id и вставляет только новую.
     * Существующие записи не трогает (text может быть отредактирован вручную).
     */
    public function run(): void
    {
        $rows = config('site.business.business_types', []);

        foreach ($rows as $row) {
            TypeBusiness::firstOrCreate(
                ['id' => $row['id']],
                ['name' => $row['name']],
            );
        }
    }
}
