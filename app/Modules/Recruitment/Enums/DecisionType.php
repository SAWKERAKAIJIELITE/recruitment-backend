<?php

namespace App\Modules\Recruitment\Enums;

/** Why a status change happened. The analysis doc requires the type of decision on every transition. */
enum DecisionType: string
{
    /** The application was opened (first status). */
    case Created = 'created';

    /** The status follows from a recorded result: an exam attempt, an interview, a contract record. */
    case Outcome = 'outcome';

    /** An authorized administrative exception (for example: failed the exams but is allowed to proceed). */
    case Exception = 'exception';

    /** An absence excuse was accepted, so a new appointment follows. */
    case ExcuseAccepted = 'excuse_accepted';

    /** Authorized staff ended the application (permission applications.close, D-037). */
    case Closure = 'closure';

    /** Decisions made by a person must carry a reason and an actor (analysis doc §07). */
    public function needsReasonAndActor(): bool
    {
        return in_array($this, [self::Exception, self::ExcuseAccepted, self::Closure], true);
    }
}
