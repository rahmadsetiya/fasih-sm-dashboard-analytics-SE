<?php

namespace App\Services;

use App\Models\InitialPrelist;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

class InitialPrelistImporter
{
    /**
     * @return array<string, int|string>
     */
    public function import(string $path, string $sheetName = 'Rekap Prelist'): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("File tidak ditemukan: {$path}");
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException("File XLSX tidak bisa dibuka: {$path}");
        }

        try {
            $sharedStrings = $this->sharedStrings($zip);
            $sheetEntry = $this->sheetEntry($zip, $sheetName);
            $xml = $this->entryXml($zip, $sheetEntry);
            $rows = $this->rows($xml, $sharedStrings);
        } finally {
            $zip->close();
        }

        $now = now();
        $sourceFile = basename($path);
        $records = [];
        $seen = [];
        $duplicates = 0;
        $invalid = 0;
        $zero = 0;
        $total = 0;

        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex < 3) {
                continue;
            }

            $idsubsls = trim((string) ($row['D'] ?? ''));
            $assignmentTotal = $this->integerValue($row['AD'] ?? null);

            if (! preg_match('/^\d{16}$/', $idsubsls)) {
                $invalid++;

                continue;
            }

            if (isset($seen[$idsubsls])) {
                $duplicates++;
            }
            $seen[$idsubsls] = true;

            if ($assignmentTotal === 0) {
                $zero++;
            }

            $total += $assignmentTotal;
            $records[$idsubsls] = [
                'idsubsls' => $idsubsls,
                'kdkec' => $idsubsls !== '' ? substr($idsubsls, 0, 7) : null,
                'nmkec' => $this->nullableString($row['J'] ?? null),
                'kddes' => $idsubsls !== '' ? substr($idsubsls, 0, 10) : null,
                'nmdesa' => $this->nullableString($row['L'] ?? null),
                'kdsls' => $idsubsls !== '' ? substr($idsubsls, 0, 14) : null,
                'kdsubsls' => $idsubsls !== '' ? substr($idsubsls, 0, 16) : null,
                'nmsls' => $this->nullableString($row['O'] ?? null),
                'nmsubsls' => $this->nullableString($row['O'] ?? null),
                'total_assignment_fasih' => $assignmentTotal,
                'source_sheet' => $sheetName,
                'source_file' => $sourceFile,
                'imported_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($records) {
            foreach (array_chunk(array_values($records), 500) as $chunk) {
                InitialPrelist::query()->upsert(
                    $chunk,
                    ['idsubsls'],
                    [
                        'kdkec',
                        'nmkec',
                        'kddes',
                        'nmdesa',
                        'kdsls',
                        'kdsubsls',
                        'nmsls',
                        'nmsubsls',
                        'total_assignment_fasih',
                        'source_sheet',
                        'source_file',
                        'imported_at',
                        'updated_at',
                    ],
                );
            }
        });

        return [
            'source_file' => $sourceFile,
            'source_sheet' => $sheetName,
            'rows_imported' => count($records),
            'total_assignment' => $total,
            'zero_total_rows' => $zero,
            'duplicates' => $duplicates,
            'invalid_rows' => $invalid,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $this->entryXml($zip, 'xl/sharedStrings.xml', false);
        if ($xml === null) {
            return [];
        }

        $dom = new \DOMDocument;
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $strings = [];
        foreach ($xpath->query('//m:si') ?: [] as $node) {
            $strings[] = $node->textContent;
        }

        return $strings;
    }

    private function sheetEntry(ZipArchive $zip, string $sheetName): string
    {
        $workbook = new \DOMDocument;
        $workbook->loadXML($this->entryXml($zip, 'xl/workbook.xml'));
        $workbookXpath = new \DOMXPath($workbook);
        $workbookXpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $workbookXpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $rels = new \DOMDocument;
        $rels->loadXML($this->entryXml($zip, 'xl/_rels/workbook.xml.rels'));
        $relsXpath = new \DOMXPath($rels);

        foreach ($workbookXpath->query('//m:sheet') ?: [] as $sheet) {
            $name = (string) $sheet->attributes->getNamedItem('name')?->nodeValue;
            if (! str_contains(strtolower($name), strtolower($sheetName))) {
                continue;
            }

            $relationshipId = (string) $sheet->attributes->getNamedItemNS(
                'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
                'id',
            )?->nodeValue;
            $relationship = $relsXpath->query("//*[@Id='{$relationshipId}']")->item(0);
            $target = (string) $relationship?->attributes->getNamedItem('Target')?->nodeValue;

            if ($target === '') {
                break;
            }

            return str_starts_with($target, '/')
                ? ltrim($target, '/')
                : 'xl/'.ltrim($target, '/');
        }

        throw new RuntimeException("Sheet '{$sheetName}' tidak ditemukan.");
    }

    /**
     * @param  array<int, string>  $sharedStrings
     * @return array<int, array<string, string|int|float|null>>
     */
    private function rows(string $xml, array $sharedStrings): array
    {
        $dom = new \DOMDocument;
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $rows = [];
        foreach ($xpath->query('//m:sheetData/m:row') ?: [] as $row) {
            $rowIndex = (int) $row->attributes->getNamedItem('r')?->nodeValue;
            $cells = [];
            foreach ($xpath->query('m:c', $row) ?: [] as $cell) {
                $reference = (string) $cell->attributes->getNamedItem('r')?->nodeValue;
                preg_match('/^[A-Z]+/', $reference, $matches);
                $column = $matches[0] ?? '';
                if ($column === '') {
                    continue;
                }

                $type = (string) $cell->attributes->getNamedItem('t')?->nodeValue;
                $valueNode = $xpath->query('m:v', $cell)->item(0);
                $inlineNode = $xpath->query('m:is/m:t', $cell)->item(0);
                $raw = $valueNode?->textContent ?? $inlineNode?->textContent;

                $cells[$column] = $type === 's' && $raw !== null
                    ? ($sharedStrings[(int) $raw] ?? '')
                    : $raw;
            }

            $rows[$rowIndex] = $cells;
        }

        return $rows;
    }

    private function entryXml(ZipArchive $zip, string $entryName, bool $required = true): ?string
    {
        $content = $zip->getFromName($entryName);
        if ($content === false) {
            if ($required) {
                throw new RuntimeException("Entry XLSX tidak ditemukan: {$entryName}");
            }

            return null;
        }

        return $content;
    }

    private function integerValue(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return max(0, (int) round((float) $value));
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
