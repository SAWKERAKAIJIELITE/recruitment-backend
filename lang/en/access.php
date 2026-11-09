<?php

return [
    'password_reset' => 'The password was changed and every session of the user was ended: tell them the new password.',
    'administrator_holds_all' => 'The system administrator always holds every permission: its role is not edited.',
    'manager_views_only' => 'The general manager only views: the role cannot get permissions that change data (:permissions).',
] + require __DIR__ . '/../ar/access.php';
