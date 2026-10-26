<?php

namespace App\Modules\Recruitment\Contracts;

/**
 * A record that causes an application's status to change: an exam attempt, an interview, a contract, ...
 * (D-038). Each cause has its own nullable foreign-key column on application_status_events, added by its
 * module's migration with StatusEventCauseColumns::add().
 *
 * Implemented by Eloquent models of other modules; ApplicationLifecycle::move() stores the key in the column.
 */
interface StatusChangeCause
{
    /** The column of application_status_events that references this kind of record, e.g. "exam_attempt_id". */
    public static function statusEventColumn(): string;

    /** @return mixed the record's primary key */
    public function getKey();
}
