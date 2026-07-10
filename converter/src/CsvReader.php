<?php
class CsvReader {
    /**
     * Read a CSV file and return array of rows.
     * Each row is an array of trimmed string values. Empty lines return as [].
     * Handles UTF-8 BOM.
     */
    public static function readFile(string $path): array {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Cannot read file");
        }
        return self::parseContent($content);
    }

    public static function parseContent(string $content): array {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = explode("\n", $content);

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                $rows[] = [];
                continue;
            }
            $parsed = str_getcsv($line);
            $rows[] = array_map('trim', $parsed);
        }
        return $rows;
    }
}
