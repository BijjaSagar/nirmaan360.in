<?php

namespace Tests\Feature\BoqImport;

use App\Models\BoqItem;
use App\Models\CustomRole;
use App\Models\Project;
use App\Models\User;
use App\Services\Reports\BoqExcelService;
use App\Support\Money;

/**
 * Rates are money: someone who may not see them cannot set them either, by hand or by a file. Their file's rates
 * are not written, whatever its layout: an item already in the BOQ keeps its rate, and a new line comes in at 0 for
 * someone who sees rates to price it. The page and the result say so, as the column check hides the rates from them.
 */
class RatesNeedMoneyTest extends BoqImportTestCase
{
    /** A role an owner made that manages the BOQ but may not see money. */
    private function moneyless(): User
    {
        $role = CustomRole::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'name' => 'Site BOQ clerk', 'permissions' => ['project.view', 'boq.view', 'boq.manage']]);
        $user = $this->member($this->org, $role->baseRole());
        $user->forceFill(['custom_role_id' => $role->id])->save();
        $this->assertFalse(Money::visible($user->fresh()));

        return $user->fresh();
    }

    private function item(Project $project): BoqItem
    {
        return BoqItem::create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'code' => 'C1', 'description' => 'Sample plaster on walls', 'unit' => 'sqm', 'qty' => '100.000', 'rate' => '12.00']);
    }

    /** @return array<string, array{0: string, 1: string}> Item code => quantity and rate, by code. */
    private function figures(Project $project): array
    {
        $figures = array_map(fn (BoqItem $item) => [$item->qty, $item->rate], $this->lines($project));
        ksort($figures);

        return $figures;
    }

    public function test_a_role_that_may_not_see_money_sets_no_rate_through_the_column_check(): void
    {
        $project = $this->makeProject($this->org);
        $this->item($project);
        $file = fn () => $this->csvFile("Item Code,Description,Unit,Quantity,Rate in Rs.\nC1,Sample plaster on walls,sqm,120,1200\nC2,Sample skirting in kota stone,sqm,30,300\n");

        $preview = $this->staged($project, $file(), $this->moneyless());
        $this->assertSame([], $preview['errors']);
        $this->assertSame(BoqExcelService::ratesNotImported(), $preview['notes'][0]['message']);
        $this->get(route('boq.sheet.preview', $project))->assertOk()->assertSee('Your role does not see rates')->assertDontSee('1,200');

        $this->confirm($project)->assertSessionHas('success');
        $this->assertContains(BoqExcelService::ratesNotImported(), array_column(session('boq_import.notes'), 'message'));
        $this->assertSame(['C1' => ['120.000', '12.00'], 'C2' => ['30.000', '0.00']], $this->figures($project));

        // Someone who sees money sets the same rates from the same file, and is not told otherwise.
        $preview = $this->staged($project, $file(), $this->pm);
        $this->assertNotContains(BoqExcelService::ratesNotImported(), array_column($preview['notes'], 'message'));
        $this->confirm($project)->assertSessionHas('success');
        $this->assertSame(['C1' => ['120.000', '1200.00'], 'C2' => ['30.000', '300.00']], $this->figures($project));
    }

    public function test_a_role_that_may_not_see_money_sets_no_rate_through_the_template_either(): void
    {
        $project = $this->makeProject($this->org);
        $this->item($project);

        $clerk = $this->moneyless();

        $this->upload($project, $this->workbook([$this->boqHeading(), ['', 'C1', 'Sample plaster on walls', 'sqm', 140, 77], ['', 'C2', 'Sample skirting in kota stone', 'sqm', 30, 300]]), $clerk)
            ->assertRedirect(route('boq.excel.index', $project))->assertSessionHas('success');

        $this->assertContains(BoqExcelService::ratesNotImported(), array_column(session('boq_import.notes'), 'message'));
        $this->assertSame(['C1' => ['140.000', '12.00'], 'C2' => ['30.000', '0.00']], $this->figures($project));

        // A file whose rates change nothing says nothing about them.
        $this->upload($project, $this->workbook([$this->boqHeading(), ['', 'C1', 'Sample plaster on walls', 'sqm', 150, 12]]), $clerk)->assertSessionHas('success');
        $this->assertSame([], session('boq_import.notes'));
        $this->assertSame('150.000', $this->lines($project)['C1']->qty);
    }
}
