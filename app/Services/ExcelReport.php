<?php

namespace App\Services;

use DateTimeInterface;
use Illuminate\Support\Facades\File;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExcelReport
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * @param  list<array{name: string, headers: list<string>, rows: list<list<string|int|float|DateTimeInterface|null>>, widths: list<int>, note: string}>  $sheets
     */
    public function download(string $filename, string $period, array $sheets): StreamedResponse
    {
        // Build before sending headers so generation errors remain normal HTTP errors.
        // PHP's system temp directory may resolve to a protected Windows folder
        // under `artisan serve`; keep generated workbooks inside writable app storage.
        $temporaryDirectory = storage_path('app/report-temp');
        File::ensureDirectoryExists($temporaryDirectory);
        $path = tempnam($temporaryDirectory, 'ticket-xlsx-');
        if ($path === false) {
            throw new \RuntimeException('Cannot create Excel report');
        }
        try {
            $this->write($path, $period, $sheets);
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }

        return response()->streamDownload(function () use ($path): void {
            try {
                if (readfile($path) === false) {
                    throw new \RuntimeException('Cannot read Excel report');
                }
            } finally {
                @unlink($path);
            }
        }, $filename, ['Content-Type' => self::MIME, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * @param  list<array{name: string, headers: list<string>, rows: list<list<string|int|float|DateTimeInterface|null>>, widths: list<int>, note: string}>  $sheets
     */
    public function write(string $path, string $period, array $sheets): void
    {
        $options = new Options(
            DEFAULT_COLUMN_WIDTH: 20,
            DEFAULT_ROW_HEIGHT: 24,
            tempFolder: dirname($path),
        );
        $writer = new Writer($options);
        $writer->openToFile($path);
        $title = new Style(fontBold: true, fontSize: 18, fontColor: '0F766E');
        $header = new Style(fontBold: true, fontColor: 'FFFFFF', backgroundColor: '0F766E', shouldWrapText: true);
        $note = new Style(fontSize: 10, fontColor: '475569', shouldWrapText: true);
        try {
            foreach ($sheets as $index => $data) {
                $sheet = $index === 0 ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
                $sheet->setName($data['name']);
                $sheet->setSheetView(new SheetView(showGridLines: false, freezeRow: 6));
                foreach ($data['widths'] as $column => $width) {
                    $sheet->setColumnWidth($width, $column + 1);
                }
                $last = max(0, count($data['headers']) - 1);
                foreach ([1, 2, 3] as $row) {
                    $options->mergeCells(0, $row, $last, $row, $index);
                }
                $writer->addRow(new Row([new StringCell('Support Desk · '.$data['name'], $title)], 32));
                $writer->addRow(new Row([new StringCell($period, $note)], 32));
                $writer->addRow(new Row([new StringCell($data['note'], $note)], 64));
                $writer->addRow(new Row([]));
                $writer->addRow(new Row(array_map(fn (string $text) => new StringCell($text, $header), $data['headers']), 44));
                foreach ($data['rows'] as $rowIndex => $values) {
                    $cells = [];
                    foreach ($values as $value) {
                        $style = new Style(backgroundColor: $rowIndex % 2 === 0 ? 'F0FDFA' : 'FFFFFF', shouldWrapText: true,
                            format: $value instanceof DateTimeInterface ? ($value->format('H:i:s') === '00:00:00' ? 'yyyy-mm-dd' : 'yyyy-mm-dd hh:mm') : (is_float($value) ? '#,##0.00' : '#,##0'));
                        // Explicit text cells prevent agent/category names from becoming formulas.
                        $cells[] = is_string($value) ? new StringCell($value, $style) : Cell::fromValue($value, $style);
                    }
                    $writer->addRow(new Row($cells, 32));
                }
                if ($data['rows'] !== []) {
                    $sheet->setAutoFilter(new AutoFilter(0, 5, $last, count($data['rows']) + 5));
                }
            }
        } finally {
            $writer->close();
        }
    }
}
