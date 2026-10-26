<?php

namespace App\Modules\Imports\Enums;

/** Analysis doc §09 step 5: valid, needs review, rejected; plus the staff decisions and the result. */
enum ImportRowStatus: string
{
    /** No problem: imported on approval. */
    case Valid = 'valid';

    /** No error, but a person must decide (the national number belongs to someone with different data). */
    case NeedsReview = 'needs_review';

    /** At least one error: correct the row (or the lists) before approval, or it is not imported. */
    case Invalid = 'invalid';

    /** Staff decided not to import this row (reason kept). */
    case Excluded = 'excluded';

    /** Became an application on approval. */
    case Imported = 'imported';

    /** Statuses the checker recomputes; the others are decisions or results it never touches. */
    public function isChecked(): bool
    {
        return in_array($this, [self::Valid, self::NeedsReview, self::Invalid], true);
    }
}
