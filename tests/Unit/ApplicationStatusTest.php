<?php

namespace Tests\Unit;

use App\Modules\Recruitment\Enums\ApplicationStatus as S;
use App\Modules\Recruitment\Enums\DecisionType as D;
use PHPUnit\Framework\TestCase;

class ApplicationStatusTest extends TestCase
{
    public function test_every_status_has_an_arabic_label(): void
    {
        foreach (S::cases() as $status) {
            $this->assertNotSame('', $status->labelAr());
        }
    }

    public function test_only_two_statuses_are_terminal(): void
    {
        $this->assertEqualsCanonicalizing(['interview_failed', 'contract_written'], S::terminalValues());
    }

    public function test_every_transition_target_is_a_real_status(): void
    {
        foreach (S::cases() as $from) {
            foreach (array_keys($from->transitions()) as $toValue) {
                $this->assertNotNull(S::tryFrom($toValue), "{$from->value} points to unknown status {$toValue}");
            }
        }
    }

    public function test_the_happy_path_is_all_outcomes(): void
    {
        $this->assertSame(D::Outcome, S::AwaitingExam->decisionRequiredFor(S::AwaitingInterview));
        $this->assertSame(D::Outcome, S::AwaitingInterview->decisionRequiredFor(S::AwaitingContract));
        $this->assertSame(D::Outcome, S::AwaitingContract->decisionRequiredFor(S::ContractWritten));
    }

    public function test_failing_the_exams_can_only_be_overturned_by_an_exception(): void
    {
        $this->assertSame(D::Exception, S::ExamFailed->decisionRequiredFor(S::AwaitingInterview));
        $this->assertNull(S::ExamFailed->decisionRequiredFor(S::AwaitingExam));
    }

    public function test_absence_leads_back_only_through_an_accepted_excuse(): void
    {
        $this->assertSame(D::ExcuseAccepted, S::ExamAbsent->decisionRequiredFor(S::AwaitingExam));
        $this->assertSame(D::ExcuseAccepted, S::InterviewAbsent->decisionRequiredFor(S::AwaitingInterview));
    }

    public function test_jumps_are_illegal(): void
    {
        $this->assertNull(S::AwaitingExam->decisionRequiredFor(S::ContractWritten));
        $this->assertNull(S::AwaitingExam->decisionRequiredFor(S::AwaitingContract));
        $this->assertNull(S::ExamFailed->decisionRequiredFor(S::ContractWritten));
        $this->assertNull(S::ContractWritten->decisionRequiredFor(S::AwaitingExam));
    }

    public function test_only_person_made_decisions_need_a_reason_and_actor(): void
    {
        $this->assertTrue(D::Exception->needsReasonAndActor());
        $this->assertTrue(D::ExcuseAccepted->needsReasonAndActor());
        $this->assertFalse(D::Outcome->needsReasonAndActor());
        $this->assertFalse(D::Created->needsReasonAndActor());
    }
}
