<?php

return [
    'statuses' => [
        'draft' => 'draft',
        'published' => 'published',
        'retired' => 'retired',
    ],
    'job_position_archived' => 'The job position is archived.',
    'draft_exists' => 'This job position already has a draft plan (version :version): edit or delete it.',
    'copy_other_position' => 'A plan of another job position cannot be copied.',
    'not_a_draft' => 'Only a draft can change; this plan is :status.',
    'no_subjects' => 'Add at least one subject.',
    'subject_archived' => 'The subject ":subject" is archived.',
    'part_archived' => 'The part ":part" of ":subject" is archived.',
    'questions_on_parts' => 'A subject scored from parts has its questions on its parts.',
    'part_not_in_subject' => 'This part does not belong to this subject.',
    'duration_required' => 'Set the duration of the exam session.',
    'questions_required' => 'Set the number of easy, medium and hard questions of ":item".',
    'bank_short' => '":item": the bank has :have :difficulty questions and the plan asks :need.',
    'no_exam_questions' => 'The plan has no online question.',
    'manual_has_no_questions' => 'A part marked by hand has no exam questions.',
    'max_score_manual_only' => 'Only a part marked by hand has a maximum score.',
    'correct_option_out_of_range' => 'The correct option is not one of the options.',
    // Exam sessions, bookings, attempts and results (D-072 to D-075).
    'sessions' => [
        'plan_not_published' => 'A session sits a published plan only; this plan is :status.',
        'late_too_long' => 'The late window must be shorter than the exam (:duration minutes).',
        'started' => 'The session has started: its start, place, late window and questions no longer change.',
        'ended' => 'The session has ended: its time is not extended.',
        'in_use' => 'Candidates were booked into this session (even if cancelled), so it is not deleted.',
    ],
    'bookings' => [
        'session_closed' => 'The late window of this session has closed: no more bookings.',
        'wrong_status' => 'Only an application awaiting its exam is booked; this one is :status.',
        'other_job_position' => 'This session\'s plan is for another job position.',
        'already_booked' => 'This application already has an exam booking that is not decided yet.',
        'not_waiting' => 'Only a booking whose candidate has not started is cancelled; this one is :status.',
        'application_moved' => 'The application was no longer awaiting its exam when the late window closed.',
    ],
    'attempts' => [
        'not_running' => 'Only a running attempt whose time is not over can be extended.',
    ],
    'candidate' => [
        'login_failed' => 'Wrong national number or session password, or you have no session now.',
        'too_late' => 'The late window closed (:time): you are absent from this exam.',
        'not_yet' => 'The exam has not started yet; it starts at :time.',
        'not_booked' => 'You have no exam in this session (booking :status).',
        'closed' => 'This exam was submitted; its answers no longer change.',
        'time_over' => 'Time is over: the exam was submitted with the answers saved.',
        'no_such_question' => 'There is no question with this number in your exam.',
        'no_such_option' => 'This question has no option with this number.',
        'not_started' => 'You have not started the exam yet.',
    ],
    'marks' => [
        'not_a_manual_part' => 'This is not a hand-marked part of this session\'s plan.',
        'above_max' => 'The mark is above the part\'s maximum (:max).',
        'reason_required' => 'This part already has a mark: give the reason for the correction.',
        'correct_permission' => 'The result is decided: correcting a mark needs the permission exam_results.correct.',
        'not_attended' => 'The candidate of this booking did not start the exam, so there are no marks.',
    ],
    'results' => [
        'not_latest' => 'The application moved on after this result (an exception or another decision): the decision is not corrected here.',
    ],
    'decisions' => [
        'wrong_status' => 'The application is :status.',
        'date' => 'The decision date must be after the application\'s last decision and not in the future.',
    ],
];
