<?php

namespace App\Modules\Contracts\Exports;

use App\Modules\Contracts\Models\Contract;
use App\Modules\Recruitment\Models\Application;
use App\Support\BusinessDay;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;

/**
 * The contract form (تعاقد عمل), filled (D-098): the owner's template, one A4 page, with the contract's details, the
 * person's and the application's. The signatures and the photo are left for the paper. Dated the writing day, in the
 * Hijri (Umm al-Qura) and the Gregorian calendars.
 *
 *  - الجهة (the header): the issuing body (config contracts.issuing_unit);
 *  - الرقم الذاتي: the application's employee number; رقم الاستمارة: the contract's reference number;
 *  - السوية التنظيمية الثانية: the unit joined; the third to the fifth levels as entered;
 *  - الشهادة العلمية: the certificate type entered (else the academic qualification); المرحلة الدراسية: the academic
 *    qualification; السنة التي أتمها: the studying years; سنة التخرج: the certificate's year;
 *  - التولد: the place and date of birth; البلد الأصلي: as entered, else the place of birth; السكن الحالي: the
 *    residence (district, area, details).
 */
final class ContractForm
{
    public const RELATIONS = [
        'unit', 'application.candidate.birthPlace', 'application.jobPosition', 'application.academicQualification',
        'application.specialization', 'application.residenceDistrict', 'application.residenceArea',
    ];

    /** The conditions printed on the template (الشروط), as written there. */
    public const CONDITIONS = [
        'أن يكون الفريق الثاني غير مرتبط مع أي جهة أو عمل آخر بتاريخ تنظيم هذا العقد.',
        'لا يحق للفريق الثاني أن يطالب بأي مساعدات مالية أو عينية أو تعويضات أو مصاريف أو أجور غير المتفق عليه.',
        'في حال رغبة الفريق الثاني في إنهاء العمل بأحكام هذا العقد يجب عليه إبلاغ الفريق الأول خطيا قبل مدة لا تقل عن شهر.',
        'في حال تركه العمل بدون إذن يحرم من جميع حقوقه المالية.',
        'في حال رغبة الفريق الأول في إنهاء العمل بأحكام هذا العقد يجب عليه إبلاغ الفريق الثاني قبل مدة لا تقل عن شهر.',
    ];

    /** @return string the PDF */
    public static function render(Contract $contract): string
    {
        $contract->loadMissing(self::RELATIONS);
        $tempDir = storage_path('framework/cache/mpdf');
        File::ensureDirectoryExists($tempDir);

        $pdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'default_font' => 'xbriyaz',
            'directionality' => 'rtl',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'tempDir' => $tempDir,
            'margin_top' => 9,
            'margin_bottom' => 8,
            'margin_left' => 9,
            'margin_right' => 9,
        ]);

        $pdf->SetDirectionality('rtl');   // mPDF ignores the 'directionality' option
        $pdf->SetTitle(__('contracts.form_title', [], 'ar') . ' ' . $contract->reference_number);
        $pdf->WriteHTML(view('contracts.form', self::values($contract) + ['emblem' => resource_path('images/emblem.png')])->render());

        return $pdf->Output('', 'S');
    }

    /** @return array<string, mixed> what the form shows */
    public static function values(Contract $contract): array
    {
        $application = $contract->application;
        $candidate = $application->candidate;
        $birthPlace = $candidate->birthPlace?->name_ar;

        return [
            'contract' => $contract,
            'issuer' => (string) config('contracts.issuing_unit'),
            'employeeNumber' => $application->existing_employee_number,
            'formNumber' => $contract->reference_number,
            'fullName' => $candidate->full_name,
            'motherName' => $candidate->mother_name,
            'right' => [
                ['السوية التنظيمية الثانية', $contract->unit?->name_ar],
                ['السوية التنظيمية الثالثة', $contract->level_3],
                ['السوية التنظيمية الرابعة', $contract->level_4],
                ['السوية التنظيمية الخامسة', $contract->level_5],
                ['مسمى العمل', $application->jobPosition?->name_ar],
                ['الرمز الوظيفي', $application->jobPosition?->job_code],
                ['عدد الزوجات', $contract->wives_count],
                ['عدد الأبناء', $contract->children_count],
                ['التولد', trim(($birthPlace ?? '') . ' ' . $candidate->birth_date->format('Y/m/d'))],
                ['رقم التواصل', $application->phone_number],
            ],
            'left' => [
                ['الرقم الوطني', $candidate->national_number],
                ['الشهادة العلمية', $contract->certificate_type?->labelAr() ?? $application->academicQualification?->name_ar],
                ['المرحلة الدراسية', $application->academicQualification?->name_ar],
                ['الاختصاص', $application->specialization?->name_ar],
                ['السنة التي أتمها', self::studyingYears($application)],
                ['سنة التخرج', $contract->certificate_date?->format('Y')],
                ['قيمة الراتب بالدولار', self::amount($contract->salary_usd)],
                ['نظام الدوام', $contract->work_schedule],
                ['البلد الأصلي', $contract->origin ?? $birthPlace],
                ['السكن الحالي', self::residence($application)],
            ],
            'startsOn' => $contract->starts_on->format('d/m/Y'),
            'endsOn' => $contract->ends_on?->format('d/m/Y'),
            'duration' => self::duration($contract->starts_on, $contract->ends_on),
            'hijri' => self::hijri($contract->written_on),
            'gregorian' => $contract->written_on->format('d/m/Y'),
            'conditions' => self::CONDITIONS,
        ];
    }

    /** The day in the Umm al-Qura calendar, d/m/y (ASCII digits). */
    public static function hijri(CarbonInterface $day): string
    {
        $formatter = new \IntlDateFormatter('en_US@calendar=islamic-umalqura', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, BusinessDay::timezone(), \IntlDateFormatter::TRADITIONAL, 'dd/MM/y');

        return (string) $formatter->format(new \DateTimeImmutable($day->toDateString() . ' 12:00:00', new \DateTimeZone(BusinessDay::timezone())));
    }

    /** From the first day to the last, both included: "سنة واحدة"، "6 أشهر و15 يومًا"; without an end: "غير محددة". */
    public static function duration(CarbonInterface $startsOn, ?CarbonInterface $endsOn): string
    {
        if ($endsOn === null) {
            return 'غير محددة';
        }

        $interval = $startsOn->copy()->startOfDay()->diff($endsOn->copy()->startOfDay()->addDay());
        $parts = array_filter([
            self::count($interval->y, ['سنة واحدة', 'سنتان', 'سنوات', 'سنة']),
            self::count($interval->m, ['شهر واحد', 'شهران', 'أشهر', 'شهرًا']),
            self::count($interval->d, ['يوم واحد', 'يومان', 'أيام', 'يومًا']),
        ]);

        return $parts === [] ? 'يوم واحد' : implode(' و', $parts);
    }

    /** @param array{0: string, 1: string, 2: string, 3: string} $words one, two, 3 to 10, 11 and more */
    private static function count(int $number, array $words): ?string
    {
        return match (true) {
            $number <= 0 => null,
            $number === 1 => $words[0],
            $number === 2 => $words[1],
            $number <= 10 => "{$number} {$words[2]}",
            default => "{$number} {$words[3]}",
        };
    }

    private static function studyingYears(Application $application): ?string
    {
        if ($application->studying_years === null) {
            return null;
        }

        return $application->studies_discontinued ? __('reports.discontinued', ['years' => $application->studying_years], 'ar') : (string) $application->studying_years;
    }

    private static function residence(Application $application): ?string
    {
        $parts = array_filter([$application->residenceDistrict?->name_ar, $application->residenceArea?->name_ar, $application->residence_details], fn(?string $part) => $part !== null && trim($part) !== '');

        return $parts === [] ? null : implode(' - ', array_map('trim', $parts));
    }

    /** 350, or 350.50. */
    public static function amount(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (float) $value;

        return floor($value) === $value ? number_format($value, 0, '.', ',') : number_format($value, 2, '.', ',');
    }
}
