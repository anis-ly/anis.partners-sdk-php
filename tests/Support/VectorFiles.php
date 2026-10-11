<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Support;

/** Reads every published vector and checks a folder manifest when supplied. */
final class VectorFiles
{
    /** @return list<string> */
    public static function read(string $directory, string $pattern): array
    {
        $matched = glob($directory . '/' . $pattern);
        $files = $matched === false ? [] : $matched;
        sort($files, SORT_STRING);
        if (!is_file($directory . '/manifest.json')) {
            return $files;
        }
        $manifest = JsonFixture::readObject($directory . '/manifest.json');
        $listed = [];
        foreach (JsonFixture::objectList($manifest, 'vectors') as $vector) {
            $listed[] = JsonFixture::nullableString($vector, 'file') ?? JsonFixture::nullableString($vector, 'filename') ?? JsonFixture::string($vector, 'id') . '.json';
        }
        sort($listed, SORT_STRING);
        $onDisk = array_map('basename', $files);
        if ($listed !== $onDisk) {
            throw new \UnexpectedValueException('The vector manifest and files differ.');
        }
        return $files;
    }
}
