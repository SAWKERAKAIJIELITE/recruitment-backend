<?php

namespace App\Modules\Recruitment\Enums;

use App\Modules\Recruitment\Models\ApplicationCategory;
use App\Modules\Recruitment\Models\District;
use App\Modules\Recruitment\Models\JobTitle;
use App\Modules\Recruitment\Models\SourceOffice;

/** The managed lists (the hidden "_Lists" sheet of the Excel template). The value is the URL segment. */
enum ReferenceType: string
{
    case SourceOffices = 'source-offices';
    case JobTitles = 'job-titles';
    case Governorates = 'governorates';
    case Categories = 'categories';

    /** @return class-string<\Illuminate\Database\Eloquent\Model> */
    public function model(): string
    {
        return match ($this) {
            self::SourceOffices => SourceOffice::class,
            self::JobTitles => JobTitle::class,
            self::Governorates => District::class,
            self::Categories => ApplicationCategory::class,
        };
    }
}
