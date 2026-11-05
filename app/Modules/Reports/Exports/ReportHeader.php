<?php

namespace App\Modules\Reports\Exports;

use App\Modules\Identity\Models\StaffUser;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * What every exported report carries (analysis doc §15 "متطلبات التصدير"): the organization lines, a title, the
 * period, when it was made, who made it and the filters applied. Page numbers are added by each format; totals come
 * from the same rows.
 */
final readonly class ReportHeader
{
    public CarbonInterface $generatedAt;

    /** @param array<string, string> $filters label => value, as applied */
    public function __construct(public string $title, public StaffUser $issuer, public array $filters = [], public ?string $period = null)
    {
        $this->generatedAt = Date::now();
    }

    public function organization(): string
    {
        return (string) config('reports.organization');
    }

    public function department(): string
    {
        return (string) config('reports.department');
    }

    /** The configured logo, when the file exists. */
    public function logo(): ?string
    {
        $path = config('reports.logo');

        if (! is_string($path) || $path === '') {
            return null;
        }

        $path = str_starts_with($path, '/') ? $path : base_path($path);

        return is_file($path) && is_readable($path) ? $path : null;
    }

    public function generatedAtText(): string
    {
        return $this->generatedAt->copy()->setTimezone(config('reports.timezone'))->format('Y-m-d H:i');
    }

    public function generatedLine(): string
    {
        return __('reports.generated', ['at' => $this->generatedAtText(), 'by' => $this->issuer->full_name], 'ar');
    }

    public function filtersText(): string
    {
        if ($this->filters === []) {
            return __('reports.no_filters', [], 'ar');
        }

        return collect($this->filters)->map(fn(string $value, string $label) => "{$label}: {$value}")->implode(' — ');
    }

    /** Period, date, issuer and filters, on one line. */
    public function metaLine(): string
    {
        return collect([
            $this->period === null ? null : __('reports.period', [], 'ar') . ': ' . $this->period,
            $this->generatedLine(),
            __('reports.filters', [], 'ar') . ': ' . $this->filtersText(),
        ])->filter()->implode('  ·  ');
    }
}
