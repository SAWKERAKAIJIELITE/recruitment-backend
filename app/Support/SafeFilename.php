<?php

namespace App\Support;

/**
 * A client's filename made safe to show and to use in a download header: no path, no control or reserved
 * characters, at most 255 characters. Never used to build a storage path (files are stored under generated names).
 */
final class SafeFilename
{
    public static function of(string $name, string $fallback = 'file'): string
    {
        $name = preg_replace('#^.*[/\\\\]#u', '', $name) ?? '';   // no basename(): it is locale-sensitive with Arabic names
        $name = trim(preg_replace('/[\x00-\x1F\x7F"<>|:*?]+/u', '', $name) ?? '');

        return mb_substr($name === '' ? $fallback : $name, 0, 255);
    }
}
