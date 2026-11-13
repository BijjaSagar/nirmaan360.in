<?php

namespace Tests\Feature\Finance;

use App\Models\CustomRole;
use App\Models\FinanceBudgetLine;
use App\Models\User;
use App\Models\VendorBill;

/**
 * Only the owner, admin and project manager (money.overview) see a project's budget, total cost, margin
 * or budget against spend. The accountant and the QS keep the entries they work with: vendor bills,
 * payments, receipts, invoices and the accounting reports.
 */
class OverallSpendAccessTest extends FinanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $bill = $this->approvedVendorBill();
        $this->pay($bill, '1000');
        $this->receive($this->approvedClientBill(), '1000');
    }

    private function as(string $role): User
    {
        $user = match ($role) {
            'owner' => $this->owner,
            'accounts' => $this->accounts,
            'pm' => $this->pm,
            default => $this->member($this->organization, $role),
        };
        $this->actingAs($user);

        return $user;
    }

    public function test_owner_admin_and_project_manager_open_the_ledger_budget_and_cost_variance(): void
    {
        foreach (['owner', 'admin', 'pm'] as $role) {
            $this->as($role);

            $this->get(route('finance.project.home', $this->project))->assertRedirect(route('finance.project.ledger', $this->project));
            $this->get(route('finance.project.ledger', $this->project))->assertOk()->assertSee('Net position');
            $this->get(route('finance.project.budget', $this->project))->assertOk()->assertSee('Billed less cost');
            $this->get(route('contractor-control.variance', $this->project))->assertOk();
        }

        $this->as('pm');
        $this->patch(route('finance.project.budget.update', $this->project), ['budget' => ['Materials' => '150000']])->assertSessionHasNoErrors();
        $this->assertSame('150000.00', FinanceBudgetLine::where('project_id', $this->project->id)->sole()->amount);
    }

    public function test_the_accountant_and_the_qs_are_refused_the_overall_spend(): void
    {
        foreach (['accounts', 'qs'] as $role) {
            $this->as($role);

            $this->get(route('finance.project.ledger', $this->project))->assertForbidden();
            $this->get(route('finance.project.budget', $this->project))->assertForbidden();
            $this->patch(route('finance.project.budget.update', $this->project), ['budget' => ['Materials' => '1']])->assertForbidden();
            $this->get(route('contractor-control.variance', $this->project))->assertForbidden();
            $this->get(route('planning.budget', $this->project))->assertForbidden();
            $this->get(route('project-setup.budget', $this->project))->assertForbidden();
        }

        $this->assertSame(0, FinanceBudgetLine::count());
    }

    public function test_the_accountant_keeps_the_entries_and_accounting_reports(): void
    {
        $this->as('accounts');
        $bill = VendorBill::where('status', '!=', 'DRAFT')->firstOrFail();

        // The Finance menu item lands on vendor bills, not on a refusal.
        $this->get(route('finance.project.home', $this->project))->assertRedirect(route('finance.project.vendor-bills.index', $this->project));

        $page = $this->get(route('finance.project.vendor-bills.index', $this->project))->assertOk();
        $page->assertDontSee(route('finance.project.ledger', $this->project))->assertDontSee(route('finance.project.budget', $this->project));

        $this->get(route('finance.project.vendor-bills.show', $bill))->assertOk();
        $this->get(route('finance.project.vendor-bills.create', $this->project))->assertOk();
        $this->get(route('finance.project.payments', $this->project))->assertOk();
        $this->get(route('finance.project.receipts', $this->project))->assertOk();
        $this->get(route('finance.project.invoices', $this->project))->assertOk();

        // Pure accounting outputs stay: GST, TDS, the cash-flow forecast from unsettled bills, and Tally.
        foreach (['finance.reports.gstr1', 'finance.reports.tds', 'finance.reports.cash-flow', 'finance.reports.tally', 'finance.reports.tally.vouchers', 'finance.reports.tally.ledgers'] as $name) {
            $this->get(route($name))->assertOk();
        }

        // Petty cash entries are still theirs, and so is the contractor payments list.
        $this->get(route('petty.index', $this->project))->assertOk();
        $this->get(route('contractor-control.payments.index', $this->project))->assertOk();
    }

    public function test_the_menu_hides_what_a_role_cannot_open(): void
    {
        $this->as('qs');
        $this->get(route('projects.show', $this->project))->assertOk()
            ->assertSee(route('finance.project.home', $this->project))
            ->assertDontSee(route('finance.project.ledger', $this->project))
            ->assertDontSee(route('contractor-control.variance', $this->project));

        $this->as('pm');
        $this->get(route('projects.show', $this->project))->assertOk()
            ->assertSee(route('finance.project.home', $this->project))
            ->assertSee(route('contractor-control.variance', $this->project));

        $this->get(route('finance.project.vendor-bills.index', $this->project))->assertOk()
            ->assertSee(route('finance.project.ledger', $this->project))
            ->assertSee(route('finance.project.budget', $this->project));
    }

    public function test_a_custom_role_needs_money_overview_for_the_overall_spend(): void
    {
        $role = CustomRole::create(['organization_id' => $this->organization->id, 'name' => 'Site accountant', 'permissions' => ['project.view', 'finance.view', 'money.view'], 'created_by' => $this->owner->id]);
        $clerk = $this->member($this->organization, 'accounts');
        $clerk->forceFill(['custom_role_id' => $role->id])->save();

        $this->actingAs($clerk->fresh());
        $this->get(route('finance.project.ledger', $this->project))->assertForbidden();
        $this->get(route('finance.project.vendor-bills.index', $this->project))->assertOk();

        $role->update(['permissions' => [...$role->permissions, 'money.overview']]);
        $this->actingAs($clerk->fresh());
        $this->get(route('finance.project.ledger', $this->project))->assertOk();
    }

    public function test_another_company_never_reaches_these_screens(): void
    {
        $this->actingAs($this->member($this->tenant('Rival Infra'), 'owner'));

        foreach (['finance.project.home', 'finance.project.ledger', 'finance.project.budget', 'contractor-control.variance'] as $name) {
            $this->get(route($name, $this->project))->assertNotFound();
        }

        $this->patch(route('finance.project.budget.update', $this->project), ['budget' => ['Materials' => '1']])->assertNotFound();
        $this->assertSame(0, FinanceBudgetLine::withoutGlobalScopes()->count());
    }
}
