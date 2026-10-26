<?php

namespace App\Modules\Imports\Support;

use App\Support\ArabicText;
use Illuminate\Support\Facades\DB;

/**
 * The managed lists, loaded once per check, matched by normalised name (ArabicText::searchKey), so
 * "أحمد" in a cell finds "احمد" in a list. Archived entries are known too, to give a precise message.
 * Areas are matched within their district only.
 */
final class ReferenceLookup
{
    /** @var array<string, array<int, object{id: int, name_ar: string, name_key: string, archived: bool, district_id: ?int}>> */
    private array $entries = [];

    /** @var array<string, array<string, list<int>>> table => scope|name_key => ids */
    private array $byKey = [];

    /**
     * @return array{id: ?int, archived: bool}|null null when no entry has that name
     */
    public function find(string $table, string $text, ?int $districtId = null): ?array
    {
        $ids = $this->byKey($table)[$this->scopedKey($table, ArabicText::searchKey($text), $districtId)] ?? [];

        if ($ids === []) {
            return null;
        }

        // An active entry wins over an archived one with the same name.
        foreach ($ids as $id) {
            if (! $this->entries[$table][$id]->archived) {
                return ['id' => $id, 'archived' => false];
            }
        }

        return ['id' => $ids[0], 'archived' => true];
    }

    /**
     * Like find(), for cells that may carry details after the list value: "مريض - سكري" or "المزة شارع الجلاء".
     * Tries the whole text, then shorter and shorter leading parts cut at a space or separator, always on the
     * original text, so an entry whose own name contains punctuation ("المزة-فيلات غربية", "مريض (مزمن)")
     * is found before a shorter one. What follows the match is the details.
     *
     * @return array{id: ?int, archived: bool, details: ?string}|null
     */
    public function findWithDetails(string $table, string $text, ?int $districtId = null): ?array
    {
        $text = trim($text);

        // Every place the text can be cut: before and after each space or separator (byte offsets), longest first.
        preg_match_all('/[\s\-–—:\/،,()؛;]/u', $text, $matches, PREG_OFFSET_CAPTURE);
        $cuts = [strlen($text)];

        foreach ($matches[0] as [$character, $offset]) {
            $cuts[] = $offset;
            $cuts[] = $offset + strlen($character);
        }

        $cuts = array_unique($cuts);
        rsort($cuts);

        foreach ($cuts as $cut) {
            // As written first (an entry's own name may end with ")"), then without trailing separators.
            $head = trim(substr($text, 0, $cut));
            $found = $head === '' ? null : ($this->find($table, $head, $districtId)
                ?? ($this->trimSeparators($head) !== $head && $this->trimSeparators($head) !== '' ? $this->find($table, $this->trimSeparators($head), $districtId) : null));

            if ($found !== null) {
                $details = $this->trimSeparators(substr($text, $cut));

                return $found + ['details' => $details === '' ? null : $details];
            }
        }

        return null;
    }

    /** The entry exists, is not archived, and (for areas) belongs to the district. */
    public function isActive(string $table, int $id, ?int $districtId = null): bool
    {
        $entry = $this->entries($table)[$id] ?? null;

        if ($entry === null || $entry->archived) {
            return false;
        }

        return $table !== 'areas' || $districtId === null || $entry->district_id === $districtId;
    }

    public function exists(string $table, int $id): bool
    {
        return isset($this->entries($table)[$id]);
    }

    public function districtOfArea(int $areaId): ?int
    {
        return $this->entries('areas')[$areaId]->district_id ?? null;
    }

    public function name(string $table, ?int $id): ?string
    {
        return $id === null ? null : ($this->entries($table)[$id]->name_ar ?? null);
    }

    public function isArchived(string $table, int $id): bool
    {
        return (bool) ($this->entries($table)[$id]->archived ?? false);
    }

    /** Multibyte-safe (trim() works on bytes and would cut Arabic letters that share a byte with "،"). */
    private function trimSeparators(string $text): string
    {
        return preg_replace('/^[\s\-–—:\/،,؛;()]+|[\s\-–—:\/،,؛;()]+$/u', '', $text) ?? $text;
    }

    private function scopedKey(string $table, string $key, ?int $districtId): string
    {
        return $table === 'areas' ? ($districtId ?? 0) . '|' . $key : $key;
    }

    /** @return array<string, list<int>> */
    private function byKey(string $table): array
    {
        if (! isset($this->byKey[$table])) {
            $this->byKey[$table] = [];

            foreach ($this->entries($table) as $entry) {
                $this->byKey[$table][$this->scopedKey($table, $entry->name_key, $entry->district_id)][] = $entry->id;
            }
        }

        return $this->byKey[$table];
    }

    /** @return array<int, object> */
    private function entries(string $table): array
    {
        if (! isset($this->entries[$table])) {
            $columns = ['id', 'name_ar', 'name_key', 'deleted_at'];

            if ($table === 'areas') {
                $columns[] = 'district_id';
            }

            $this->entries[$table] = DB::table($table)->orderBy('id')->get($columns)
                ->mapWithKeys(fn($row) => [(int) $row->id => (object) [
                    'id' => (int) $row->id,
                    'name_ar' => $row->name_ar,
                    'name_key' => $row->name_key,
                    'archived' => $row->deleted_at !== null,
                    'district_id' => isset($row->district_id) ? (int) $row->district_id : null,
                ]])
                ->all();
        }

        return $this->entries[$table];
    }
}
