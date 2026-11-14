<?php

namespace Tests\Feature\BoqImport\Corpus;

use App\Services\Imports\BoqHeadings;
use App\Services\Imports\BoqSheetImport;
use App\Services\Imports\BoqSheetRows;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\BoqImport\BoqImportTestCase;

/**
 * The golden corpus of BOQ sheet shapes. Each case in cases/ is a sheet (rows of cells, or a workbook built here)
 * with invented text and figures, and beside it, in a .txt file of the same name, exactly how it is read: the
 * heading, what each column holds, every row's kind, every line's code, section, description, unit, quantity and
 * rate, the adjustments, the totals and the notes. A change to the row rules that alters how any case is read
 * fails here, so a fix for one shape cannot quietly break another; when the change is meant, the .txt is edited
 * by hand with it.
 *
 * Set CORPUS_ACTUAL to a folder to have every case's reading written there for comparison.
 */
class CorpusTest extends BoqImportTestCase
{
    private const DIR = __DIR__.'/cases';

    /** @return iterable<string, array{0: string}> */
    public static function cases(): iterable
    {
        foreach (glob(self::DIR.'/*.php') ?: [] as $file) {
            $name = basename($file, '.php');

            yield $name => [$name];
        }
    }

    #[DataProvider('cases')]
    public function test_the_case_is_read_exactly_as_written_down(string $name): void
    {
        $case = require self::DIR.'/'.$name.'.php';
        $actual = $this->reading($case);

        if (is_string($folder = getenv('CORPUS_ACTUAL')) && $folder !== '' && is_dir($folder)) {
            file_put_contents($folder.'/'.$name.'.txt', $actual);
        }

        $this->assertFileExists(self::DIR.'/'.$name.'.txt', 'Every case needs its expected reading beside it.');
        $this->assertSame(file_get_contents(self::DIR.'/'.$name.'.txt'), $actual, $name.': '.$case['about']);
    }

    public function test_every_case_says_what_it_is_and_has_its_reading(): void
    {
        $names = array_map(fn (array $case) => $case[0], iterator_to_array(self::cases()));
        $this->assertGreaterThan(50, count($names));

        foreach ($names as $name) {
            $case = require self::DIR.'/'.$name.'.php';
            $this->assertNotSame('', trim((string) ($case['about'] ?? '')), $name);
            $this->assertTrue(isset($case['rows']) !== isset($case['file']), $name.' is either rows or a file');
        }

        foreach (glob(self::DIR.'/*.txt') ?: [] as $expected) {
            $this->assertFileExists(substr($expected, 0, -4).'.php', 'A reading with no case: '.basename($expected));
        }
    }

    private function reading(array $case): string
    {
        if (isset($case['rows'])) {
            $grid = $this->grid($case['rows']);
            $merges = array_map([self::class, 'range'], $case['merges'] ?? []);
            $layout = (new BoqHeadings)->detect($grid, $merges);
            $read = (new BoqSheetRows)->classify($grid, $layout, $case['unit'] ?? null, ['merges' => $merges]);

            return CorpusRender::render($read, $layout);
        }

        $inspected = app(BoqSheetImport::class)->inspect($this->org->id, $this->file($case['file']));

        if ($inspected['error'] !== null) {
            return 'file: '.($inspected['format'] ?? 'unknown').' | refused: '.$inspected['error']."\n";
        }

        return CorpusRender::render($inspected, $inspected['layout'], $inspected);
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} */
    private static function range(string $range): array
    {
        [[$left, $top], [$right, $bottom]] = Coordinate::rangeBoundaries($range);

        return [(int) $top, (int) $left, (int) $bottom, (int) $right];
    }

    /**
     * A workbook written exactly as the case gives it: numbers as numbers, text as text (so "011010" stays text),
     * "=..." as a formula, with hidden sheets, merged cells and number formats where the case asks for them.
     */
    private function file(array $spec): string
    {
        $writer = $spec['writer'] ?? 'Xlsx';
        $path = tempnam(sys_get_temp_dir(), 'corpus').'.'.strtolower($writer);

        if (isset($spec['raw'])) {
            file_put_contents($path, $spec['raw']);

            return $path;
        }

        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);

        foreach ($spec['sheets'] as $title => $rows) {
            $sheet = $book->createSheet();
            $sheet->setTitle($title);

            foreach ($rows as $r => $cells) {
                foreach (array_values($cells) as $c => $value) {
                    if ($value === null || $value === '') {
                        continue;
                    }

                    if (is_string($value) && ! str_starts_with($value, '=')) {
                        $sheet->setCellValueExplicit([$c + 1, $r + 1], $value, DataType::TYPE_STRING);
                    } else {
                        $sheet->setCellValue([$c + 1, $r + 1], $value);
                    }
                }
            }

            foreach ($spec['merges'][$title] ?? [] as $range) {
                $sheet->mergeCells($range);
            }

            foreach ($spec['formats'][$title] ?? [] as $range => $format) {
                $sheet->getStyle($range)->getNumberFormat()->setFormatCode($format);
            }

            if (isset($spec['states'][$title])) {
                $sheet->setSheetState($spec['states'][$title]);
            }
        }

        foreach ($spec['cells'] ?? [] as $title => $cells) {
            foreach ($cells as $address => $value) {
                $book->getSheetByName($title)->setCellValue($address, $value);
            }
        }

        $book->setActiveSheetIndex($spec['active'] ?? 0);
        $file = IOFactory::createWriter($book, $writer);

        if (method_exists($file, 'setPreCalculateFormulas')) {
            $file->setPreCalculateFormulas($spec['formula_results'] ?? true);
        }

        $file->save($path);
        $book->disconnectWorksheets();

        return $path;
    }
}
