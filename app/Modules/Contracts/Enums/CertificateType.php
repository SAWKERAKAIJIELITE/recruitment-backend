<?php

namespace App\Modules\Contracts\Enums;

/** نوع الشهادة, as the list of the owner's ملف التثبيت (D-098). */
enum CertificateType: string
{
    case Preparatory = 'preparatory';
    case Secondary = 'secondary';
    case Institute = 'institute';
    case Bachelor4 = 'bachelor_4';
    case Bachelor5 = 'bachelor_5';
    case Bachelor6 = 'bachelor_6';
    case Master = 'master';
    case Doctorate = 'doctorate';

    public function labelAr(): string
    {
        return match ($this) {
            self::Preparatory => 'شهادة إعدادية',
            self::Secondary => 'شهادة ثانوية',
            self::Institute => 'معهد متوسط',
            self::Bachelor4 => 'إجازة جامعية 4 سنوات',
            self::Bachelor5 => 'إجازة جامعية 5 سنوات',
            self::Bachelor6 => 'إجازة جامعية 6 سنوات',
            self::Master => 'ماجستير',
            self::Doctorate => 'دكتوراه',
        };
    }
}
