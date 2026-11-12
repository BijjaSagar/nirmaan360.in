<?php

namespace Tests\Feature\DocumentReading;

use App\Services\DocumentReading\Cell;
use App\Services\DocumentReading\PageStrip;
use App\Services\DocumentReading\ShapeCheck;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ShapeCheckTest extends TestCase
{
    /** A schema of the kind a reader uses: a page remark, and rows of cells with a position. */
    private const PAGE = [
        'type' => 'object',
        'properties' => [
            'page_kind' => ['type' => 'string', 'enum' => ['table', 'blank', 'other']],
            'rows' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'y_top' => ['type' => 'integer'],
                        'y_bottom' => ['type' => 'integer'],
                        'description' => ['type' => 'string'],
                        'qty' => Cell::SCHEMA,
                    ],
                    'required' => ['y_top', 'y_bottom', 'description', 'qty'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['page_kind', 'rows'],
        'additionalProperties' => false,
    ];

    private static function page(array $row = [], array $top = []): array
    {
        return [
            'page_kind' => 'table',
            'rows' => [
                ['y_top' => 120, 'y_bottom' => 160, 'description' => 'Excavation in soft soil', 'qty' => ['raw' => '12.500', 'state' => 'read'], ...$row],
            ],
            ...$top,
        ];
    }

    public function test_output_in_the_shape_of_the_schema_passes(): void
    {
        $this->assertSame([], ShapeCheck::errors(self::page(), self::PAGE));
        $this->assertTrue(ShapeCheck::passes(self::page(), self::PAGE));
        $this->assertTrue(ShapeCheck::passes(['page_kind' => 'blank', 'rows' => []], self::PAGE));
        $this->assertTrue(ShapeCheck::passes(json_decode('{"raw":"","state":"absent"}', true), Cell::SCHEMA));
    }

    public static function wrongShapes(): array
    {
        return [
            'a key is missing' => [['page_kind' => 'table'], '$.rows is missing.'],
            'a key was added' => [self::page(top: ['note' => 'x']), '$.note is not part of the form.'],
            'a key was added inside a row' => [self::page(['amount' => ['raw' => '5', 'state' => 'read']]), '$.rows[0].amount is not part of the form.'],
            'a cell lost its remark' => [self::page(['qty' => ['raw' => '5']]), '$.rows[0].qty.state is missing.'],
            'a cell is a bare string' => [self::page(['qty' => '12.500']), '$.rows[0].qty must be an object.'],
            'a cell is a list' => [self::page(['qty' => ['12.500', 'read']]), '$.rows[0].qty must be an object.'],
            'a number where text belongs' => [self::page(['description' => 12]), '$.rows[0].description must be text.'],
            'a figure sent as a number' => [self::page(['qty' => ['raw' => 12.5, 'state' => 'read']]), '$.rows[0].qty.raw must be text.'],
            'null where text belongs' => [self::page(['description' => null]), '$.rows[0].description must be text.'],
            'a remark outside the set' => [self::page(['qty' => ['raw' => '5', 'state' => 'certain']]), '$.rows[0].qty.state must be one of: read, unclear, absent.'],
            'a page kind outside the set' => [self::page(top: ['page_kind' => 'invoice']), '$.page_kind must be one of: table, blank, other.'],
            'a position as text' => [self::page(['y_top' => '120']), '$.rows[0].y_top must be a whole number.'],
            'a position with a fraction' => [self::page(['y_top' => 120.5]), '$.rows[0].y_top must be a whole number.'],
            'a position as true' => [self::page(['y_top' => true]), '$.rows[0].y_top must be a whole number.'],
            'rows as an object' => [['page_kind' => 'table', 'rows' => ['first' => []]], '$.rows must be a list.'],
            'rows as text' => [['page_kind' => 'table', 'rows' => 'none'], '$.rows must be a list.'],
            'a row that is text' => [['page_kind' => 'table', 'rows' => ['Excavation']], '$.rows[0] must be an object.'],
            'the whole thing is a list' => [[self::page()], '$ must be an object.'],
            'the whole thing is text' => ['{"page_kind":"table"}', '$ must be an object.'],
            'nothing' => [null, '$ must be an object.'],
        ];
    }

    #[DataProvider('wrongShapes')]
    public function test_output_in_any_other_shape_fails(mixed $value, string $problem): void
    {
        $errors = ShapeCheck::errors($value, self::PAGE);

        $this->assertContains($problem, $errors);
        $this->assertFalse(ShapeCheck::passes($value, self::PAGE));
    }

    public function test_fixed_sets_are_compared_without_regard_to_case_and_come_out_in_lower_case(): void
    {
        $output = self::page(['qty' => ['raw' => 'Read', 'state' => 'UNCLEAR']], ['page_kind' => 'Table']);

        $this->assertTrue(ShapeCheck::passes($output, self::PAGE));

        $lowered = ShapeCheck::lowered($output, self::PAGE);

        $this->assertSame('table', $lowered['page_kind']);
        $this->assertSame('unclear', $lowered['rows'][0]['qty']['state']);

        // Only fixed-set values change. What was printed is never touched.
        $this->assertSame('Read', $lowered['rows'][0]['qty']['raw']);
        $this->assertSame('Excavation in soft soil', $lowered['rows'][0]['description']);
        $this->assertSame(120, $lowered['rows'][0]['y_top']);
    }

    public function test_a_problem_names_the_place_and_never_the_value(): void
    {
        $errors = ShapeCheck::errors(self::page(['description' => ['Bill no. 98765'], 'qty' => ['raw' => '5', 'state' => 'Invented Supplier']]), self::PAGE);

        $this->assertCount(2, $errors);
        $this->assertStringNotContainsString('98765', implode(' ', $errors));
        $this->assertStringNotContainsString('Invented Supplier', implode(' ', $errors));
    }

    public function test_a_reply_that_is_wrong_everywhere_lists_twenty_problems_at_most(): void
    {
        $rows = array_fill(0, 500, ['y_top' => 'a', 'y_bottom' => 'b', 'description' => 1, 'qty' => 2, 'extra' => 3]);

        $this->assertCount(20, ShapeCheck::errors(['page_kind' => 'table', 'rows' => $rows], self::PAGE));
    }

    public function test_a_schema_the_check_cannot_read_never_lets_output_through(): void
    {
        $this->assertFalse(ShapeCheck::passes('anything', []));
        $this->assertFalse(ShapeCheck::passes(1.5, ['type' => 'number']));
        $this->assertFalse(ShapeCheck::passes(true, ['type' => 'boolean']));
        $this->assertFalse(ShapeCheck::passes('x', ['type' => ['string', 'null']]));
        $this->assertFalse(ShapeCheck::passes(['x'], ['type' => 'array']));
        $this->assertTrue(ShapeCheck::passes([], ['type' => 'array']));
    }

    public function test_the_cell_schema_and_a_reader_schema_follow_the_rules(): void
    {
        $this->assertSame([], ShapeCheck::schemaProblems(Cell::SCHEMA));
        $this->assertSame([], ShapeCheck::schemaProblems(self::PAGE));
    }

    public static function brokenSchemas(): array
    {
        $cell = Cell::SCHEMA;

        return [
            'an optional property' => [[...$cell, 'required' => ['raw']], '$ must require every property it lists.'],
            'a required key that is not a property' => [[...$cell, 'required' => ['raw', 'state', 'page']], '$ must require every property it lists.'],
            'extra keys allowed' => [[...$cell, 'additionalProperties' => true], '$ must set additionalProperties to false.'],
            'extra keys not ruled out' => [array_diff_key($cell, ['additionalProperties' => 1]), '$ must set additionalProperties to false.'],
            'an object without properties' => [['type' => 'object', 'additionalProperties' => false], '$ must list its properties.'],
            'a choice of forms' => [['anyOf' => [['type' => 'string'], ['type' => 'integer']]], '$ uses "anyOf", which the reader schemas do not use.'],
            'a reference' => [['$ref' => '#/$defs/cell'], '$ uses "$ref", which the reader schemas do not use.'],
            'two types' => [['type' => ['string', 'null']], '$ has a type the reader schemas do not use.'],
            'a number that is not whole' => [['type' => 'number'], '$ has a type the reader schemas do not use.'],
            'a length limit the provider would refuse' => [['type' => 'string', 'maxLength' => 40], '$ uses "maxLength", which the reader schemas do not use.'],
            'a fixed set in capitals' => [['type' => 'string', 'enum' => ['Read', 'unclear']], '$ has a fixed-set value that is not lower-case ASCII.'],
            'an empty fixed set' => [['type' => 'string', 'enum' => []], '$ has an empty fixed set.'],
            'a fixed set of numbers' => [['type' => 'integer', 'enum' => [1, 2]], '$ has a fixed set on something that is not a string.'],
            'a list of anything' => [['type' => 'array'], '$ must say what its items are.'],
            'a fault deep inside' => [
                ['type' => 'object', 'properties' => ['rows' => ['type' => 'array', 'items' => [...$cell, 'required' => []]]], 'required' => ['rows'], 'additionalProperties' => false],
                '$.rows[] must require every property it lists.',
            ],
        ];
    }

    #[DataProvider('brokenSchemas')]
    public function test_a_schema_that_breaks_a_rule_is_named(array $schema, string $problem): void
    {
        $this->assertContains($problem, ShapeCheck::schemaProblems($schema));
    }

    public function test_the_cell_remark_only_ever_adds_review_work(): void
    {
        $this->assertSame(['read', 'unclear', 'absent'], Cell::STATES);

        $this->assertSame('read', Cell::state(['raw' => '12.500', 'state' => 'read']));
        $this->assertSame('unclear', Cell::state(['raw' => '12.500', 'state' => 'Unclear']));
        $this->assertSame('absent', Cell::state(['raw' => '', 'state' => 'absent']));

        // Text marked absent is not dropped; an empty cell marked read holds nothing.
        $this->assertSame('unclear', Cell::state(['raw' => '12.500', 'state' => 'absent']));
        $this->assertSame('absent', Cell::state(['raw' => '   ', 'state' => 'read']));
        $this->assertSame('unclear', Cell::state(['raw' => '', 'state' => 'unclear']));
        $this->assertSame('unclear', Cell::state(['raw' => '5', 'state' => 'certain']));
        $this->assertSame('unclear', Cell::state([]));

        $this->assertSame('12.500', Cell::raw(['raw' => "  12.500\n", 'state' => 'read']));
        $this->assertSame('', Cell::raw([]));
    }

    public function test_a_row_position_is_kept_in_parts_per_thousand_of_the_page(): void
    {
        $this->assertSame(['top' => 100, 'bottom' => 200], PageStrip::perThousand(230, 460, 2300));
        $this->assertSame(['top' => 0, 'bottom' => 1000], PageStrip::perThousand(0, 2300, 2300));

        // Rounded outwards, so the band never loses part of the row.
        $this->assertSame(['top' => 43, 'bottom' => 53], PageStrip::perThousand(100, 120, 2296));

        // Rows outside the picture are pulled in; a band given upside down is turned round.
        $this->assertSame(['top' => 0, 'bottom' => 100], PageStrip::perThousand(-40, 230, 2300));
        $this->assertSame(['top' => 900, 'bottom' => 1000], PageStrip::perThousand(2070, 9999, 2300));
        $this->assertSame(['top' => 1000, 'bottom' => 1000], PageStrip::perThousand(5000, 6000, 2300));
        $this->assertSame(['top' => 100, 'bottom' => 200], PageStrip::perThousand(460, 230, 2300));

        $this->expectException(InvalidArgumentException::class);
        PageStrip::perThousand(10, 20, 0);
    }
}
