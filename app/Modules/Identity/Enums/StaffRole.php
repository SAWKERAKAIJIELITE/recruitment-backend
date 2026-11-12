<?php

namespace App\Modules\Identity\Enums;

/**
 * The roles a staff user can hold, one each (D-085): the roles of the four offices, the general manager and the
 * system administrator. Each office has full control of its own section only; the general manager only views; the
 * system administrator holds every permission. The Exams and Interviews office has three roles (D-097): both its
 * sections, or the exams only, or the interviews only.
 */
enum StaffRole: string
{
    case Recruitment = 'recruitment';
    case ExamsInterviews = 'exams_interviews';
    case Exams = 'exams';
    case Interviews = 'interviews';
    case Contracts = 'contracts';
    case Documenting = 'documenting';
    case GeneralManager = 'general_manager';
    case SystemAdmin = 'system_admin';

    public function labelAr(): string
    {
        return match ($this) {
            self::Recruitment => 'مكتب التوظيف',
            self::ExamsInterviews => 'مكتب الاختبارات والمقابلات',
            self::Exams => 'موظف الاختبارات',
            self::Interviews => 'موظف المقابلات',
            self::Contracts => 'مكتب العقود',
            self::Documenting => 'مكتب التوثيق',
            self::GeneralManager => 'المدير العام',
            self::SystemAdmin => 'مسؤول النظام',
        };
    }

    /** A role of one of the four offices (not the general manager or the administrator). */
    public function isOffice(): bool
    {
        return ! in_array($this, [self::GeneralManager, self::SystemAdmin], true);
    }

    /** The office the role works in: the exams only and the interviews only are of the Exams and Interviews office. */
    public function office(): self
    {
        return match ($this) {
            self::Exams, self::Interviews => self::ExamsInterviews,
            default => $this,
        };
    }

    /** @return list<self> the roles of the four offices */
    public static function offices(): array
    {
        return array_values(array_filter(self::cases(), fn(self $role) => $role->isOffice()));
    }
}
