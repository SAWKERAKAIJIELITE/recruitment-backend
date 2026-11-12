<?php

// Access management messages (D-085).
return [
    'password_reset' => 'غُيّرت كلمة المرور وأُنهيت كل جلسات المستخدم: أبلغه بكلمة المرور الجديدة.',
    'administrator_holds_all' => 'مسؤول النظام يملك كل الصلاحيات دائمًا: لا تُعدَّل صلاحيات دوره.',
    'manager_views_only' => 'دور المدير العام للعرض فقط: لا يُمنح صلاحيات تعديل (:permissions).',
    // Devices (Step 15: these were English).
    'device_not_pending' => 'لا يُعتمد إلا جهاز بانتظار الاعتماد.',
    'device_owner_inactive' => 'صاحب الجهاز يجب أن يكون مستخدمًا فعّالًا.',
    'device_owner_has_one' => 'لهذا المستخدم جهاز معتمد: ألغِ اعتماده أولًا.',
    'fingerprint_mismatch' => 'البصمة لا تطابق البصمة التي يعرضها الجهاز.',
    'public_key_format' => 'المفتاح العام يجب أن يكون مفتاح EC من نوع P-256 (prime256v1) بصيغة PEM.',
    'registration_unavailable' => 'تسجيل الأجهزة متوقف مؤقتًا: الأجهزة بانتظار الاعتماد كثيرة، راجع مسؤول النظام.',
    'device_already_revoked' => 'اعتماد هذا الجهاز ملغى أصلًا.',
    'too_many_attempts' => 'محاولات كثيرة: انتظر قليلًا ثم أعد المحاولة.',
];
