<?php

namespace App\Modules\Recruitment\Models;

use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Support\NationalNumber;
use App\Support\ArabicText;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Sanctum\HasApiTokens;

/**
 * The person, recognized by national number (D-028, D-032). Only facts that do not change between two
 * applications live here; everything declared at application time is on Application.
 *
 * Also the candidate who sits an exam (guard `candidate`, D-072): they log in with their national number and the
 * password of the session they are booked into; the token belongs to that booking. There is no password of their own.
 */
class Candidate extends Model implements AuthenticatableContract
{
    use Authenticatable, HasApiTokens, HasUuids;

    /** is_active and the deactivation columns are set only by ChangeCandidateActivation (forceFill). */
    protected $fillable = ['national_number', 'full_name', 'mother_name', 'birth_date', 'birth_place_id', 'notes'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'is_active' => 'boolean',
            'deactivated_at' => 'datetime',
        ];
    }

    /** No password of their own (the session's is checked at login), no "remember me". */
    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberTokenName(): string
    {
        return '';
    }

    public function deactivator(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'deactivated_by');
    }

    /** Always stored as ASCII digits ("٠١٢٣" becomes "0123"; spaces and dashes are dropped). */
    protected function nationalNumber(): Attribute
    {
        return Attribute::make(set: fn(string $value) => NationalNumber::normalize($value));
    }

    /** Keeps the normalized search key in step with the name (a mutator, so it also works in seeders). */
    protected function fullName(): Attribute
    {
        return Attribute::make(set: fn(string $value) => [
            'full_name' => trim($value),
            'name_search_key' => ArabicText::searchKey($value),
        ]);
    }

    protected function motherName(): Attribute
    {
        return Attribute::make(set: fn(string $value) => trim($value));
    }

    public function birthPlace(): BelongsTo
    {
        return $this->belongsTo(District::class, 'birth_place_id')->withTrashed();
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /** The one application that has not ended (D-037), if any. */
    public function liveApplication(): HasOne
    {
        return $this->hasOne(Application::class)->live();
    }
}
