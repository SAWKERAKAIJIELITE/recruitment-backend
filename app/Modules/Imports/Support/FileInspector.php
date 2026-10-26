<?php

namespace App\Modules\Imports\Support;

use SimpleXMLElement;
use ZipArchive;

/**
 * Checks an uploaded workbook before any spreadsheet library parses it (D-045):
 *
 * - the content really is .xlsx (a zip) or .xls (an OLE compound file), whatever the extension says;
 * - it contains no macros, external links, embedded objects, ActiveX controls or external data connections
 *   (the original is kept and staff may download and open it later);
 * - it is not a zip bomb: no part expands abnormally, and the parts that will actually be read (the chosen
 *   sheet, shared strings, styles) stay under a size limit. Other sheets are never decompressed.
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

    /** A part may expand at most this many times (real workbooks: under 30). */
    private const MAX_RATIO = 200;

    private ?ZipArchive $zip = null;

    /** @var array<string, string> sheet name => part path inside the zip */
    private array $sheetParts = [];

    private function __construct(public readonly string $format)
    {
    }

    public static function inspect(string $path): self
    {
        $head = (string) file_get_contents($path, false, null, 0, 8);

        if (str_starts_with($head, "PK\x03\x04")) {
            $inspector = new self(self::XLSX);
            $inspector->inspectZip($path);

            return $inspector;
        }

        if ($head === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            // An .xls is not compressed (its size is bounded by the upload limit). Refuse VBA projects.
            if (str_contains((string) file_get_contents($path), "_\0V\0B\0A\0_\0P\0R\0O\0J\0E\0C\0T\0")) {
                ImportProblem::fail('file', 'unsafe_content');
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

        $parts = array_filter([$this->sheetParts[$sheetName] ?? null, 'xl/sharedStrings.xml', 'xl/styles.xml']);
        $total = 0;

        foreach ($parts as $part) {
            $total += (int) ($this->zip->statName($part)['size'] ?? 0);
        }

        $limit = (int) config('imports.max_unpacked_megabytes', 100);

        if ($total > $limit * 1024 * 1024) {
            ImportProblem::fail('file', 'too_large_unpacked', ['max' => $limit]);
        }
    }

    private function inspectZip(string $path): void
    {
        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            ImportProblem::fail('file', 'unreadable_file');
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $name = (string) ($stat['name'] ?? '');

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
        }

        $this->zip = $zip;
        $this->sheetParts = $this->readSheetParts();

        if ($this->sheetParts === []) {
            ImportProblem::fail('file', 'unreadable_file');
        }
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
            $target = (string) $relation['Target'];
            $targets[(string) $relation['Id']] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
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

    /** Small XML parts only (workbook, relations); never expands entities or reaches the network. */
    private function xml(string $part): ?SimpleXMLElement
    {
        $size = (int) ($this->zip->statName($part)['size'] ?? 0);

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
