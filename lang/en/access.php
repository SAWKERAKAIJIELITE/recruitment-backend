<?php

return [
    'password_reset' => 'The password was changed and every session of the user was ended: tell them the new password.',
    'administrator_holds_all' => 'The system administrator always holds every permission: its role is not edited.',
    'manager_views_only' => 'The general manager only views: the role cannot get permissions that change data (:permissions).',
    'device_not_pending' => 'Only a pending device can be approved.',
    'device_owner_inactive' => 'The device owner must be an active staff user.',
    'device_owner_has_one' => 'This user already has an approved device. Revoke it first.',
    'fingerprint_mismatch' => 'The fingerprint does not match the one shown by the device.',
    'public_key_format' => 'The public key must be a P-256 (prime256v1) EC public key in PEM format.',
    'registration_unavailable' => 'Device registration is temporarily unavailable.',
    'device_already_revoked' => 'The device is already revoked.',
    'too_many_attempts' => 'Too many attempts: wait a little, then try again.',
] + require __DIR__ . '/../ar/access.php';
