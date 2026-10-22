<?php

namespace App\Modules\Recruitment\Actions;

use App\Support\ArabicText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Create, rename, archive and restore entries of the managed lists (source offices, job titles, ...). */
class ManageReferenceData
{
    /** @param  class-string<Model>  $modelClass */
    public function create(string $modelClass, string $name): Model
    {
        $this->assertNameIsFree($modelClass, $name, null);

        return $modelClass::create(['name_ar' => $name]);
    }

    public function rename(Model $item, string $name): Model
    {
        $this->assertNameIsFree($item::class, $name, $item->getKey());

        $item->update(['name_ar' => $name]);

        return $item;
    }

    /** Archived entries disappear from the lists but stay valid for the applications that use them. */
    public function archive(Model $item): Model
    {
        $item->delete();

        return $item;
    }

    public function restore(Model $item): Model
    {
        $item->restore();

        return $item;
    }

    private function assertNameIsFree(string $modelClass, string $name, ?string $exceptId): void
    {
        $clash = $modelClass::withTrashed()
            ->where('name_key', ArabicText::searchKey($name))
            ->when($exceptId, fn($query) => $query->whereKeyNot($exceptId))
            ->first();

        if ($clash === null) {
            return;
        }

        throw ValidationException::withMessages([
            'name_ar' => [$clash->trashed()
                ? 'An archived entry with this name already exists: restore it instead.'
                : 'An entry with this name already exists.'],
        ]);
    }
}
