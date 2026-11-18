<?php

namespace App\Modules\Audit\Support;

/**
 * Every event the audit log records, with its Arabic label (D-099), for the audit screen: the list shows the label,
 * and the event filter offers the catalogue. A test checks that every event recorded in the code is here.
 */
final class AuditEvents
{
    public const LABELS = [
        // Access, devices, logins (security).
        'access.user_created' => 'إضافة مستخدم',
        'access.user_activated' => 'تفعيل حساب مستخدم',
        'access.user_deactivated' => 'إيقاف حساب مستخدم',
        'access.password_reset' => 'إعادة تعيين كلمة مرور مستخدم',
        'access.role_changed' => 'تغيير دور مستخدم',
        'access.role_permissions_added' => 'إضافة صلاحيات إلى دور',
        'access.role_permission_removed' => 'سحب صلاحية من دور',
        'device.approved' => 'اعتماد جهاز',
        'device.revoked' => 'إلغاء اعتماد جهاز',
        'auth.staff_login' => 'تسجيل دخول موظف',
        'exam.candidate_login' => 'دخول مرشح إلى الاختبار',
        // Recruitment.
        'application.updated' => 'تصحيح بيانات طلب',
        'candidate.updated' => 'تصحيح بيانات مرشح',
        'candidate.deactivated' => 'إيقاف مرشح',
        'candidate.activated' => 'إعادة تفعيل مرشح',
        'import.uploaded' => 'رفع ملف استيراد',
        'import.row_corrected' => 'تصحيح سطر في ملف استيراد',
        'import.person_confirmed' => 'تأكيد هوية شخص في ملف استيراد',
        'import.row_excluded' => 'استبعاد سطر من ملف استيراد',
        'import.row_included' => 'إعادة سطر مستبعد إلى ملف استيراد',
        'import.cancelled' => 'إلغاء ملف استيراد',
        'import.approved' => 'اعتماد ملف استيراد',
        'report.results_statement' => 'إصدار بيان نتائج مرشح',
        'report.results_list' => 'إصدار قائمة النتائج',
        'report.conscription_list' => 'إصدار قائمة شعبة التجنيد',
        'report.exported' => 'تصدير تقرير',
        'report.workbook' => 'تصدير ملف التقارير الشامل',
        // Exams.
        'exam_subject.created' => 'إضافة مادة اختبار',
        'exam_subject.renamed' => 'تعديل اسم مادة اختبار',
        'exam_subject.archived' => 'أرشفة مادة اختبار',
        'exam_subject.restored' => 'استعادة مادة اختبار',
        'exam_subject_part.created' => 'إضافة جزء إلى مادة',
        'exam_subject_part.updated' => 'تعديل جزء من مادة',
        'exam_subject_part.archived' => 'أرشفة جزء من مادة',
        'exam_subject_part.restored' => 'استعادة جزء من مادة',
        'exam_question.created' => 'إضافة سؤال إلى البنك',
        'exam_question.revised' => 'تعديل سؤال',
        'exam_question.archived' => 'أرشفة سؤال',
        'exam_question.restored' => 'استعادة سؤال',
        'exam_plan.draft_created' => 'إنشاء مسودة خطة اختبار',
        'exam_plan.draft_updated' => 'تعديل مسودة خطة اختبار',
        'exam_plan.draft_deleted' => 'حذف مسودة خطة اختبار',
        'exam_plan.published' => 'نشر خطة اختبار',
        'exam_pass_policy.added' => 'إضافة إصدار من قواعد النجاح',
        'exam_session.created' => 'إنشاء جلسة اختبار',
        'exam_session.updated' => 'تعديل جلسة اختبار',
        'exam_session.deleted' => 'حذف جلسة اختبار',
        'exam_session.redrawn' => 'إعادة سحب أسئلة جلسة اختبار',
        'exam_session.extended' => 'تمديد وقت جلسة اختبار',
        'exam_booking.created' => 'حجز مرشح في جلسة اختبار',
        'exam_booking.cancelled' => 'إلغاء حجز في جلسة اختبار',
        'exam_booking.absent' => 'تسجيل غياب عن الاختبار',
        'exam_attempt.started' => 'بدء محاولة اختبار',
        'exam_attempt.extended' => 'تمديد وقت محاولة اختبار',
        'exam_attempt.closed' => 'إغلاق محاولة اختبار',
        'exam_marks.entered' => 'إدخال علامات يدوية',
        'exam_marks.corrected' => 'تصحيح علامات يدوية',
        'exam_result.decided' => 'صدور نتيجة اختبار',
        'exam_result.revised' => 'تعديل نتيجة اختبار',
        'exam.exception_approved' => 'استثناء من الرسوب في الاختبار',
        'exam.excuse_accepted' => 'قبول عذر الغياب عن الاختبار',
        'exam_hall.downloaded' => 'إخراج جلسة اختبار إلى قاعة بلا إنترنت',
        'exam_hall.uploaded' => 'إعادة نتائج جلسة اختبار من القاعة',
        'exam_hall.cancelled' => 'إلغاء إخراج جلسة اختبار إلى القاعة',
        // Interviews.
        'interview_sitting.created' => 'إنشاء جلسة مقابلات',
        'interview_sitting.updated' => 'تعديل جلسة مقابلات',
        'interview_sitting.deleted' => 'حذف جلسة مقابلات',
        'interview.scheduled' => 'حجز موعد مقابلة',
        'interview.rescheduled' => 'تغيير موعد مقابلة',
        'interview.cancelled' => 'إلغاء مقابلة',
        'interview.recorded' => 'تسجيل نتيجة مقابلة',
        'interview.corrected' => 'تصحيح نتيجة مقابلة',
        'interview.excuse_accepted' => 'قبول عذر الغياب عن المقابلة',
        // Contracts.
        'contract.written' => 'كتابة عقد أول',
        'contract.renewed' => 'تجديد عقد',
        'contract.corrected' => 'تصحيح عقد',
        'contract.ended' => 'إنهاء عقد دون تجديد',
        'contract.form_printed' => 'طباعة استمارة تعاقد',
        'contract.confirmation_file' => 'تصدير ملف التثبيت',
        'contract.job_code_set' => 'تعديل الرمز الوظيفي لمسمى',
        'vacancy_plan.uploaded' => 'رفع ملف الشواغر',
        'vacancy_plan.replaced' => 'استبدال ملف الشواغر',
        // Documents.
        'document.uploaded' => 'رفع وثيقة',
        'document.downloaded' => 'تنزيل وثيقة',
        'document.archived' => 'أرشفة وثيقة',
        'document_type.created' => 'إضافة نوع وثيقة',
        'document_type.updated' => 'تعديل نوع وثيقة',
        'document_type.archived' => 'أرشفة نوع وثيقة',
        'document_type.restored' => 'استعادة نوع وثيقة',
    ];

    /** The event's Arabic label; the event itself when it has none (an event recorded before it was named). */
    public static function label(string $event): string
    {
        return self::LABELS[$event] ?? $event;
    }
}
