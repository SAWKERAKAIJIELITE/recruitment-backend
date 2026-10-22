<?php

namespace App\Modules\Recruitment\Models;

use App\Modules\Recruitment\Support\BlindIndex;
use App\Modules\Recruitment\Support\NationalId;
use App\Support\ArabicText;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** The person. Their applications live in Application. */
class Candidate extends Model
{
    use HasUuids;

    /**
     * national_id and national_id_hash are NOT fillable: they are always set together through
     * setNationalId(), so the readable-looking value and its blind index can never disagree.
     */
    protected $fillable = [
        'full_name', 'mother_name', 'birth_date', 'birth_place', 'phone', 'governorate_id',
        'residence_details', 'education_level', 'specialization', 'study_years', 'quran_parts_memorized',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'national_id' => 'encrypted',
            'study_years' => 'integer',
            'quran_parts_memorized' => 'integer',
        ];
    }

    /** Keeps the normalised search key in step with the name (a mutator, so it also works in seeders). */
    protected function fullName(): Attribute
    {
        return Attribute::make(set: fn(string $value) => [
            'full_name' => trim($value),
            'name_search_key' => ArabicText::searchKey($value),
        ]);
    }

    /** Stores the national ID encrypted together with its blind index. Null clears both. */
    public function setNationalId(?string $value): void
    {
        $normalized = $value === null ? '' : NationalId::normalize($value);

        $this->forceFill([
            'national_id' => $normalized === '' ? null : $normalized,
            'national_id_hash' => $normalized === '' ? null : BlindIndex::for($normalized),
        ]);
    }

    public function maskedNationalId(): ?string
    {
        return $this->national_id === null ? null : NationalId::mask($this->national_id);
    }

    public function governorate(): BelongsTo
    {
        return $this->belongsTo(District::class)->withTrashed();
    }

    public function sensitiveDetails(): HasOne
    {
        return $this->hasOne(CandidateSensitiveDetail::class, 'candidate_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }
}
