<?php

namespace App\Modules\Recruitment\Support;

/**
 * A keyed hash that lets us find "the same national ID" without storing it readable.
 * The value cannot be recovered from the hash, and without the key the hash cannot be recomputed.
 *
 * The key comes from RECRUITMENT_BLIND_INDEX_KEY, or is derived from APP_KEY. If it changes, every
 * stored hash must be recomputed (the decrypted national IDs make that possible).
 */
final class BlindIndex
{
    public static function for(string $normalizedValue): string
    {
        return hash_hmac('sha256', $normalizedValue, self::key());
    }

    private static function key(): string
    {
        return config('recruitment.blind_index_key')
            ?: hash_hmac('sha256', 'recruitment.blind-index.v1', (string) config('app.key'));
    }
}
