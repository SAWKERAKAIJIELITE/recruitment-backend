<?php

namespace App\Modules\Reports\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\Validator;

/**
 * The period a report counts (analysis doc §16: "تعريف مرجع التاريخ لكل تقرير"; legacy dashboard: month and year).
 * `year` (with an optional `month`), or `period_from` / `period_to` (YYYY-MM-DD, both included), or both together;
 * nothing: all time. Days are the report time zone's (config reports.timezone), so a decision taken at 01:00 in
 * Damascus counts on that day, not on the previous one in UTC.
 *
 * Each report says which date it compares: a nomination by its date, an exam decision by its date, an interview by
 * the day it was held, a contract by its writing date. So a candidate absent in March who passed in May counts as
 * absent in March and as passed in May (AC-13).
 */
final readonly class ReportPeriod
{
    /** The longest run of months the monthly series covers. */
    public const MAX_MONTHS = 60;

    private function __construct(public ?CarbonImmutable $start, public ?CarbonImmutable $end) {}

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100', 'required_with:month'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'period_from' => ['nullable', 'date_format:Y-m-d'],
            'period_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:period_from'],
        ];
    }

    public static function keys(): array
    {
        return array_keys(self::rules());
    }

    /** @param array<string, mixed> $filters validated with rules() */
    public static function from(array $filters): self
    {
        $tz = (string) config('reports.timezone');
        $start = $end = null;

        if (isset($filters['year'])) {
            $start = CarbonImmutable::create((int) $filters['year'], (int) ($filters['month'] ?? 1), 1, 0, 0, 0, $tz);
            $end = isset($filters['month']) ? $start->addMonth() : $start->addYear();
        }

        if (isset($filters['period_from'])) {
            $from = CarbonImmutable::parse($filters['period_from'], $tz)->startOfDay();
            $start = $start === null ? $from : $start->max($from);
        }

        if (isset($filters['period_to'])) {
            $to = CarbonImmutable::parse($filters['period_to'], $tz)->startOfDay()->addDay();
            $end = $end === null ? $to : $end->min($to);
        }

        return new self($start, $end);
    }

    /** The year and the dates given together must overlap. */
    public static function after(Validator $validator, array $filters): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $period = self::from($filters);

        if ($period->start !== null && $period->end !== null && $period->start->greaterThanOrEqualTo($period->end)) {
            $validator->errors()->add('period_from', __('reports.empty_period'));
        }
    }

    public function isAllTime(): bool
    {
        return $this->start === null && $this->end === null;
    }

    /** An instant within the period. */
    public function contains(?CarbonInterface $moment): bool
    {
        if ($moment === null) {
            return false;
        }

        return ($this->start === null || $moment->greaterThanOrEqualTo($this->start))
            && ($this->end === null || $moment->lessThan($this->end));
    }

    /** A calendar date (a contract's writing date) within the period, compared as a day of the report time zone. */
    public function containsDate(?CarbonInterface $date): bool
    {
        return $date !== null && $this->contains(CarbonImmutable::parse($date->toDateString(), (string) config('reports.timezone')));
    }

    /** The months (YYYY-MM, report time zone) the monthly series shows: the period, or $earliest up to this month. */
    public function months(?CarbonInterface $earliest): array
    {
        $tz = (string) config('reports.timezone');
        $first = ($this->start ?? ($earliest === null ? CarbonImmutable::now($tz) : CarbonImmutable::parse($earliest)->setTimezone($tz)))->startOfMonth();
        $last = ($this->end?->subSecond() ?? CarbonImmutable::now($tz))->setTimezone($tz)->startOfMonth();

        $months = [];

        for ($month = $first; $month->lessThanOrEqualTo($last); $month = $month->addMonth()) {
            $months[] = $month->format('Y-m');
        }

        return array_slice($months, -self::MAX_MONTHS);
    }

    /** The month (YYYY-MM, report time zone) of an instant. */
    public static function monthOf(CarbonInterface $moment): string
    {
        return CarbonImmutable::parse($moment)->setTimezone((string) config('reports.timezone'))->format('Y-m');
    }

    /** Readable, for a report header: "2026-03-01 — 2026-03-31", or "all time". */
    public function describe(): string
    {
        if ($this->isAllTime()) {
            return __('reports.all_time', [], 'ar');
        }

        return ($this->start?->toDateString() ?? '…') . ' — ' . ($this->end?->subDay()->toDateString() ?? '…');
    }

    /** @return array{from: ?string, to: ?string} the dates included, for the API */
    public function toArray(): array
    {
        return ['from' => $this->start?->toDateString(), 'to' => $this->end?->subDay()->toDateString()];
    }
}
