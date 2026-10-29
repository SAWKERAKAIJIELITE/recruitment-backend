<?php

namespace App\Modules\Documents\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Checks an uploaded file by its content, not by what the client claims (D-057): the type detected from the bytes
 * must be accepted (config documents.types) and match the file's extension; a PDF must start like one and an image
 * must be readable as one. Returns the detected type and the extension to store it under.
 */
final class DocumentFile
{
    /** @return array{mime: string, extension: string} */
    public static function inspect(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $mime = $path === false ? null : (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $accepted = config('documents.types');
        $extension = strtolower($file->getClientOriginalExtension());

        if (! is_string($mime) || ! isset($accepted[$mime]) || ! in_array($extension, $accepted[$mime], true)) {
            self::fail();
        }

        $looksRight = $mime === 'application/pdf'
            ? self::startsWith($path, '%PDF-')
            : @getimagesize($path) !== false;

        if (! $looksRight) {
            self::fail();
        }

        return ['mime' => $mime, 'extension' => $accepted[$mime][0]];
    }

    private static function startsWith(string $path, string $prefix): bool
    {
        $handle = fopen($path, 'rb');
        $head = $handle === false ? '' : (string) fread($handle, strlen($prefix));
        $handle !== false && fclose($handle);

        return $head === $prefix;
    }

    private static function fail(): never
    {
        $extensions = collect(config('documents.types'))->flatten()->unique()->implode('، ');

        throw ValidationException::withMessages(['file' => [__('documents.file_type', ['types' => $extensions])]]);
    }
}
