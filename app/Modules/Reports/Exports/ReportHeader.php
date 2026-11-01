<?php

namespace App\Modules\Reports\Exports;

use App\Modules\Identity\Models\StaffUser;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * What every exported report carries (analysis doc §15 "متطلبات التصدير"): a title, when it was made, who made it and
 * the filters applied. Page numbers are added by each format; totals come from the same rows.
 */
final readonly class ReportHeader
{
    public CarbonInterface $generatedAt;

    /** @param array<string, string> $filters label => value, as applied */
    public function __construct(public string $title, public StaffUser $issuer, public array $filters = [])
    {
        $this->generatedAt = Date::now();
    }

    public function organization(): string
    {
        return (string) config('reports.organization');
    }

    public function generatedAtText(): string
    {
        return $this->generatedAt->copy()->setTimezone(config('reports.timezone'))->format('Y-m-d H:i');
    }
}
