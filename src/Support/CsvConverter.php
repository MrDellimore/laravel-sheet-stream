<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Support;

use MrDellimore\SheetStream\Exceptions\CsvConversionException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use ZipArchive;

final readonly class CsvConverter
{
    public function __construct(
        private ?string $binary = null,
        private ?string $tempDir = null,
        private int $timeoutSeconds = 3600,
    ) {}

    /**
     * Create an instance using the Laravel config values.
     */
    public static function fromConfig(): self
    {
        return new self(
            binary: config('sheet-stream.csv_converter.binary'),
            tempDir: config('sheet-stream.temp_path'),
            timeoutSeconds: (int) config('sheet-stream.csv_converter.timeout', 3600),
        );
    }

    /**
     * Convert an XLSX or ODS file to one CSV per sheet.
     */
    public function convert(string $spreadsheetPath): ConversionResult
    {
        $binary = $this->resolveBinary();
        $sheetNames = $this->extractSheetNames($spreadsheetPath);
        $csvPaths = $this->runConversion($binary, $spreadsheetPath, count($sheetNames) ?: 1);

        return new ConversionResult($csvPaths, $sheetNames);
    }

    /**
     * Check whether a supported converter binary is available.
     */
    public function isAvailable(): bool
    {
        try {
            $this->resolveBinary();

            return true;
        } catch (CsvConversionException) {
            return false;
        }
    }

    private function resolveBinary(): string
    {
        if ($this->binary !== null && $this->binary !== '') {
            if ($this->binaryExists($this->binary)) {
                return $this->binary;
            }

            throw new CsvConversionException(
                "Configured CSV converter not found: {$this->binary}"
            );
        }

        // Auto-detect supported converters
        $finder = new ExecutableFinder;

        foreach (['ssconvert', 'xlsx2csv'] as $name) {
            $path = $finder->find($name);

            if ($path !== null) {
                return $path;
            }
        }

        throw new CsvConversionException(
            'No CSV converter binary found. Install gnumeric (provides ssconvert) '
            .'or xlsx2csv (pip install xlsx2csv).'
        );
    }

    private function binaryExists(string $binary): bool
    {
        // Absolute path
        if (str_starts_with($binary, '/')) {
            return is_executable($binary);
        }

        // Search PATH
        return (new ExecutableFinder)->find($binary) !== null;
    }

    /**
     * Extract sheet names from an XLSX file's workbook.xml metadata.
     *
     * @return array<int, string>
     */
    private function extractSheetNames(string $path): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension !== 'xlsx') {
            return [];
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return [];
        }

        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $zip->close();

        if ($workbookXml === false) {
            return [];
        }

        $xml = @simplexml_load_string($workbookXml);

        if ($xml === false || ! isset($xml->sheets->sheet)) {
            return [];
        }

        $names = [];

        foreach ($xml->sheets->sheet as $sheet) {
            $names[] = (string) $sheet['name'];
        }

        return $names;
    }

    private function resolveTempDir(): string
    {
        return $this->tempDir ?? sys_get_temp_dir();
    }

    /**
     * @return array<int, string> CSV paths indexed by sheet index
     */
    private function runConversion(string $binary, string $spreadsheetPath, int $expectedSheets): array
    {
        $binaryName = basename($binary);

        return match (true) {
            str_contains($binaryName, 'ssconvert') => $this->convertWithSsconvert($binary, $spreadsheetPath, $expectedSheets),
            str_contains($binaryName, 'xlsx2csv') => $this->convertWithXlsx2csv($binary, $spreadsheetPath, $expectedSheets),
            default => throw new CsvConversionException("Unsupported converter binary: {$binary}"),
        };
    }

    /**
     * @return array<int, string>
     */
    private function convertWithSsconvert(string $binary, string $spreadsheetPath, int $expectedSheets): array
    {
        $baseName = $this->resolveTempDir().'/sheet_stream_csv_'.uniqid();
        $outputPattern = $baseName.'.csv';

        $process = new Process([$binary, '-S', $spreadsheetPath, $outputPattern]);
        $process->setTimeout($this->timeoutSeconds);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new CsvConversionException(
                'ssconvert failed (exit '.$process->getExitCode().'): '.$process->getErrorOutput()
            );
        }

        // ssconvert with -S creates: base.csv.0, base.csv.1, etc.
        // Rename to .csv so OpenSpout recognises the extension.
        $paths = [];

        for ($i = 0; $i < $expectedSheets; $i++) {
            $ssconvertPath = $baseName.".csv.{$i}";

            if (file_exists($ssconvertPath)) {
                $csvPath = $baseName."_sheet{$i}.csv";
                rename($ssconvertPath, $csvPath);
                $paths[$i] = $csvPath;
            }
        }

        if ($paths === []) {
            // Single-sheet file may produce just base.csv
            if (file_exists($outputPattern)) {
                $paths[0] = $outputPattern;
            }
        }

        if ($paths === []) {
            throw new CsvConversionException(
                'ssconvert produced no output files. Expected at: '.$baseName.'.csv.*'
            );
        }

        return $paths;
    }

    /**
     * @return array<int, string>
     */
    private function convertWithXlsx2csv(string $binary, string $spreadsheetPath, int $expectedSheets): array
    {
        $baseName = $this->resolveTempDir().'/sheet_stream_csv_'.uniqid();

        // xlsx2csv can export all sheets with -s 0 and -n for sheet-specific output
        $paths = [];

        for ($i = 0; $i < $expectedSheets; $i++) {
            $outputPath = $baseName."_{$i}.csv";

            // xlsx2csv uses 1-based sheet numbers
            $process = new Process([$binary, '-s', (string) ($i + 1), $spreadsheetPath, $outputPath]);
            $process->setTimeout($this->timeoutSeconds);
            $process->run();

            if ($process->isSuccessful() && file_exists($outputPath)) {
                $paths[$i] = $outputPath;
            }
        }

        if ($paths === []) {
            throw new CsvConversionException(
                'xlsx2csv produced no output files for: '.$spreadsheetPath
            );
        }

        return $paths;
    }
}
