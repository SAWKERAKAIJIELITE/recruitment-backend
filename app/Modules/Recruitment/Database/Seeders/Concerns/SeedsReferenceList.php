<?php

namespace App\Modules\Recruitment\Database\Seeders\Concerns;

use App\Support\ArabicText;
use Illuminate\Database\Eloquent\Model;

/**
 * Seeding a managed list the same way everywhere: entries are matched by their normalized name, archived
 * ones included, so running a seeder again never duplicates an entry, never brings back one that staff
 * archived, and never overwrites one they renamed to another spelling.
 */
trait SeedsReferenceList
{
    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $names
     * @param  array<string, mixed>  $scope  extra key columns (district_id for areas)
     */
    protected function seedList(string $model, array $names, array $scope = []): void
    {
        foreach ($names as $name) {
            $model::withTrashed()->firstOrCreate($scope + ['name_key' => ArabicText::searchKey($name)], ['name_ar' => $name]);
        }
    }
}
