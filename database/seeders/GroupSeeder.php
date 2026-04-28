<?php

namespace Database\Seeders;

use App\Models\Group;
use App\Models\Language;
use Illuminate\Database\Seeder;

class GroupSeeder extends Seeder
{
    public function run(): void
    {
        $language = Language::where('code', 'de')->firstOrFail();

        $groups = [
            'Produktion',
            'Herstellung',
            'Verarbeitung',
            'Industrie',
            'Werk',
            'Fabrik',
        ];

        foreach ($groups as $keyword) {
            Group::updateOrCreate(
                [
                    'language_id' => $language->id,
                    'keyword' => $keyword,
                ],
                []
            );
        }
    }
}
