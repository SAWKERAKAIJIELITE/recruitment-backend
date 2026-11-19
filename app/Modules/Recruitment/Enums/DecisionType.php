<?php

namespace App\Modules\Recruitment\Enums;

/** Why a status change happened. The analysis doc requires the type of decision on every transition. */
enum DecisionType: string
{
    /** The application was opened (first status: received). */
    case Created = 'created';

    /** Recruitment started a received application: it now awaits its exam (D-087). Needs the actor, not a reason. */
    case Started = 'started';

    /** The status follows from a recorded result: an exam attempt, an interview, a contract record. */
    case Outcome = 'outcome';

    /** An authorized administrative exception (for example: failed the exams but is allowed to proceed). */
    case Exception = 'exception';

    /** An absence excuse was accepted, so a new appointment follows. */
    case ExcuseAccepted = 'excuse_accepted';

    /** Authorized staff ended the application (permission applications.close, D-037). */
    case Closure = 'closure';

    /**
     * The record behind the latest outcome was corrected (a data-entry error, analysis doc §07), so the outcome is
     * replaced by the one the corrected record gives. Only the latest decision can be corrected (D-059).
     */
    case Correction = 'correction';

    /**
     * The exams office failed a candidate who passed, when it approved the session's results (ترسيب بقرار, D-104): a
     * reason of a type (cheating, other) and an optional decision number. The computed result stays a pass.
     */
    case FailedByDecision = 'failed_by_decision';

    /** Decisions made by a person must carry a reason and an actor (analysis doc §07). */
    public function needsReasonAndActor(): bool
    {
        return in_array($this, [self::Exception, self::ExcuseAccepted, self::Closure, self::Correction, self::FailedByDecision], true);
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Created => 'إنشاء الطلب',
            self::Started => 'بدء دورة الطلب',
            self::Outcome => 'نتيجة',
            self::Exception => 'استثناء',
            self::ExcuseAccepted => 'قبول عذر',
            self::Closure => 'إغلاق',
            self::Correction => 'تصحيح',
            self::FailedByDecision => 'ترسيب بقرار',
        };
    }
}
