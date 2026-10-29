<?php

namespace App\Modules\Documents\Database\Seeders;

use App\Modules\Documents\Models\DocumentType;
use App\Support\ArabicText;
use Illuminate\Database\Seeder;

/**
 * Default document types (D-057), every environment. Matched by normalized name, archived ones included, so running
 * again adds only what is missing and never overwrites staff changes.
 */
class DocumentTypesSeeder extends Seeder
{
    /** name => [scope, direction, required from status] (the owner's examples). */
    public const REQUESTED = [
        'صورة البطاقة الشخصية' => ['candidate', 'incoming', 'awaiting_exam'],
        'الشهادات العلمية' => ['candidate', 'incoming', 'awaiting_exam'],
        'السيرة الذاتية' => ['candidate', 'incoming', null],
        'وثائق رسمية' => ['candidate', 'incoming', null],
        'العقد' => ['application', 'outgoing', 'contract_written'],
    ];

    /** Suggested additions: remove the ones that do not apply. */
    public const SUGGESTED = [
        'صورة شخصية' => ['candidate', 'incoming', null],
        'كتاب الترشيح' => ['application', 'incoming', null],
        'قرار الاستثناء' => ['application', 'outgoing', null],
    ];

    public function run(): void
    {
        foreach ([...self::REQUESTED, ...self::SUGGESTED] as $name => [$scope, $direction, $requiredFrom]) {
            DocumentType::withTrashed()->firstOrCreate(
                ['name_key' => ArabicText::searchKey($name)],
                ['name_ar' => $name, 'scope' => $scope, 'direction' => $direction, 'required_from_status' => $requiredFrom],
            );
        }
    }
}
