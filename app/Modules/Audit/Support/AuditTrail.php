<?php

namespace App\Modules\Audit\Support;

use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

/**
 * The single place where "who did what" is recorded (D-078): a row of audit_events, in the transaction of the action
 * (rolled back with it), and a line in the application log.
 *
 * Security events (access management, devices, staff and candidate logins) are read with `audit.view` only; every other event is
 * an operation, also read with `audit.view_related` (analysis doc §02: "audit of the related operations only").
 */
final class AuditTrail
{
    /** Event prefixes (or whole events) that are security events. */
    public const SECURITY = ['access.', 'device.', 'auth.', 'exam.candidate_login'];

    /** @param ?string $candidateId when the candidate acted themselves (an exam login, a start), not a staff user about them */
    public static function record(string $event, ?StaffUser $actor, array $context = [], ?string $candidateId = null): void
    {
        $context = self::plain($context);

        AuditEvent::query()->forceCreate([
            'event' => $event,
            'category' => self::category($event),
            'actor_id' => $actor?->id,
            'candidate_id' => $candidateId,
            'context' => $context,
            'related_ids' => self::arrayLiteral(self::relatedIds($context, $candidateId)),
            'ip' => request()->route() === null ? null : request()->ip(),   // none from the console or the scheduler
            'created_at' => Date::now(),
        ]);

        Log::notice($event, ['actor_id' => $actor?->id] + $context);
    }

    public static function category(string $event): string
    {
        foreach (self::SECURITY as $prefix) {
            if (str_starts_with($event, $prefix)) {
                return 'security';
            }
        }

        return 'operations';
    }

    /**
     * The ids the event is about, for the `related_id` search: the values of the top-level context keys `id`,
     * `*_id` and `*_ids`, and the acting candidate.
     *
     * @return list<string>
     */
    public static function relatedIds(array $context, ?string $candidateId = null): array
    {
        $ids = $candidateId === null ? [] : [$candidateId];

        foreach ($context as $key => $value) {
            if (! is_string($key) || ! ($key === 'id' || str_ends_with($key, '_id') || str_ends_with($key, '_ids'))) {
                continue;
            }

            foreach (is_array($value) ? $value : [$value] as $id) {
                if (is_string($id) || is_int($id)) {
                    $ids[] = (string) $id;
                }
            }
        }

        return array_values(array_unique(array_filter($ids, fn(string $id) => $id !== '')));
    }

    /** A PostgreSQL text[] literal: every element quoted, `"` and `\` escaped. */
    private static function arrayLiteral(array $values): string
    {
        return '{' . implode(',', array_map(fn(string $value) => '"' . addcslashes($value, '"\\') . '"', $values)) . '}';
    }

    /** Dates as ISO strings and enums as their values, so the stored JSON reads the same everywhere. */
    private static function plain(array $context): array
    {
        array_walk_recursive($context, function (&$value) {
            $value = match (true) {
                $value instanceof \DateTimeInterface => Date::instance($value)->toIso8601String(),
                $value instanceof \BackedEnum => $value->value,
                default => $value,
            };
        });

        return $context;
    }
}
