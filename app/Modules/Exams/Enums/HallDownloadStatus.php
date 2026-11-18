<?php

namespace App\Modules\Exams\Enums;

/** Where a session taken out to a hall with no internet stands (D-102). */
enum HallDownloadStatus: string
{
    case Out = 'out';                 // on the moderator's laptop: the session is frozen on the server
    case Returned = 'returned';       // brought back: the attempts and absences are recorded
    case Cancelled = 'cancelled';     // by staff, with a reason (the laptop lost, or the session must change)
    case Replaced = 'replaced';       // taken out again before the start: this copy no longer counts

    public function labelAr(): string
    {
        return match ($this) {
            self::Out => 'في القاعة',
            self::Returned => 'أُعيدت نتائجها',
            self::Cancelled => 'أُلغي إخراجها',
            self::Replaced => 'استُبدل بإخراج أحدث',
        };
    }
}
