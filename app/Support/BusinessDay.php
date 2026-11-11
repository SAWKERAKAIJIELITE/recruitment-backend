<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * Days as the organization lives them (D-081): the days of `reports.timezone` (Asia/Damascus), not UTC days. From
 * midnight to 03:00 in Damascus the UTC day is still yesterday; a date typed by staff ("today", a writing date)
 * means the Damascus day.
 */
final class BusinessDay
{
    public static function timezone(): string
    {
        return (string) config('reports.timezone', 'UTC');
    }

    /** Today's date (YYYY-MM-DD) in the business time zone. */
    public static function today(): string
    {
        return Date::now(self::timezone())->toDateString();
    }

    /** Today as a date-only value, comparable with date columns (midnight, UTC, as their casts give them). */
    public static function todayDate(): CarbonInterface
    {
        return Date::parse(self::today(), 'UTC')->startOfDay();
    }

    public static function isToday(CarbonInterface|string $day): bool
    {
        return Date::parse($day instanceof CarbonInterface ? $day->toDateString() : $day)->toDateString() === self::today();
    }

    /** The first instant of a business day, in UTC: a list's `from` (D-093). */
    public static function startOf(CarbonInterface|string $day): CarbonInterface
    {
        $date = $day instanceof CarbonInterface ? $day->toDateString() : $day;

        return Date::parse($date, self::timezone())->startOfDay()->utc();
    }

    /** The first instant of the next business day, in UTC: a list's `to`, included, is before it (D-093). */
    public static function startOfNext(CarbonInterface|string $day): CarbonInterface
    {
        return self::startOf(Date::parse($day instanceof CarbonInterface ? $day->toDateString() : $day)->addDay()->toDateString());
    }

    /** The last instant of a business day, in UTC (a decision dated that day, placed after anything else that day). */
    public static function endOf(CarbonInterface|string $day): CarbonInterface
    {
        $date = $day instanceof CarbonInterface ? $day->toDateString() : $day;

        return Date::parse($date, self::timezone())->endOfDay()->utc();
    }
}
