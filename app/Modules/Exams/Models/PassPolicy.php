<?php

namespace App\Modules\Exams\Models;

use App\Modules\Exams\Scoring\PassRules;
use Illuminate\Database\Eloquent\Model;

/**
 * A version of the pass rules (D-052). Rows are never changed (database trigger): a new rule is a new version.
 *
 * @property int $id
 * @property int $version
 */
class PassPolicy extends Model
{
    protected $table = 'exam_pass_policies';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'subject_pass_mark' => 'decimal:2',
            'tolerance_floor' => 'decimal:2',
            'average_must_exceed' => 'decimal:2',
        ];
    }

    /** The newest version: what a new plan uses unless told otherwise. */
    public static function current(): self
    {
        return self::query()->orderByDesc('version')->firstOrFail();
    }

    public function rules(): PassRules
    {
        return new PassRules(
            version: $this->version,
            subjectPassMark: (string) $this->subject_pass_mark,
            toleranceFloor: (string) $this->tolerance_floor,
            maxToleratedSubjects: $this->max_tolerated_subjects,
            averageMustExceed: (string) $this->average_must_exceed,
        );
    }
}
