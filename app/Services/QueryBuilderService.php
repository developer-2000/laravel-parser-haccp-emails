<?php

namespace App\Services;

use App\Jobs\SearchJob;
use App\Models\QuerySet;
use App\Models\Query;
use App\Models\Group;
use App\Models\Exclude;

class QueryBuilderService
{
    public function searchQuery(int $id): string
    {
        $logger = app(AppLogger::class);
        $logger->clear('search.log');
        $logger->clear('crawl.log');

//        $queryString = $this->buildByQueryString($id);
        $queryString = 'Fleischverarbeitung Impressum Kontakt Deutschland';

        SearchJob::dispatch($id, $queryString)->onQueue('search');

        return $queryString;
    }


    /**
     * Формирует правильный запрос
     *
     * @param int $id
     * @return string
     */
    private function buildByQueryString(int $id): string
    {
        $set = QuerySet::findOrFail($id);

        $queries = Query::whereIn('id', $set->query_ids)->pluck('keyword')->toArray();
        $groups = Group::whereIn('id', $set->group_ids)->pluck('keyword')->toArray();
        $excludes = Exclude::whereIn('id', $set->exclude_ids)->pluck('keyword')->toArray();

        // includes
        $includePart = '';
        if (!empty($queries)) {
            $includePart = '"' . implode('" "', $queries) . '"';
        }

        // group OR
        $groupPart = '';
        if (!empty($groups)) {
            $groupPart = '(' . implode(' OR ', array_map(fn($g) => "\"$g\"", $groups)) . ')';
        }

        // exclude
        $excludePart = '';
        if (!empty($excludes)) {
            $excludePart = implode(' ', array_map(fn($e) => "-$e", $excludes));
        }

        return trim(implode(' ', array_filter([
            'site:.de',
            $includePart,
            $groupPart,
            $excludePart
        ])));
    }
}
