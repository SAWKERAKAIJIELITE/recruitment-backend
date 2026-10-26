<?php

namespace App\Modules\Recruitment\Actions;

use App\Modules\Recruitment\Enums\ReferenceType;
use App\Support\ArabicText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Create, rename, archive and restore entries of the managed lists. A name must be unique in its list,
 * in any spelling (ArabicText::searchKey). Areas are unique within their district only.
 */
class ManageReferenceData
{
    public function create(ReferenceType $type, string $name, ?int $districtId = null): Model
    {
        $this->assertNameIsFree($type, $name, $districtId, null);

        $attributes = ['name_ar' => $name];

        if ($type->isScopedToDistrict()) {
            $attributes['district_id'] = $districtId;
        }

        return $type->model()::create($attributes);
    }

    public function rename(ReferenceType $type, Model $item, string $name): Model
    {
        $this->assertNameIsFree($type, $name, $type->isScopedToDistrict() ? $item->district_id : null, $item->getKey());

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

    private function assertNameIsFree(ReferenceType $type, string $name, ?int $districtId, int|string|null $exceptId): void
    {
        $clash = $type->model()::withTrashed()
            ->where('name_key', ArabicText::searchKey($name))
            ->when($type->isScopedToDistrict(), fn($query) => $query->where('district_id', $districtId))
            ->when($exceptId !== null, fn($query) => $query->whereKeyNot($exceptId))
            ->first(['id', 'deleted_at']);   // exists() would not tell an archived clash from an active one

        if ($clash === null) {
            return;
        }

        throw ValidationException::withMessages([
            'name_ar' => [__($clash->trashed() ? 'recruitment.name_archived' : 'recruitment.name_taken')],
        ]);
    }
}
