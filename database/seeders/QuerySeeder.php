<?php

namespace Database\Seeders;

use App\Models\Query;
use App\Models\Language;
use Illuminate\Database\Seeder;

class QuerySeeder extends Seeder
{
    public function run(): void
    {
        $language = Language::where('code', 'de')->firstOrFail();

        $includes = [
            'Impressum',
            'E-Mail',
            'Fleischverarbeitung',
        ];

        foreach ($includes as $keyword) {
            Query::updateOrCreate(
                [
                    'language_id' => $language->id,
                    'keyword' => $keyword,
                ],
                []
            );
        }
    }
}
