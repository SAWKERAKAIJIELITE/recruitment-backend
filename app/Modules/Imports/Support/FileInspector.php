<?php

namespace App\Modules\Imports\Support;

use SimpleXMLElement;
use ZipArchive;

/**
 * Checks an uploaded workbook before any spreadsheet library parses it (D-045):
 *
 * - the content really is .xlsx (a zip) or .xls (an OLE compound file), whatever the extension says;
 * - it contains no macros, external links, embedded objects, ActiveX controls or external data connections
 *   (the original is kept and staff may download and open it later). They are recognised by part name, by content
 *   type ([Content_Types].xml) and by relationship type (every .rels part), so moving or renaming a part does not
 *   hide it; any relationship to an outside target other than a hyperlink is refused too;
 * - it is not a zip bomb: no part expands abnormally, none declares more than the unpacked limit, and the parts
 *   that will actually be read (the chosen sheet, shared strings, styles, found through the workbook's
 *   relationships as the reader finds them) stay under that limit together. Other sheets are never decompressed.
 *
 * For .xlsx it also maps sheet names to their parts, so the reader can load one sheet only.
 */
final class FileInspector
{
    public const XLSX = 'xlsx';

    public const XLS = 'xls';

    /** Parts that are never accepted in an uploaded workbook. */
    private const FORBIDDEN_PARTS = [
        '#vbaProject\.bin$#i',          // macros
        '#(^|/)externalLinks/#i',       // links to other workbooks
        '#(^|/)embeddings/#i',          // embedded files and OLE objects
        '#(^|/)activeX/#i',             // ActiveX controls
        '#(^|/)connections\.xml$#i',    // external data connections
        '#\.(exe|dll|js|vbs|ps1|bat|cmd|scr)$#i',
    ];

    /** Content types never accepted, whatever the part is called (matched as lower-case fragments). */
    private const FORBIDDEN_CONTENT_TYPES = ['vbaproject', 'macrosheet', 'macroenabled', 'oleobject', 'externallink', 'connections+xml', 'activex', 'querytable'];

    /** Relationship types never accepted (the last segment of the type URI). */
    private const FORBIDDEN_RELATIONSHIPS = ['vbaProject', 'oleObject', 'package', 'externalLink', 'externalLinkPath', 'connections', 'control', 'activeXControlBinary', 'attachedTemplate', 'queryTable', 'xlMacrosheet', 'xlIntlMacrosheet'];

    /** Markers of macros, auto-run names and embedded objects in an .xls (binary) file, as ASCII and UTF-16LE. */
    private const XLS_MARKERS = ['_VBA_PROJECT', 'Auto_Open', 'Auto_Close', 'Ole10Native', 'ObjectPool', 'MBD0'];

    /** A part may expand at most this many times (real workbooks: under 30). */
    private const MAX_RATIO = 200;

    private ?ZipArchive $zip = null;

    /** @var array<string, string> lower-case part name => its name in the zip (parts are found case-insensitively) */
    private array $names = [];

    /** @var array<string, string> sheet name => part path inside the zip */
    private array $sheetParts = [];

    private function __construct(public readonly string $format) {}

    public static function inspect(string $path): self
    {
        $head = (string) file_get_contents($path, false, null, 0, 8);

        if (str_starts_with($head, "PK\x03\x04")) {
            $inspector = new self(self::XLSX);
            $inspector->inspectZip($path);

            return $inspector;
        }

        if ($head === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            // An .xls is not compressed (its size is bounded by the upload limit). Refuse macros (VBA and Excel 4
            // auto-run names) and embedded objects.
            $content = (string) file_get_contents($path);

            foreach (self::XLS_MARKERS as $marker) {
                if (stripos($content, $marker) !== false || stripos($content, implode("\0", str_split($marker))."\0") !== false) {
                    ImportProblem::fail('file', 'unsafe_content');
                }
            }

            return new self(self::XLS);
        }

        ImportProblem::fail('file', 'unreadable_file');
    }

    /** @return list<string> sheet names in workbook order (.xlsx only; empty for .xls) */
    public function sheetNames(): array
    {
        return array_keys($this->sheetParts);
    }

    /** The parts read to load one sheet must stay under the limit (memory guard). */
    public function assertSheetIsReasonable(string $sheetName): void
    {
        if ($this->zip === null) {
            return;
        }

        // The sheet, and the shared strings and styles wherever the workbook's relationships put them.
        $parts = array_filter([$this->sheetParts[$sheetName] ?? null, ...$this->workbookParts(['sharedStrings', 'styles'])]);
        $total = 0;

        foreach (array_unique($parts) as $part) {
            $total += $this->declaredSize($part);
        }

        $limit = (int) config('imports.max_unpacked_megabytes', 100);

        if ($total > $limit * 1024 * 1024) {
            ImportProblem::fail('file', 'too_large_unpacked', ['max' => $limit]);
        }
    }

    private function inspectZip(string $path): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            ImportProblem::fail('file', 'unreadable_file');
        }

        $limit = (int) config('imports.max_unpacked_megabytes', 100) * 1024 * 1024;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $name = (string) ($stat['name'] ?? '');
            $this->names[strtolower($name)] = $name;

            foreach (self::FORBIDDEN_PARTS as $pattern) {
                if (preg_match($pattern, $name) === 1) {
                    ImportProblem::fail('file', 'unsafe_content');
                }
            }

            if (str_contains($name, '..') || str_starts_with($name, '/')) {
                ImportProblem::fail('file', 'unsafe_content');
            }

            $size = (int) ($stat['size'] ?? 0);
            $compressed = max(1, (int) ($stat['comp_size'] ?? 0));

            if ($size > 1024 * 1024 && $size / $compressed > self::MAX_RATIO) {
                ImportProblem::fail('file', 'unsafe_content');
            }

            // No part may even declare more than the limit: a reader allocates what a part declares.
            if ($size > $limit) {
                ImportProblem::fail('file', 'too_large_unpacked', ['max' => intdiv($limit, 1024 * 1024)]);
            }
        }

        $this->zip = $zip;
        $this->assertSafeTypes();
        $this->sheetParts = $this->readSheetParts();

        if ($this->sheetParts === []) {
            ImportProblem::fail('file', 'unreadable_file');
        }
    }

    /**
     * Refuses what the content types or any relationship declare as macros, links, objects or connections, and any
     * relationship to an outside target other than a hyperlink.
     */
    private function assertSafeTypes(): void
    {
        $types = $this->xml('[Content_Types].xml');

        foreach ($types?->children() ?? [] as $entry) {
            $type = strtolower((string) $entry['ContentType']);

            if (array_filter(self::FORBIDDEN_CONTENT_TYPES, fn (string $fragment) => str_contains($type, $fragment)) !== []) {
                ImportProblem::fail('file', 'unsafe_content');
            }
        }

        foreach ($this->names as $name) {
            if (! str_ends_with(strtolower($name), '.rels')) {
                continue;
            }

            foreach ($this->xml($name)?->children() ?? [] as $relation) {
                $type = (string) $relation['Type'];
                $external = strcasecmp((string) $relation['TargetMode'], 'External') === 0;

                if (in_array(basename($type), self::FORBIDDEN_RELATIONSHIPS, true) || ($external && ! str_ends_with($type, '/hyperlink'))) {
                    ImportProblem::fail('file', 'unsafe_content');
                }
            }
        }
    }

    /** @return list<string> the parts the workbook relates by these relationship types (as the reader resolves them) */
    private function workbookParts(array $types): array
    {
        $parts = [];

        foreach ($this->xml('xl/_rels/workbook.xml.rels')?->children() ?? [] as $relation) {
            if (in_array(basename((string) $relation['Type']), $types, true)) {
                $parts[] = $this->resolve((string) $relation['Target']);
            }
        }

        return $parts;
    }

    /** A relationship target as a part name: relative to xl/, "./" and ".." resolved, found case-insensitively. */
    private function resolve(string $target): string
    {
        $path = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            match ($segment) {
                '', '.' => null,
                '..' => array_pop($segments),
                default => $segments[] = $segment,
            };
        }

        $name = implode('/', $segments);

        return $this->names[strtolower($name)] ?? $name;
    }

    private function declaredSize(string $part): int
    {
        return (int) ($this->zip->statName($part)['size'] ?? 0);
    }

    /** @return array<string, string> */
    private function readSheetParts(): array
    {
        $workbook = $this->xml('xl/workbook.xml');
        $relations = $this->xml('xl/_rels/workbook.xml.rels');

        if ($workbook === null || $relations === null) {
            return [];
        }

        $targets = [];

        foreach ($relations->children() as $relation) {
            $targets[(string) $relation['Id']] = $this->resolve((string) $relation['Target']);
        }

        $parts = [];

        foreach ($workbook->sheets->children() as $sheet) {
            $id = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];

            if (isset($targets[$id])) {
                $parts[(string) $sheet['name']] = $targets[$id];
            }
        }

        return $parts;
    }

    /** Small XML parts only (workbook, relations, content types); never expands entities or reaches the network. */
    private function xml(string $part): ?SimpleXMLElement
    {
        $part = $this->names[strtolower($part)] ?? $part;
        $size = $this->declaredSize($part);

        if ($size === 0 || $size > 5 * 1024 * 1024) {
            return null;
        }

        $content = $this->zip->getFromName($part);

        if ($content === false || preg_match('/<!DOCTYPE|<!ENTITY/i', $content) === 1) {
            return null;
        }

        $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET);

        return $xml === false ? null : $xml;
    }

    public function __destruct()
    {
        $this->zip?->close();
    }
}
