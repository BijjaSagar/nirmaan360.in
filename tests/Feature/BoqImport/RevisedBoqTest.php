<?php

namespace Tests\Feature\BoqImport;

use App\Models\BoqItem;

/**
 * A revised BOQ imported again in the same layout: lines numbered by the sheet update their items in place, even
 * where the wording changed, and the page says beforehand how many lines update and how many are added. A line
 * numbered the same but plainly another item, or one whose code was made up here, never overwrites an item.
 */
class RevisedBoqTest extends BoqImportTestCase
{
    private const HEADING = ['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'];

    public function test_a_revised_boq_updates_its_items_in_place_and_says_so_first(): void
    {
        $project = $this->makeProject($this->org);
        $this->staged($project, $this->sheet([
            self::HEADING,
            [1, 'Sample excavation in soft soil', 'Cum', 80, 260, 20800],
            [2, 'Sample PCC 1:4:8', 'Cum', 12, 5400, 64800],
            [3, 'Sample brick masonry', 'Cum', 20, 5000, 100000],
        ]));
        $this->confirm($project)->assertSessionHas('success');

        $preview = $this->staged($project, $this->sheet([
            self::HEADING,
            [1, 'Sample excavation in soft soil and gravel', 'Cum', 90, 260, 23400],
            [2, 'Sample PCC 1:4:8', 'Cum', 12, 5600, 67200],
            [3, 'Sample brick masonry in CM 1:6', 'Cum', 24, 5000, 120000],
            [4, 'Sample plaster on walls', 'Sqm', 300, 250, 75000],
        ]));

        $this->assertSame(['update' => 3, 'add' => 1], $preview['plan']);
        $this->assertSame([], $preview['renumbered']);
        $this->assertSame([[2, '1'], [4, '3']], array_map(fn (array $line) => [$line['row'], $line['code']], $preview['updates']));
        $this->get(route('boq.sheet.preview', $project))->assertSee('Will update items already in the project')->assertSee('Will add 1 new items.')
            ->assertSee('Lines that change an item already in the project');

        $this->confirm($project)->assertSessionHas('success');
        $lines = $this->lines($project);
        $this->assertSame(['1', '2', '3', '4'], array_map('strval', array_keys($lines)));
        $this->assertSame(['Sample excavation in soft soil and gravel', '90.000'], [$lines[1]->description, $lines[1]->qty]);
        $this->assertSame(['Sample brick masonry in CM 1:6', '5600.00'], [$lines[3]->description, $lines[2]->rate]);
    }

    public function test_a_numbered_line_that_is_another_item_and_a_made_up_code_never_overwrite(): void
    {
        $project = $this->makeProject($this->org);
        $this->staged($project, $this->sheet([self::HEADING, [1, 'Sample excavation in soft soil', 'Cum', 80, 260, 20800], [2, 'Sample PCC 1:4:8', 'Cum', 12, 5400, 64800]]));
        $this->confirm($project)->assertSessionHas('success');

        // Numbered 1 and 2 again, but other items in other units: added under new codes.
        $preview = $this->staged($project, $this->sheet([self::HEADING, [1, 'Sample wiring point', 'Point', 40, 900, 36000], [2, 'Sample PCC 1:4:8 in footings', 'Cum', 4, 5400, 21600]]));
        $this->assertSame(['update' => 1, 'add' => 1], $preview['plan']);
        $this->assertSame([['1', '1-2']], array_map(fn (array $line) => [$line['from'], $line['to']], $preview['renumbered']));
        $this->assertSame([[3, '2']], array_map(fn (array $line) => [$line['row'], $line['code']], $preview['updates']));
        $this->confirm($project)->assertSessionHas('success');
        $this->assertSame('Sample excavation in soft soil', $this->lines($project)[1]->description);

        // A sheet with no numbers at all: its codes are made up here, so a changed wording is a new line, never an update.
        $other = $this->makeProject($this->org);
        $this->staged($other, $this->sheet([['Description', 'Unit', 'Qty', 'Rate'], ['Sample excavation in soft soil', 'Cum', 80, 260], ['Sample PCC 1:4:8', 'Cum', 12, 5400]]));
        $this->confirm($other)->assertSessionHas('success');
        $preview = $this->staged($other, $this->sheet([['Description', 'Unit', 'Qty', 'Rate'], ['Sample excavation in soft soil and gravel', 'Cum', 80, 260], ['Sample PCC 1:4:8', 'Cum', 12, 5600]]));

        $this->assertSame(['update' => 1, 'add' => 1], $preview['plan']);
        $this->assertSame([['1', '1-2']], array_map(fn (array $line) => [$line['from'], $line['to']], $preview['renumbered']));
        $this->confirm($other)->assertSessionHas('success');
        $this->assertSame(3, BoqItem::where('project_id', $other->id)->count());
    }
}
