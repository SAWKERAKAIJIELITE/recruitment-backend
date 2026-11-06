<?php

return [
    'not_awaiting_contract' => 'The first contract is written only for an application that passed its interview and awaits its contract (its status is ":status").',
    'application_not_under_contract' => 'The application is not under contract (its status is ":status").',
    'written_before_previous_decision' => 'The writing date is before the previous decision of the application.',
    'ended_before_previous_decision' => 'The end date is before the previous decision of the application.',
    'ended_before_written' => 'A contract cannot end before it was written (:date).',
    'not_latest' => 'This contract was renewed or ended: only the latest contract of the chain can change this way.',
    'renew_without_end' => 'A contract without an end date is not renewed: correct its end date first.',
    'renewal_starts_before_end' => 'A renewal starts on the end date of the previous contract (:date) or later.',
    'ends_after_renewal' => 'A renewed contract ends no later than its renewal starts (:date).',
    'written_after_end' => 'The contract cannot be written after it was ended (:date).',
    'written_before_decision' => 'The contract cannot be written before the interview was passed (:date).',
    'ends_before_start' => 'The end date is before the start date.',
    'reference_taken' => 'Another contract has this reference number.',
    'document_not_of_application' => 'The file must be a document of this application that is not archived.',
    'document_taken' => 'This file is attached to another contract.',
    'nothing_to_correct' => 'No field changes.',
    'ending_soon_title' => 'A contract ends soon',
    'ending_soon_message' => 'The contract of :name (nomination :nomination, reference :reference) ends on :date, in :days days, and is not renewed yet.',
];
