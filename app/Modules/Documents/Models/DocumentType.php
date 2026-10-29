<?php

namespace App\Modules\Documents\Models;

use App\Modules\Documents\Enums\DocumentDirection;
use App\Modules\Documents\Enums\DocumentScope;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Models\Concerns\IsReferenceData;
use Illuminate\Database\Eloquent\Model;

/**
 * What a file is, whose it is, who produces it, and from which application status it is required (D-057).
 *
 * @property int $id
 * @property string $name_ar
 * @property DocumentScope $scope
 * @property DocumentDirection $direction
 * @property ?ApplicationStatus $required_from_status
 */
class DocumentType extends Model
{
    use IsReferenceData;

    protected $fillable = ['scope', 'direction', 'required_from_status'];

    protected function casts(): array
    {
        return [
            'scope' => DocumentScope::class,
            'direction' => DocumentDirection::class,
            'required_from_status' => ApplicationStatus::class,
        ];
    }
}
