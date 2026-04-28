<?php

namespace Database\Seeders;

use App\Models\Exclude;
use App\Models\Language;
use Illuminate\Database\Seeder;

class ExcludeSeeder extends Seeder
{
    public function run(): void
    {
        $language = Language::where('code', 'de')->firstOrFail();

        $excludes = [
            'shop','online-shop','einzelhandel',
            'restaurant','cafe','bistro','imbiss','gastronomie',
            'hotel','bar','pub','kantine','lieferdienst','takeaway',
            'consulting','beratung','zertifizierung','audit','certification',
            'agentur','marketing','seo',
            'kanzlei','anwalt','recht','steuerberater',
            'apotheke','praxis','klinik',
            'menü','speisekarte','blog','news','magazin','portal','media',
            'karriere','jobs','stellenangebote',
            'service','dienstleistung','industrie-service','solutions','anbieter'
        ];

        foreach ($excludes as $keyword) {
            Exclude::updateOrCreate(
                [
                    'language_id' => $language->id,
                    'keyword' => $keyword,
                ],
                []
            );
        }
    }
}
