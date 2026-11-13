<?php

namespace Tests\Feature\Finance;

use App\Models\Approval;
use App\Models\ClientReceipt;
use App\Models\GstInvoice;
use App\Models\Party;
use App\Models\RaBill;
use App\Models\VendorBill;
use App\Models\VendorPayment;
use App\Services\Finance\GstInvoiceService;
use App\Services\RaBillService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

class FinanceHttpTest extends FinanceTestCase
{
    /** Screens that show the project's budget, total cost or margin, and so need money.overview as well. */
    private const OVERVIEW_ROUTES = ['finance.project.ledger', 'finance.project.budget', 'finance.project.budget.update'];

    private RaBill $clientBill;

    private VendorBill $approvedBill;

    private VendorBill $draftBill;

    private VendorPayment $payment;

    private ClientReceipt $receipt;

    private GstInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientBill = $this->approvedClientBill();
        $this->approvedBill = $this->approvedVendorBill();
        $this->draftBill = $this->draftVendorBill(['bill_no' => 'DRAFT-1']);
        $this->payment = $this->pay($this->approvedBill, '1000');
        $this->receipt = $this->receive($this->clientBill, '1000', '10');
        $this->invoice = app(GstInvoiceService::class)->issue($this->clientBill, ['invoice_date' => '2026-11-20', 'sac' => '9954', 'description' => 'Works contract services'], $this->accounts);
    }

    /** @return list<array{0:string, 1:string, 2:array<string,mixed>}> Method, route name, parameters. */
    private function routes(): array
    {
        $project = ['project' => $this->project->id];

        return [
            ['get', 'finance.project.home', $project],
            ['get', 'finance.project.ledger', $project],
            ['get', 'finance.project.budget', $project],
            ['patch', 'finance.project.budget.update', $project],
            ['get', 'finance.project.vendor-bills.index', $project],
            ['get', 'finance.project.vendor-bills.create', $project],
            ['post', 'finance.project.vendor-bills.store', $project],
            ['get', 'finance.project.payments', $project],
            ['get', 'finance.project.receipts', $project],
            ['get', 'finance.project.invoices', $project],
            ['get', 'finance.project.invoices.pdf', ['invoice' => $this->invoice->id]],
            ['get', 'finance.project.vendor-bills.show', ['vendorBill' => $this->approvedBill->id]],
            ['get', 'finance.project.vendor-bills.edit', ['vendorBill' => $this->draftBill->id]],
            ['patch', 'finance.project.vendor-bills.update', ['vendorBill' => $this->draftBill->id]],
            ['post', 'finance.project.vendor-bills.submit', ['vendorBill' => $this->draftBill->id]],
            ['post', 'finance.project.vendor-bills.cancel', ['vendorBill' => $this->draftBill->id]],
            ['post', 'finance.project.vendor-bills.reverse', ['vendorBill' => $this->approvedBill->id]],
            ['post', 'finance.project.payments.vendor', ['vendorBill' => $this->approvedBill->id]],
            ['post', 'finance.project.payments.subcontractor', ['raBill' => $this->clientBill->id]],
            ['post', 'finance.project.payments.reverse', ['payment' => $this->payment->id]],
            ['post', 'finance.project.receipts.store', ['raBill' => $this->clientBill->id]],
            ['post', 'finance.project.receipts.reverse', ['receipt' => $this->receipt->id]],
            ['post', 'finance.project.invoices.store', ['raBill' => $this->clientBill->id]],
            ['patch', 'finance.project.invoices.irn', ['invoice' => $this->invoice->id]],
            ['post', 'finance.project.invoices.cancel', ['invoice' => $this->invoice->id]],
        ];
    }

    /** @return list<string> */
    private function reportRoutes(): array
    {
        return [
            'finance.reports.gstr1', 'finance.reports.gstr1.excel', 'finance.reports.gstr1.json', 'finance.reports.tds',
            'finance.reports.tds.excel', 'finance.reports.cash-flow', 'finance.reports.tally', 'finance.reports.tally.vouchers', 'finance.reports.tally.ledgers',
        ];
    }

    private function hit(string $method, string $name, array $parameters = []): TestResponse
    {
        return $this->{$method}(route($name, $parameters));
    }

    public function test_all_finance_routes_are_listed_in_these_checks(): void
    {
        $declared = collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn ($name) => str_starts_with($name, 'finance.'))->sort()->values()->all();
        $listed = collect($this->routes())->pluck(1)->concat($this->reportRoutes())->sort()->values()->all();

        $this->assertSame($declared, $listed);
    }

    public function test_every_project_route_needs_the_right_permission(): void
    {
        $this->actingAs($this->member($this->organization, 'engineer'));

        foreach ($this->routes() as [$method, $name, $parameters]) {
            $this->hit($method, $name, $parameters)->assertForbidden();
        }
    }

    public function test_every_report_route_needs_the_report_permission(): void
    {
        foreach (['pm', 'qs', 'engineer', 'viewer'] as $role) {
            $this->actingAs($this->member($this->organization, $role));

            foreach ($this->reportRoutes() as $name) {
                $this->get(route($name))->assertForbidden();
            }
        }
    }

    public function test_a_view_only_role_can_read_but_not_write(): void
    {
        $this->actingAs($this->member($this->organization, 'qs'));

        foreach ($this->routes() as [$method, $name, $parameters]) {
            $response = $this->hit($method, $name, $parameters);
            $writes = $method !== 'get' || str_ends_with($name, '.create') || str_ends_with($name, '.edit');

            // The ledger and the budget are the project's overall spend: money.overview, which the QS lacks.
            match (true) {
                $name === 'finance.project.home' => $response->assertRedirect(route('finance.project.vendor-bills.index', $this->project)),
                $writes, in_array($name, self::OVERVIEW_ROUTES, true) => $response->assertForbidden(),
                default => $response->assertOk(),
            };
        }
    }

    public function test_every_route_hides_another_companys_records(): void
    {
        $outsider = $this->member($this->tenant('Rival Infra'), 'owner');
        $this->actingAs($outsider);

        foreach ($this->routes() as [$method, $name, $parameters]) {
            $this->hit($method, $name, $parameters)->assertNotFound();
        }

        $this->assertSame('DRAFT', VendorBill::withoutGlobalScopes()->find($this->draftBill->id)->status);
    }

    public function test_reports_only_show_the_signed_in_company(): void
    {
        $this->actingAs($this->member($this->tenant('Rival Infra'), 'owner'));

        $this->get(route('finance.reports.gstr1', ['month' => '2026-11']))->assertOk()->assertDontSee('INV/26-27/00001');
        $this->get(route('finance.reports.tally'))->assertOk()->assertDontSee('Download vouchers');
        $this->get(route('finance.reports.cash-flow'))->assertOk()->assertDontSee('SSC-101');
        $this->assertStringNotContainsString('SSC-101', $this->get(route('finance.reports.tally.vouchers'))->getContent());
    }

    public function test_filters_cannot_reach_another_companys_project(): void
    {
        $rival = $this->member($this->tenant('Rival Infra'), 'owner');
        $this->actingAs($rival);

        $this->get(route('finance.reports.cash-flow', ['project_id' => $this->project->id]))->assertSessionHasErrors('project_id');
        $this->get(route('finance.reports.tally', ['project_id' => $this->project->id]))->assertSessionHasErrors('project_id');
    }

    public function test_vendor_from_another_company_is_refused(): void
    {
        $rivalOrganization = $this->tenant('Rival Infra');
        $rivalVendor = Party::withoutGlobalScopes()->create(['organization_id' => $rivalOrganization->id, 'type' => 'vendor', 'name' => 'Rival Vendor']);

        $this->post(route('finance.project.vendor-bills.store', $this->project), $this->billData(['party_id' => $rivalVendor->id, 'bill_no' => 'NEW-1']))
            ->assertSessionHasErrors('party_id');
    }

    #[DataProvider('plans')]
    public function test_module_follows_the_plan(string $plan, bool $allowed): void
    {
        $organization = $this->tenant('Plan Co', $plan);
        $this->actingAs($this->member($organization, 'owner'));
        $project = $this->makeProject($organization);

        $response = $this->get(route('finance.project.ledger', $project));
        $allowed ? $response->assertOk() : $response->assertForbidden();

        $response = $this->get(route('finance.reports.tds'));
        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public static function plans(): array
    {
        return [
            'free' => ['free', false],
            'contractor' => ['contractor', true],
            'business' => ['business', true],
            'enterprise' => ['enterprise', true],
        ];
    }

    public function test_menu_shows_finance_only_with_permission(): void
    {
        $this->get(route('projects.show', $this->project))->assertOk()->assertSee('Finance')->assertSee('GST reports');

        $this->actingAs($this->member($this->organization, 'engineer'));
        $this->get(route('projects.show', $this->project))->assertOk()->assertDontSee('GST reports');
    }

    public function test_vendor_bill_flow_over_http(): void
    {
        $uuid = (string) Str::uuid();
        $this->actingAs($this->pm);

        $this->post(route('finance.project.vendor-bills.store', $this->project), $this->billData(['bill_no' => 'FLOW-1', 'client_uuid' => $uuid]))->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('finance.project.vendor-bills.store', $this->project), $this->billData(['bill_no' => 'FLOW-1', 'client_uuid' => $uuid]))->assertSessionHasNoErrors();
        $bill = VendorBill::where('bill_no', 'FLOW-1')->sole();

        $this->get(route('finance.project.vendor-bills.show', $bill))->assertOk()->assertSee('FLOW-1')->assertSee('₹1,18,000.00');
        $this->post(route('finance.project.vendor-bills.submit', $bill))->assertSessionHasNoErrors();

        $this->actingAs($this->owner);
        $approval = Approval::where('doc_type', 'vendor_bill')->where('approvable_id', $bill->id)->sole();
        $this->assertSame(route('finance.project.vendor-bills.show', $bill), $approval->documentUrl());
        $this->get(route('approvals.index'))->assertOk()->assertSee('Vendor bill FLOW-1');
        $this->post(route('approvals.act', $approval), ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->post(route('approvals.act', $approval), ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame('APPROVED', $bill->refresh()->status);

        $this->get(route('finance.project.payments', $this->project))->assertOk()->assertSee('FLOW-1');
        $this->post(route('finance.project.payments.vendor', $bill), ['paid_on' => '2026-11-18', 'amount' => '117000', 'mode' => 'upi', 'reference' => 'UTR1'])->assertSessionHasNoErrors();
        $this->assertSame('PAID', $bill->refresh()->status);
        $this->get(route('finance.project.ledger', $this->project))->assertOk()->assertSee('FLOW-1');
    }

    public function test_approved_bill_returns_409_when_opened_for_edit(): void
    {
        $this->get(route('finance.project.vendor-bills.edit', $this->approvedBill))->assertStatus(409);
    }

    public function test_bad_amounts_are_refused(): void
    {
        $url = route('finance.project.payments.vendor', $this->approvedBill);
        $valid = ['paid_on' => '2026-11-18', 'mode' => 'bank'];

        $this->post($url, [...$valid, 'amount' => '0'])->assertSessionHasErrors('amount');
        $this->post($url, [...$valid, 'amount' => '-5'])->assertSessionHasErrors('amount');
        $this->post($url, [...$valid, 'amount' => '1.234'])->assertSessionHasErrors('amount');
        $this->post($url, [...$valid, 'amount' => '999999'])->assertSessionHasErrors('amount');
        $this->post($url, ['paid_on' => '2026-11-21', 'mode' => 'bank', 'amount' => '1'])->assertSessionHasErrors('paid_on');
        $this->post($url, ['paid_on' => '2026-11-18', 'mode' => 'barter', 'amount' => '1'])->assertSessionHasErrors('mode');
    }

    public function test_tds_rate_needs_a_section(): void
    {
        $this->post(route('finance.project.vendor-bills.store', $this->project), $this->billData(['bill_no' => 'S-1', 'tds_section' => '']))->assertSessionHasErrors('tds_section');
    }

    public function test_receipts_page_offers_the_cash_and_tds_still_due(): void
    {
        // Bill TDS is 5,000 and 10 has been recorded. Bank money left is 2,77,500 − 1,000.
        $this->get(route('finance.project.receipts', $this->project))->assertOk()->assertSee('value="276500.00"', false)->assertSee('value="4990.00"', false);
    }

    public function test_ledger_warns_about_a_bill_marked_paid_without_its_receipts(): void
    {
        app(RaBillService::class)->markPaid($this->approvedClientBill());

        $this->get(route('finance.project.ledger', $this->project))->assertOk()->assertSee('1 bill was marked paid');
    }

    public function test_gst_report_warns_about_a_bill_with_no_invoice(): void
    {
        $this->approvedClientBill();

        $this->get(route('finance.reports.gstr1', ['month' => '2026-11']))->assertOk()->assertSee('1 approved client bill with GST has no tax invoice');
    }

    public function test_reversing_needs_a_reason(): void
    {
        $this->post(route('finance.project.payments.reverse', $this->payment))->assertSessionHasErrors('reason');
        $this->post(route('finance.project.payments.reverse', $this->payment), ['reason' => 'Bounced'])->assertSessionHasNoErrors();
        $this->assertSame(2, VendorPayment::count());
    }

    public function test_invoice_pdf_downloads_and_irn_can_be_saved(): void
    {
        $this->get(route('finance.project.invoices.pdf', $this->invoice))->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->patch(route('finance.project.invoices.irn', $this->invoice), ['irn' => 'not-hex', 'irn_ack_no' => '1', 'irn_ack_date' => '2026-11-20'])->assertSessionHasErrors('irn');
        $this->patch(route('finance.project.invoices.irn', $this->invoice), ['irn' => str_repeat('a1', 32), 'irn_ack_no' => '1', 'irn_ack_date' => '2026-11-20 10:00'])->assertSessionHasNoErrors();
        $this->assertSame(str_repeat('a1', 32), $this->invoice->refresh()->irn);
        $this->get(route('finance.project.invoices', $this->project))->assertOk()->assertSee(str_repeat('a1', 32));
    }

    public function test_invoice_pdf_renders_the_tax_figures(): void
    {
        $html = view('modules.finance.invoice-pdf', ['invoice' => $this->invoice->load('raBill.items', 'raBill.deductions', 'project')])->render();

        $this->assertStringContainsString('Tax Invoice', $html);
        $this->assertStringContainsString('INV/26-27/00001', $html);
        $this->assertStringContainsString('₹22,500.00', $html);
        $this->assertStringContainsString('₹2,95,000.00', $html);
        $this->assertStringContainsString('Not generated', $html);
    }

    public function test_reports_are_marked_for_ca_check(): void
    {
        foreach (['finance.reports.gstr1', 'finance.reports.tds', 'finance.reports.cash-flow'] as $name) {
            $this->get(route($name))->assertOk()->assertSee('Data for your CA to check');
        }
    }

    public function test_tally_page_says_live_sync_is_not_connected(): void
    {
        $this->get(route('finance.reports.tally'))->assertOk()->assertSee('Live two-way sync with Tally is not connected');
    }

    public function test_downloads_have_the_right_type_and_content(): void
    {
        $excel = $this->get(route('finance.reports.gstr1.excel', ['month' => '2026-11']))->assertOk();
        $this->assertStringStartsWith('PK', $excel->streamedContent());
        $this->assertStringContainsString('for_CA_check', (string) $excel->headers->get('content-disposition'));

        $json = $this->get(route('finance.reports.gstr1.json', ['month' => '2026-11']))->assertOk();
        $this->assertSame('112026', $json->json('fp'));
        $this->assertStringContainsString('for_CA_check', (string) $json->headers->get('content-disposition'));

        $this->assertStringStartsWith('PK', $this->get(route('finance.reports.tds.excel'))->assertOk()->streamedContent());

        $vouchers = $this->get(route('finance.reports.tally.vouchers'))->assertOk()->assertHeader('content-type', 'application/xml; charset=UTF-8');
        $this->assertStringContainsString('<VOUCHER', $vouchers->getContent());
        $this->assertStringContainsString('<LEDGER', $this->get(route('finance.reports.tally.ledgers'))->assertOk()->getContent());
    }

    public function test_bad_report_filters_are_refused(): void
    {
        $this->get(route('finance.reports.gstr1', ['month' => 'soon']))->assertSessionHasErrors('month');
        $this->get(route('finance.reports.tds', ['from' => '2026-12-01', 'to' => '2026-11-01']))->assertSessionHasErrors('to');
        $this->get(route('finance.reports.cash-flow', ['weeks' => 100]))->assertSessionHasErrors('weeks');
    }

    public function test_pages_translate(): void
    {
        foreach (['hi' => 'परियोजना खाता', 'mr' => 'प्रकल्प खाते'] as $locale => $title) {
            $this->owner->update(['locale' => $locale]);
            $this->get(route('finance.project.ledger', $this->project))->assertOk()->assertSee($title);
        }
    }

    public function test_every_ui_string_has_hindi_and_marathi(): void
    {
        $strings = [...$this->uiStrings(), ...config('modules.finance.cost_heads'), ...array_values(config('modules.finance.payment_modes'))];
        $strings = [...$strings, 'Finance', 'GST reports', 'TDS reports', 'Cash-flow forecast', 'Tally export', 'Sales', 'Purchase', 'Payment', 'Receipt', 'Contra', 'Journal', 'Part Paid', 'Reversed', 'Reversal', 'Issued'];

        foreach (['hi', 'mr'] as $locale) {
            $lines = json_decode(file_get_contents(lang_path("modules/finance/{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
            $missing = array_values(array_diff(array_unique($strings), array_keys($lines)));

            $this->assertSame([], $missing, "Missing {$locale} translations.");
        }
    }

    /** @return list<string> Literal text passed to __() or trans_choice() in this module's code. */
    private function uiStrings(): array
    {
        $files = [...glob(resource_path('views/modules/finance/*.blade.php')), ...glob(resource_path('views/modules/finance/*/*.blade.php')), ...glob(app_path('Services/Finance/*.php')), ...glob(app_path('Http/Controllers/Finance/*.php'))];
        $strings = [];

        foreach ($files as $file) {
            preg_match_all("/(?:__|trans_choice)\\('((?:[^'\\\\]|\\\\.)+)'/", file_get_contents($file), $matches);
            array_push($strings, ...array_map('stripslashes', $matches[1]));
        }

        return $strings;
    }
}
