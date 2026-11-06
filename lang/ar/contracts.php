<?php

return [
    'not_awaiting_contract' => 'لا يُكتب العقد الأول إلا لطلب ناجح بالمقابلة بانتظار كتابة العقد (حالته الآن «:status»).',
    'application_not_under_contract' => 'الطلب ليس تحت عقد (حالته «:status»).',
    'written_before_previous_decision' => 'تاريخ كتابة العقد يسبق القرار السابق في سجل الطلب.',
    'ended_before_previous_decision' => 'تاريخ إنهاء العقد يسبق القرار السابق في سجل الطلب.',
    'ended_before_written' => 'لا يُنهى العقد قبل تاريخ كتابته (:date).',
    'not_latest' => 'هذا العقد جُدِّد أو أُنهي: الإجراء على آخر عقد في السلسلة فقط.',
    'renew_without_end' => 'عقد بلا تاريخ انتهاء لا يُجدَّد: صحّح تاريخ انتهائه أولاً.',
    'renewal_starts_before_end' => 'يبدأ التجديد في تاريخ انتهاء العقد السابق (:date) أو بعده.',
    'ends_after_renewal' => 'العقد المجدَّد ينتهي قبل بدء تجديده (:date) أو فيه.',
    'written_after_end' => 'تاريخ كتابة العقد بعد تاريخ إنهائه (:date).',
    'written_before_decision' => 'تاريخ كتابة العقد قبل نجاح المقابلة (:date).',
    'ends_before_start' => 'تاريخ انتهاء العقد يسبق تاريخ بدئه.',
    'reference_taken' => 'رقم المرجع هذا مسجل لعقد آخر.',
    'document_not_of_application' => 'الملف المرفق يجب أن يكون وثيقة غير مؤرشفة لهذا الطلب.',
    'document_taken' => 'هذا الملف مرفق بعقد آخر.',
    'nothing_to_correct' => 'لم يتغير أي حقل.',
    'ending_soon_title' => 'عقد ينتهي قريباً',
    'ending_soon_message' => 'عقد :name (رقم الترشيح :nomination، المرجع :reference) ينتهي في :date، بعد :days يوماً، ولم يُجدَّد بعد.',
];
