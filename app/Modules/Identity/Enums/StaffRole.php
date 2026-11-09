<?php

namespace App\Modules\Identity\Enums;

/**
 * The roles a staff user can hold, one each (D-085): the four offices, the general manager and the system
 * administrator. Each office has full control of its own section only; the general manager only views; the system
 * administrator holds every permission.
 */
enum StaffRole: string
{
    case Recruitment = 'recruitment';
    case ExamsInterviews = 'exams_interviews';
    case Contracts = 'contracts';
    case Documenting = 'documenting';
    case GeneralManager = 'general_manager';
    case SystemAdmin = 'system_admin';

    public function labelAr(): string
    {
        return match ($this) {
            self::Recruitment => 'مكتب التوظيف',
            self::ExamsInterviews => 'مكتب الاختبارات والمقابلات',
            self::Contracts => 'مكتب العقود',
            self::Documenting => 'مكتب التوثيق',
            self::GeneralManager => 'المدير العام',
            self::SystemAdmin => 'مسؤول النظام',
        };
    }

    /** One of the four offices (not the general manager or the administrator). */
    public function isOffice(): bool
    {
        return ! in_array($this, [self::GeneralManager, self::SystemAdmin], true);
    }
}
