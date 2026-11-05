<?php

// Access management: the notes the system writes when it adds or removes a role (D-070), and its messages.
return [
    'notes' => [
        'role_added' => 'أُضيف الدور «:role» تلقائيًا: كل صلاحياته ممنوحة لهذا المستخدم مباشرة (تبقى منحًا مباشرًا).',
        'role_removed' => 'سُحب الدور «:role» تلقائيًا: كل صلاحياته ممنوعة عن هذا المستخدم مباشرة (يبقى المنع مباشرًا).',
    ],
    'password_reset' => 'غُيّرت كلمة المرور وأُنهيت كل جلسات المستخدم: أبلغه بكلمة المرور الجديدة.',
];
