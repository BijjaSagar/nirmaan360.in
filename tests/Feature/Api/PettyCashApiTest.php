<?php

namespace Tests\Feature\Api;

use App\Models\PettyCashEntry;
use App\Models\PettyCashWallet;
use App\Models\Project;
use App\Models\User;
use App\Services\PettyCashService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PettyCashApiTest extends ApiTestCase
{
    private function wallet(Project $project, ?User $holder = null, string $topUp = '1000'): PettyCashWallet
    {
        $wallet = PettyCashWallet::create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'name' => 'Imprest', 'user_id' => $holder?->id]);

        if ($holder) {
            app(PettyCashService::class)->addEntry($wallet, ['type' => 'topup', 'amount' => $topUp, 'entry_date' => '2026-11-01'], $holder);
        }

        return $wallet;
    }

    private function expense(array $overrides = []): array
    {
        return ['type' => 'expense', 'amount' => '450', 'category' => 'Fuel', 'entry_date' => '2026-11-02', ...$overrides];
    }

    public function test_list_shows_wallets_with_balance_and_recent_entries(): void
    {
        $site = $this->site();
        $wallet = $this->wallet($site->project, $site->user);
        app(PettyCashService::class)->addEntry($wallet, $this->expense(), $site->user);

        $this->getJson(self::API."/projects/{$site->project->id}/petty-cash")->assertOk()
            ->assertJsonPath('data.0.id', $wallet->id)
            ->assertJsonPath('data.0.balance', '550.00')
            ->assertJsonPath('data.0.user_id', $site->user->id)
            ->assertJsonCount(2, 'data.0.entries')
            ->assertJsonPath('data.0.entries.0.type', 'expense')
            ->assertJsonPath('data.0.entries.0.amount', '450.00')
            ->assertJsonPath('data.0.entries.0.has_bill', false);
    }

    public function test_create_is_idempotent_and_stores_the_bill(): void
    {
        Storage::fake('local');
        $site = $this->site();
        $wallet = $this->wallet($site->project, $site->user);
        $payload = $this->expense(['client_uuid' => (string) Str::uuid()]);

        $id = $this->post(self::API."/petty-wallets/{$wallet->id}/entries", [...$payload, 'bill' => UploadedFile::fake()->create('bill.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.has_bill', true)->json('data.id');
        $this->postJson(self::API."/petty-wallets/{$wallet->id}/entries", $payload)->assertOk()->assertJsonPath('data.id', $id);

        $this->assertSame(1, PettyCashEntry::where('type', 'expense')->count());
        $this->get(self::API."/petty-entries/{$id}/bill")->assertOk();
    }

    public function test_an_entry_without_a_bill_is_a_404_on_the_bill_route(): void
    {
        $site = $this->site();
        $entry = $this->wallet($site->project, $site->user)->entries()->first();

        $this->getJson(self::API."/petty-entries/{$entry->id}/bill")->assertNotFound();
    }

    public function test_an_expense_over_the_balance_is_refused(): void
    {
        $site = $this->site();
        $wallet = $this->wallet($site->project, $site->user);

        $this->postJson(self::API."/petty-wallets/{$wallet->id}/entries", $this->expense(['amount' => '1000.01']))
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertSame(1, PettyCashEntry::count());
    }

    public function test_the_web_form_applies_the_same_balance_check(): void
    {
        $site = $this->site();
        $wallet = $this->wallet($site->project, $site->user);
        $this->actingAs($site->user);

        $this->post("/petty-wallets/{$wallet->id}/entries", $this->expense(['amount' => '1000.01']))->assertSessionHasErrors('amount');
        $this->post("/petty-wallets/{$wallet->id}/entries", $this->expense(['amount' => '1000.00']))->assertSessionHasNoErrors();

        $this->assertSame('0.00', $wallet->fresh()->load('entries')->balance());
    }

    public function test_routes_need_their_permission(): void
    {
        $site = $this->site();
        $wallet = $this->wallet($site->project, $site->user);
        $entry = $wallet->entries()->first();
        $this->signIn($this->member($site->organization, 'store'));

        $this->getJson(self::API."/projects/{$site->project->id}/petty-cash")->assertForbidden();
        $this->postJson(self::API."/petty-wallets/{$wallet->id}/entries", $this->expense())->assertForbidden();
        $this->getJson(self::API."/petty-entries/{$entry->id}/bill")->assertForbidden();
    }

    public function test_routes_respect_the_plan_feature(): void
    {
        $site = $this->site('owner');
        config(['buildos.plans.business.features' => ['site']]);

        $this->getJson(self::API."/projects/{$site->project->id}/petty-cash")->assertForbidden();
    }

    public function test_another_companys_petty_cash_is_a_404(): void
    {
        $site = $this->site();
        $wallet = $this->wallet($site->project, $site->user);
        $entry = $wallet->entries()->first();
        $this->signInElsewhere();

        $this->getJson(self::API."/projects/{$site->project->id}/petty-cash")->assertNotFound();
        $this->postJson(self::API."/petty-wallets/{$wallet->id}/entries", $this->expense())->assertNotFound();
        $this->getJson(self::API."/petty-entries/{$entry->id}/bill")->assertNotFound();
    }
}
