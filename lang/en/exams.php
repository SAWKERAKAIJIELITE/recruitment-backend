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
];
