<?php

namespace Database\Seeders;

use App\Models\QuerySet;
use App\Models\Query;
use App\Models\Group;
use App\Models\Exclude;
use App\Models\Language;
use Illuminate\Database\Seeder;

class QuerySetSeeder extends Seeder
{
    public function run(): void
    {
        $language = Language::where('code', 'de')->firstOrFail();

        $queryIds = Query::where('language_id', $language->id)->pluck('id')->toArray();
        $groupIds = Group::where('language_id', $language->id)->pluck('id')->toArray();
        $excludeIds = Exclude::where('language_id', $language->id)->pluck('id')->toArray();

        QuerySet::updateOrCreate(
            [
                'name' => 'meat Germany',
                'language_id' => $language->id,
            ],
            [
                'query_ids' => $queryIds,
                'group_ids' => $groupIds,
                'exclude_ids' => $excludeIds,
            ]
        );
    }
}
