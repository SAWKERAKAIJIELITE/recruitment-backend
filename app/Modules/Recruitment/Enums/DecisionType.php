<?php

namespace App\Modules\Recruitment\Enums;

/** Why a status change happened. The analysis doc requires the type of decision on every transition. */
enum DecisionType: string
{
    /** The application was opened (first status). */
    case Created = 'created';

    /** The status follows from a recorded result: an exam attempt, an interview, a contract record. */
    case Outcome = 'outcome';

    /** An authorised administrative exception (for example: failed the exams but is allowed to proceed). */
    case Exception = 'exception';

    /** An absence excuse was accepted, so a new appointment follows. */
    case ExcuseAccepted = 'excuse_accepted';

    /** Decisions made by a person must carry a reason and an actor (analysis doc §07). */
    public function needsReasonAndActor(): bool
    {
        return $this === self::Exception || $this === self::ExcuseAccepted;
    }
}
