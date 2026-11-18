<?php

namespace App\Modules\Exams\Support;

/**
 * The receipt a candidate sees on their phone when they hand in a hall exam (D-102), for example «4F2A-9C11-B07E».
 * The laptop computes it from that candidate's final answers; the server computes it again from what comes back and
 * refuses a mismatch. If answers were changed after the hand-in, the code the candidate kept no longer matches.
 *
 * The text hashed (SHA-256, UTF-8, lines joined by "\n", no final newline):
 *
 *     recruitment-hall-receipt-v1
 *     <booking id>
 *     <hand-in time, Unix seconds>
 *     <position>:<session question id>:<chosen option, from 0, or "-" when none>     one line per question, by position
 *
 * The code: the hash's first 12 hexadecimal digits, upper case, in three groups of four joined by "-".
 */
final class HallReceipt
{
    public const PREFIX = 'recruitment-hall-receipt-v1';

    /** @param list<array{position: int, session_question_id: int, option: ?int}> $items in position order */
    public static function code(string $bookingId, int $submittedAt, array $items): string
    {
        $lines = [self::PREFIX, $bookingId, (string) $submittedAt];

        foreach ($items as $item) {
            $lines[] = $item['position'] . ':' . $item['session_question_id'] . ':' . ($item['option'] ?? '-');
        }

        return implode('-', str_split(strtoupper(substr(hash('sha256', implode("\n", $lines)), 0, 12)), 4));
    }

    /** As the moderator may have typed it: case and separators do not matter. */
    public static function normalize(string $code): string
    {
        $hex = strtoupper((string) preg_replace('/[^0-9A-Fa-f]/', '', $code));

        return strlen($hex) === 12 ? implode('-', str_split($hex, 4)) : $hex;
    }
}
