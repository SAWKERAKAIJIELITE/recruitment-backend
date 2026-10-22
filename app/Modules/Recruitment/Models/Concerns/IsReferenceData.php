<?php

namespace App\Modules\Recruitment\Models\Concerns;

use App\Support\ArabicText;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A managed list entry: a name, archived (soft deleted) instead of removed. */
trait IsReferenceData
{
    use HasUuids, SoftDeletes;

    public function initializeIsReferenceData(): void
    {
        $this->fillable = ['name_ar'];
    }

    /** Setting the name also keeps its normalised key in step (an attribute mutator, so it works even in seeders). */
    protected function nameAr(): Attribute
    {
        return Attribute::make(set: fn(string $value) => [
            'name_ar' => trim($value),
            'name_key' => ArabicText::searchKey($value),
        ]);
    }
}
