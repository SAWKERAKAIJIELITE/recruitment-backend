<?php

return [
    'live_application_exists' => 'لدى هذا الشخص طلب ترشيح قائم (رقم الترشيح :number). لا يُفتح طلب جديد قبل إغلاقه أو انتهاء عقده.',
    'name_taken' => 'يوجد عنصر بهذا الاسم مسبقاً.',
    'name_archived' => 'يوجد عنصر مؤرشف بهذا الاسم: استعده بدلاً من إضافة عنصر جديد.',
    'area_not_in_district' => 'المنطقة المختارة لا تتبع المحافظة المختارة.',
    'illegal_transition' => 'لا يمكن تنفيذ هذا الإجراء على طلب حالته «:status».',
    'changed_meanwhile' => 'غيّر مستخدم آخر بيانات هذا الشخص للتو: أعد المحاولة.',
    'national_number_taken' => 'هذا الرقم الوطني مسجل لشخص آخر.',
    'candidate_inactive' => 'هذا الشخص معطَّل ولا يمكن ترشيحه.',
    'candidate_already_inactive' => 'هذا الشخص معطَّل مسبقاً.',
    'candidate_already_active' => 'هذا الشخص فعّال مسبقاً.',
    'candidate_has_live_application' => 'لا يمكن تعطيل شخص لديه طلب ترشيح قائم (رقم الترشيح :number): أغلق الطلب أولاً.',
    'application_ended' => 'لا يُعدَّل طلب منتهٍ (حالته «:status»).',
    'position_after_contract' => 'لا يتغير المسمى الوظيفي لطلب كُتب له عقد: استُخدم به شاغر هذا المسمى.',
    'not_received' => 'لا يبدأ إلا طلب مستلم: هذه الطلبات ليست كذلك (:numbers).',
    'start_too_many' => 'تطابق المرشحات :count طلبًا مستلمًا، والحد :max في المرة الواحدة: ضيّق المرشحات.',
    'start_needs_one' => 'أرسل application_ids أو filters، وليس كليهما.',
    'start_nothing' => 'لا يوجد طلب مستلم يطابق المرشحات.',
    'identity_only_filter' => 'هذا المرشح غير متاح لمكتبك: يرى مكتب التوظيف وحده ملف المرشح كاملًا.',
    // Recruitment's calls about the appointments (D-105).
    'calls' => [
        'no_appointment' => 'ليس لهذا الطلب موعد قادم (اختبار محجوز أو مقابلة مجدولة) يُبلَّغ به المرشح.',
    ],
    // Lifecycle notifications (D-089), stored in Arabic.
    'moved_title_one' => 'تحديث طلب ترشيح',
    'moved_title_many' => 'تحديث :count طلب ترشيح',
    'moved_one' => 'طلب :name (رقم الترشيح :nomination) صار «:to».',
    'moved_many' => ':count طلبًا صارت «:to».',
    'moved_by' => ' بواسطة :actor.',
];
