<?php

namespace App\Modules\Recruitment\Models;

use App\Modules\Recruitment\Support\NationalNumber;
use App\Support\ArabicText;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The person, recognized by national number (D-028, D-032). Only facts that do not change between two
 * applications live here; everything declared at application time is on Application.
 */
class Candidate extends Model
{
    use HasUuids;

    protected $fillable = ['national_number', 'full_name', 'mother_name', 'birth_date', 'birth_place_id', 'notes'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'is_active' => 'boolean',
        ];
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
}
