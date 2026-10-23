<?php

namespace App\Modules\Recruitment\Http\Resources;

use Illuminate\Database\Eloquent\Model;

/** How a list entry appears inside other resources: id and name, with a flag when it is archived. */
final class ReferenceSummary
{
    /** @return array{id: int, name_ar: string, archived: bool}|null */
    public static function of(?Model $item): ?array
    {
        if ($item === null) {
            return null;
        }

        return [
            'id' => (int) $item->getKey(),
            'name_ar' => (string) $item->getAttribute('name_ar'),
            'archived' => $item->getAttribute('deleted_at') !== null,
        ];
    }
}
