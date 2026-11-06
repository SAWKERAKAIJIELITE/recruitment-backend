<?php

namespace App\Modules\Identity\Models;

use App\Modules\Contracts\Models\Contract;
use App\Modules\Documents\Models\Document;
use App\Modules\Exams\Models\ExamAttemptExtension;
use App\Modules\Exams\Models\ExamBooking;
use App\Modules\Exams\Models\ExamManualMark;
use App\Modules\Exams\Models\ExamPlanVersion;
use App\Modules\Exams\Models\ExamQuestion;
use App\Modules\Exams\Models\ExamSession;
use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Interviews\Models\Interview;
use App\Modules\Interviews\Models\InterviewMember;
use App\Modules\Interviews\Models\InterviewReschedule;
use App\Modules\Interviews\Models\InterviewResultRevision;
use App\Modules\Interviews\Models\InterviewSitting;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\ApplicationStatusEvent;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

class StaffUser extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    // public const TYPE_STAFF = 'staff';
    // public const TYPE_CANDIDATE = 'candidate';

    protected $fillable = [
        // 'type',
        'username',
        'first_name',
        'last_name',
        'personal_number',
        'password',
        'is_active',
        // 'must_change_password',
    ];

    protected $hidden = ['password'];

    /** Per-request cache of effective permission slugs. */
    private ?Collection $permissionSlugCache = null;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            // 'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    /** Usernames are case-insensitive: always stored trimmed and lowercase. */
    protected function username(): Attribute
    {
        return Attribute::make(set: fn(string $value) => mb_strtolower(trim($value)));
    }

    protected function getFullNameAttribute(): string
    {
        return mb_ucfirst($this->first_name) . ' ' . mb_ucfirst($this->last_name);
    }

    // We use token auth only; there is no remember_token column.
    public function getRememberTokenName(): string
    {
        return '';
    }

    protected static function newFactory(): StaffUserFactory
    {
        return StaffUserFactory::new();
    }

    /**
     * The user's roles. `automatic`: added by the system because every permission of the role is granted to the
     * user directly (its `note` says so); a role given by hand is not.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_staff_user')->withPivot(['automatic', 'note', 'assigned_by', 'created_at']);
    }

    /** Direct per-user overrides (grant or deny), with a note when the system acted on them. */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_staff_user')
            ->withPivot(['is_granted', 'granted_by', 'note'])
            ->withTimestamps();
    }

    /**
     * Effective permissions = (permissions from roles + direct grants) - direct denials.
     */
    public function permissionSlugs(): Collection
    {
        return $this->permissionSlugCache ??= $this->resolvePermissionSlugs();
    }

    public function hasPermission(string $slug): bool
    {
        return $this->permissionSlugs()->contains($slug);
    }

    public function forgetPermissionCache(): void
    {
        $this->permissionSlugCache = null;
    }

    private function resolvePermissionSlugs(): Collection
    {
        $fromRoles = $this->roles()->with('permissions')->get()
            ->flatMap(fn(Role $role) => $role->permissions->pluck('slug')->all())
            ->all();

        $direct = $this->permissions()->get();
        $granted = $direct->filter(fn($p) => (bool) $p->pivot->is_granted)->pluck('slug')->all();
        $denied = $direct->reject(fn($p) => (bool) $p->pivot->is_granted)->pluck('slug')->all();

        return collect($fromRoles)->merge($granted)->unique()->diff($denied)->values();
    }

    /** Every device this user has ever owned (history, including revoked ones). */
    public function devices(): HasMany
    {
        return $this->hasMany(StaffDevice::class, 'staff_user_id');
    }

    /** The one approved device the user can log in from (null if none). */
    public function device(): HasOne
    {
        return $this->hasOne(StaffDevice::class, 'staff_user_id')->where('status', 'approved');
    }

    /** Devices this user approved / revoked (devices.approve). */
    public function approvedDevices(): HasMany
    {
        return $this->hasMany(StaffDevice::class, 'approved_by');
    }

    public function revokedDevices(): HasMany
    {
        return $this->hasMany(StaffDevice::class, 'revoked_by');
    }

    // What the user did elsewhere. Every action is recorded with who did it; these are the way back from the user.

    /** Applications this user entered (by hand or by an approved import). */
    public function createdApplications(): HasMany
    {
        return $this->hasMany(Application::class, 'created_by');
    }

    /** Every status decision this user made (exceptions, excuses, closures, recorded outcomes, ...). */
    public function statusDecisions(): HasMany
    {
        return $this->hasMany(ApplicationStatusEvent::class, 'actor_id');
    }

    public function uploadedImports(): HasMany
    {
        return $this->hasMany(ImportBatch::class, 'uploaded_by');
    }

    public function approvedImports(): HasMany
    {
        return $this->hasMany(ImportBatch::class, 'approved_by');
    }

    public function reviewedImportRows(): HasMany
    {
        return $this->hasMany(ImportRow::class, 'reviewed_by');
    }

    public function uploadedDocuments(): HasMany
    {
        return $this->hasMany(Document::class, 'uploaded_by');
    }

    public function archivedDocuments(): HasMany
    {
        return $this->hasMany(Document::class, 'archived_by');
    }

    public function createdExamPlans(): HasMany
    {
        return $this->hasMany(ExamPlanVersion::class, 'created_by');
    }

    public function publishedExamPlans(): HasMany
    {
        return $this->hasMany(ExamPlanVersion::class, 'published_by');
    }

    public function createdExamQuestions(): HasMany
    {
        return $this->hasMany(ExamQuestion::class, 'created_by');
    }

    public function createdExamSessions(): HasMany
    {
        return $this->hasMany(ExamSession::class, 'created_by');
    }

    /** Candidates this user booked into exam sessions. */
    public function examBookings(): HasMany
    {
        return $this->hasMany(ExamBooking::class, 'created_by');
    }

    /** Every move of an attempt's deadline this user gave (its own, or its share of one for everyone). */
    public function examExtensions(): HasMany
    {
        return $this->hasMany(ExamAttemptExtension::class, 'extended_by');
    }

    /** Hand marks (التجويد) this user entered or corrected. */
    public function examMarks(): HasMany
    {
        return $this->hasMany(ExamManualMark::class, 'entered_by');
    }

    /** Seats on interview committees: one row per sitting the user sits on, with the capacity (الصفة). */
    public function interviewMemberships(): HasMany
    {
        return $this->hasMany(InterviewMember::class, 'staff_user_id');
    }

    /** The sittings this user sits on (as chair, member or secretary). */
    public function interviewSittings(): BelongsToMany
    {
        return $this->belongsToMany(InterviewSitting::class, 'interview_members', 'staff_user_id', 'sitting_id')->withPivot(['capacity', 'position']);
    }

    public function createdInterviewSittings(): HasMany
    {
        return $this->hasMany(InterviewSitting::class, 'created_by');
    }

    /** Interviews this user booked. */
    public function scheduledInterviews(): HasMany
    {
        return $this->hasMany(Interview::class, 'created_by');
    }

    /** Interviews whose result this user entered. */
    public function recordedInterviews(): HasMany
    {
        return $this->hasMany(Interview::class, 'recorded_by');
    }

    public function cancelledInterviews(): HasMany
    {
        return $this->hasMany(Interview::class, 'cancelled_by');
    }

    public function interviewReschedules(): HasMany
    {
        return $this->hasMany(InterviewReschedule::class, 'rescheduled_by');
    }

    /** Every version of an interview result this user entered (first entries and corrections). */
    public function interviewResultRevisions(): HasMany
    {
        return $this->hasMany(InterviewResultRevision::class, 'entered_by');
    }

    /** Contracts this user wrote (first contracts and renewals). */
    public function writtenContracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'created_by');
    }

    public function endedContracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'ended_by');
    }
}
