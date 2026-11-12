<?php

namespace Tests\Feature\Notify;

use App\Mail\Notify\WorkflowNotificationMail;
use App\Models\AuditLog;
use App\Models\CompanyDataExport;
use App\Models\CompanyDeletionRequest;
use App\Models\DeviceToken;
use App\Models\DprPhoto;
use App\Models\Organization;
use App\Models\Party;
use App\Models\Project;
use App\Models\User;
use App\Services\Notify\CompanyDeletionService;
use App\Services\Notify\CompanyExportService;
use App\Services\Notify\TenantData;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class CompanyDataTest extends NotifyTestCase
{
    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->organization = $this->tenant('Sahyadri Builders');
        $this->owner = $this->member($this->organization, 'owner');
    }

    private function photoFor(Organization $organization, string $path): void
    {
        $project = $this->makeProject($organization);
        $dpr = $project->dprs()->create(['organization_id' => $organization->id, 'report_date' => '2026-11-01', 'status' => 'DRAFT']);
        Storage::put($path, 'image bytes');
        DprPhoto::withoutGlobalScopes()->create(['organization_id' => $organization->id, 'dpr_id' => $dpr->id, 'path' => $path]);
    }

    private function exportZip(): array
    {
        $this->actingAs($this->owner)->post('/company-data/export')->assertRedirect()->assertSessionHasNoErrors();

        $export = CompanyDataExport::where('organization_id', $this->organization->id)->latest('id')->firstOrFail();
        $this->assertSame('ready', $export->status, (string) $export->error);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::path($export->path)));

        $files = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $files[$name] = $zip->getFromName($name);
        }

        $zip->close();

        return [$export, $files];
    }

    private function schedule(?Organization $organization = null, ?User $by = null): CompanyDeletionRequest
    {
        $organization ??= $this->organization;

        return app(CompanyDeletionService::class)->request($organization, $by ?? $this->owner, $organization->name);
    }

    private function runHousekeeping(): void
    {
        Auth::forgetGuards();
        $this->artisan('company-data:housekeeping')->assertSuccessful();
    }

    public function test_only_people_with_settings_access_can_open_the_page(): void
    {
        $this->actingAs($this->member($this->organization, 'pm'))->get('/company-data')->assertForbidden();
        $this->actingAs($this->owner)->get('/company-data')->assertOk()->assertSee('Export all company data');
    }

    public function test_an_admin_can_read_but_not_export_or_delete(): void
    {
        $this->actingAs($this->member($this->organization, 'admin'));

        $this->get('/company-data')->assertOk()->assertSee('Only a company owner')->assertDontSee('Export all company data');
        $this->post('/company-data/export')->assertForbidden();
        $this->post('/company-data/deletion', ['confirm_name' => 'Sahyadri Builders'])->assertForbidden();
        $this->delete('/company-data/deletion')->assertForbidden();
        $this->assertSame(0, CompanyDeletionRequest::count());
    }

    public function test_the_export_holds_records_uploads_and_a_manifest(): void
    {
        $this->makeProject($this->organization, ['code' => 'RIVER-1']);
        $this->photoFor($this->organization, 'org-'.$this->organization->id.'/dprs/1/site.jpg');

        [, $files] = $this->exportZip();

        $this->assertArrayHasKey('README.txt', $files);
        $this->assertStringContainsString('RIVER-1', $files['data/projects.csv']);
        $this->assertStringContainsString('Sahyadri Builders', $files['data/organizations.csv']);
        $this->assertStringContainsString($this->owner->email, $files['data/users.csv']);

        $photo = collect($files)->keys()->first(fn ($name) => str_starts_with($name, 'files/dpr_photos/'));
        $this->assertNotNull($photo);
        $this->assertSame('image bytes', $files[$photo]);

        $manifest = json_decode($files['manifest.json'], true);
        $this->assertSame(1, $manifest['files_included']);
        $this->assertSame(0, $manifest['files_missing']);
        $this->assertSame(1, $manifest['tables']['users']);
    }

    public function test_a_table_from_any_module_is_included_automatically(): void
    {
        DB::statement('CREATE TABLE future_module_things (id INTEGER PRIMARY KEY, organization_id INTEGER, label TEXT)');
        DB::table('future_module_things')->insert(['id' => 1, 'organization_id' => $this->organization->id, 'label' => 'From another module']);

        [, $files] = $this->exportZip();

        $this->assertStringContainsString('From another module', $files['data/future_module_things.csv']);
    }

    public function test_no_other_companys_rows_or_files_are_in_the_export(): void
    {
        $other = $this->tenant('Rival Constructions');
        $rival = $this->member($other, 'owner');
        $this->makeProject($other, ['code' => 'RIVAL-X']);
        $this->photoFor($other, 'org-'.$other->id.'/dprs/9/rival.jpg');

        [, $files] = $this->exportZip();

        $everything = implode("\n", array_map('strval', $files));
        $this->assertStringNotContainsString('RIVAL-X', $everything);
        $this->assertStringNotContainsString('Rival Constructions', $everything);
        $this->assertStringNotContainsString($rival->email, $everything);
        $this->assertEmpty(array_filter(array_keys($files), fn ($name) => str_contains($name, 'rival.jpg')));
    }

    public function test_credentials_are_never_exported(): void
    {
        DeviceToken::withoutGlobalScopes()->create(['organization_id' => $this->organization->id, 'user_id' => $this->owner->id, 'token_hash' => str_repeat('a', 64), 'token' => 'secret-device-token', 'platform' => 'android']);
        $hash = $this->owner->password;

        [, $files] = $this->exportZip();

        $this->assertStringNotContainsString('password', strtolower(strtok($files['data/users.csv'], "\n")));
        $this->assertStringNotContainsString($hash, $files['data/users.csv']);
        $this->assertStringNotContainsString('remember_token', $files['data/users.csv']);
        $this->assertStringNotContainsString('secret-device-token', implode("\n", $files));
        $this->assertStringNotContainsString('token', strtok($files['data/device_tokens.csv'], "\n"));
    }

    public function test_credential_columns_are_recognised_in_any_module(): void
    {
        $columns = app(TenantData::class)->exportableColumns(['id', 'password', 'remember_token', 'tally_api_key', 'client_secret', 'code_hash', 'tokens_used', 'name']);

        $this->assertSame(['id', 'tokens_used', 'name'], $columns);
    }

    public function test_a_formula_typed_by_a_user_cannot_run_in_excel(): void
    {
        Party::create(['organization_id' => $this->organization->id, 'type' => 'vendor', 'name' => '=HYPERLINK("http://bad.example")']);

        [, $files] = $this->exportZip();

        $this->assertStringContainsString("'=HYPERLINK", $files['data/parties.csv']);
    }

    public function test_the_export_does_not_pack_earlier_exports(): void
    {
        $this->exportZip();
        [, $files] = $this->exportZip();

        $this->assertArrayNotHasKey('data/company_data_exports.csv', $files);
        $this->assertEmpty(array_filter(array_keys($files), fn ($name) => str_ends_with($name, '.zip')));
    }

    public function test_the_owner_is_told_when_it_is_ready(): void
    {
        $this->exportZip();

        $this->assertSame('Your company data export is ready', $this->owner->notifications()->first()->data['title']);
    }

    public function test_a_second_export_cannot_start_while_one_is_running(): void
    {
        Queue::fake();
        $this->actingAs($this->owner);

        $this->post('/company-data/export')->assertSessionHasNoErrors();
        $this->post('/company-data/export')->assertSessionHasErrors('export');

        $this->assertSame(1, CompanyDataExport::count());
    }

    public function test_a_job_handed_to_a_second_worker_does_nothing(): void
    {
        $export = CompanyDataExport::withoutGlobalScopes()->create(['organization_id' => $this->organization->id, 'status' => 'running']);

        app(CompanyExportService::class)->build($export->id);

        $this->assertSame('running', $export->fresh()->status);
    }

    public function test_the_owner_can_download_and_it_is_audited(): void
    {
        [$export] = $this->exportZip();

        $this->get("/company-data/exports/{$export->id}")->assertOk()->assertDownload('company-data-'.$export->created_at->format('Y-m-d').'.zip');

        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'company.export_downloaded')->count());
    }

    public function test_an_admin_cannot_download_it(): void
    {
        [$export] = $this->exportZip();

        $this->actingAs($this->member($this->organization, 'admin'))->get("/company-data/exports/{$export->id}")->assertForbidden();
    }

    public function test_another_company_cannot_download_it(): void
    {
        [$export] = $this->exportZip();

        $this->actingAs($this->member($this->tenant('Rival'), 'owner'))->get("/company-data/exports/{$export->id}")->assertNotFound();
    }

    public function test_an_expired_export_stops_working_and_is_removed(): void
    {
        [$export] = $this->exportZip();
        $path = $export->path;
        $export->update(['expires_at' => now()->subMinute()]);

        $this->get("/company-data/exports/{$export->id}")->assertNotFound();
        $this->runHousekeeping();

        Storage::assertMissing($path);
        $this->assertSame('expired', $export->fresh()->status);
    }

    public function test_a_run_that_died_is_cleared_so_the_owner_can_try_again(): void
    {
        $export = CompanyDataExport::withoutGlobalScopes()->forceCreate(['organization_id' => $this->organization->id, 'status' => 'running', 'created_at' => now()->subHours(3), 'updated_at' => now()->subHours(3)]);

        $this->assertFalse(app(CompanyExportService::class)->inProgress($this->organization->id));
        $this->runHousekeeping();

        $this->assertSame('failed', $export->fresh()->status);
    }

    public function test_deletion_needs_the_exact_company_name(): void
    {
        $this->actingAs($this->owner);

        foreach (['DELETE', 'sahyadri builders', 'Sahyadri'] as $wrong) {
            $this->post('/company-data/deletion', ['confirm_name' => $wrong])->assertSessionHasErrors('confirm_name');
        }

        $this->post('/company-data/deletion', [])->assertSessionHasErrors('confirm_name');
        $this->assertSame(0, CompanyDeletionRequest::count());
    }

    public function test_deletion_is_scheduled_after_the_grace_period_and_the_company_keeps_working(): void
    {
        $this->actingAs($this->owner);

        $this->post('/company-data/deletion', ['confirm_name' => 'Sahyadri Builders'])->assertRedirect()->assertSessionHas('success');

        $request = CompanyDeletionRequest::sole();
        $this->assertSame('pending', $request->status);
        $this->assertEqualsWithDelta(now()->addDays(CompanyDeletionService::GRACE_DAYS)->timestamp, $request->purge_after->timestamp, 5);
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'company.deletion_requested')->count());

        $this->get('/dashboard')->assertOk();
        $this->get('/company-data')->assertOk()->assertSee('Cancel the deletion');
    }

    public function test_every_active_person_is_told_even_with_email_off(): void
    {
        Queue::fake();
        $admin = $this->member($this->organization, 'admin');
        $engineer = $this->member($this->organization);
        $contractor = $this->member($this->organization, 'contractor');
        $gone = $this->member($this->organization, 'pm');
        $gone->forceFill(['is_active' => false])->save();
        $outsider = $this->member($this->tenant('Rival Constructions'), 'owner');

        $request = $this->schedule();

        $this->assertSame(1, $this->owner->notifications()->count());
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame(1, $engineer->notifications()->count());
        $this->assertSame(1, $contractor->notifications()->count());
        $this->assertSame(0, $gone->notifications()->count());
        $this->assertSame(0, $outsider->notifications()->count());

        // Owners and admins get the Company data page, where they can cancel. Everyone else cannot open it.
        foreach ([$this->owner, $admin] as $leader) {
            $this->assertSame(route('notify.data.index'), $leader->notifications()->sole()->data['url']);
            $this->assertStringNotContainsString('Your access ends then', $leader->notifications()->sole()->data['body']);
        }

        foreach ([$engineer, $contractor] as $member) {
            $notice = $member->notifications()->sole()->data;
            $this->assertNull($notice['url']);
            $this->assertStringContainsString($request->purge_after->format('d M Y'), $notice['body']);
            $this->assertStringContainsString("Your access ends then, because all of the company's data will be erased.", $notice['body']);
        }
    }

    public function test_members_are_told_in_their_own_language(): void
    {
        Queue::fake();
        $engineer = $this->member($this->organization);
        $engineer->forceFill(['locale' => 'mr'])->save();

        $this->schedule();

        $body = $engineer->notifications()->sole()->data['body'];
        $this->assertStringContainsString(__("Your access ends then, because all of the company's data will be erased.", [], 'mr'), $body);
        $this->assertStringNotContainsString('Your access ends then', $body);
    }

    public function test_everyone_is_told_when_the_deletion_is_cancelled(): void
    {
        Queue::fake();
        $engineer = $this->member($this->organization);
        $this->schedule();

        $this->actingAs($this->owner)->delete('/company-data/deletion')->assertRedirect();

        $this->assertSame(2, $this->owner->notifications()->count());
        $this->assertSame(2, $engineer->notifications()->count());
        $cancelled = $engineer->notifications()->get()->firstWhere('data.title', 'Company deletion cancelled for Sahyadri Builders');
        $this->assertNotNull($cancelled);
        $this->assertNull($cancelled->data['url']);
        $this->assertSame('Your data is safe and will not be erased.', $cancelled->data['body']);
    }

    public function test_a_request_records_where_it_was_made(): void
    {
        $this->actingAs($this->owner)->post('/company-data/deletion', ['confirm_name' => 'Sahyadri Builders'])->assertRedirect();
        $this->assertSame('company', CompanyDeletionRequest::sole()->requested_via);

        $other = $this->tenant('Solo Infra');
        $request = app(CompanyDeletionService::class)->request($other, $this->member($other, 'owner'), 'Solo Infra', via: 'account');
        $this->assertSame('account', $request->fresh()->requested_via);
    }

    public function test_the_requested_via_column_rolls_back(): void
    {
        $migration = require database_path('migrations/2027_01_04_100003_add_requested_via_to_company_deletion_requests.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('company_deletion_requests', 'requested_via'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('company_deletion_requests', 'requested_via'));
    }

    public function test_asking_twice_does_not_extend_the_grace_period(): void
    {
        $first = $this->schedule();
        $this->travel(5)->days();
        $second = $this->schedule();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CompanyDeletionRequest::count());
    }

    public function test_nothing_is_erased_during_the_grace_period(): void
    {
        $this->schedule();
        $this->travel(CompanyDeletionService::GRACE_DAYS - 1)->days();

        $this->runHousekeeping();

        $this->assertDatabaseHas('organizations', ['id' => $this->organization->id]);
        $this->assertSame('pending', CompanyDeletionRequest::sole()->status);
    }

    public function test_cancelling_keeps_the_company_for_good(): void
    {
        $this->schedule();
        $this->actingAs($this->owner)->delete('/company-data/deletion')->assertRedirect()->assertSessionHas('success');

        $this->assertSame('cancelled', CompanyDeletionRequest::sole()->status);
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'company.deletion_cancelled')->count());

        $this->travel(CompanyDeletionService::GRACE_DAYS + 5)->days();
        $this->runHousekeeping();

        $this->assertDatabaseHas('organizations', ['id' => $this->organization->id]);
    }

    public function test_after_the_grace_period_the_company_and_its_files_are_erased(): void
    {
        $engineer = $this->member($this->organization);
        $engineer->createToken('phone');
        DB::table('otp_codes')->insert(['phone' => '9876543210', 'code_hash' => 'x', 'expires_at' => now()]);
        $engineer->update(['phone' => '9876543210']);
        $this->makeProject($this->organization);
        $path = 'org-'.$this->organization->id.'/dprs/1/site.jpg';
        $this->photoFor($this->organization, $path);
        $this->exportZip();
        $exportPath = CompanyDataExport::where('organization_id', $this->organization->id)->sole()->path;
        $id = $this->organization->id;

        $this->schedule();
        $this->travel(CompanyDeletionService::GRACE_DAYS + 1)->days();
        $this->runHousekeeping();

        $this->assertDatabaseMissing('organizations', ['id' => $id]);
        $this->assertSame(0, Project::withoutGlobalScopes()->where('organization_id', $id)->count());
        $this->assertSame(0, User::where('organization_id', $id)->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertSame(0, DB::table('notifications')->count());
        $this->assertSame(0, DB::table('otp_codes')->count());
        Storage::assertMissing($path);
        Storage::assertMissing($exportPath);

        $tombstone = CompanyDeletionRequest::withoutGlobalScopes()->sole();
        $this->assertSame('completed', $tombstone->status);
        $this->assertNull($tombstone->requested_by);
    }

    public function test_the_requester_is_emailed_once_the_company_has_been_erased(): void
    {
        $this->owner->forceFill(['locale' => 'hi'])->save();
        $email = $this->owner->email;
        $this->schedule();
        Mail::fake();

        $this->travel(CompanyDeletionService::GRACE_DAYS + 1)->days();
        $this->runHousekeeping();

        $this->assertDatabaseMissing('organizations', ['id' => $this->organization->id]);
        $sent = Mail::sent(WorkflowNotificationMail::class, fn (WorkflowNotificationMail $mail) => $mail->notice->event === 'company.erased');
        $this->assertCount(1, $sent);
        $mail = $sent->first();
        $this->assertTrue($mail->hasTo($email));
        $this->assertSame('hi', $mail->locale);
        $this->assertSame(__('Your company :company has been erased', ['company' => 'Sahyadri Builders'], 'hi'), $mail->notice->title);
        $this->assertStringContainsString('Nirmaan360', $mail->notice->body);
        $this->assertNull($mail->notice->url);

        // The address was only ever held in memory: no table keeps it, not even the queue.
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            $this->assertStringNotContainsStringIgnoringCase($email, (string) json_encode(DB::table($table)->get(), JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), "{$table} still holds the requester's email.");
        }
    }

    public function test_a_confirmation_email_that_fails_does_not_undo_the_purge(): void
    {
        $email = $this->owner->email;
        $this->schedule();
        Log::spy();
        Mail::shouldReceive('to')->once()->with($email)->andThrow(new RuntimeException("Expected response code 250 but got 550: <{$email}> does not exist"));

        $this->travel(CompanyDeletionService::GRACE_DAYS + 1)->days();
        $this->runHousekeeping();

        $this->assertDatabaseMissing('organizations', ['id' => $this->organization->id]);
        $this->assertSame('completed', CompanyDeletionRequest::withoutGlobalScopes()->sole()->status);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context = []) => $message === 'The erasure confirmation email could not be sent'
            && str_contains($context['error'], '[hidden]') && ! str_contains($context['error'], $email));
    }

    public function test_no_confirmation_is_sent_when_mail_is_only_logged(): void
    {
        $this->schedule();
        config(['mail.default' => 'log']);
        Mail::fake();

        $this->travel(CompanyDeletionService::GRACE_DAYS + 1)->days();
        $this->runHousekeeping();

        $this->assertDatabaseMissing('organizations', ['id' => $this->organization->id]);
        Mail::assertNothingSent();
    }

    public function test_tables_with_no_foreign_key_are_swept_too(): void
    {
        DB::statement('CREATE TABLE future_module_things (id INTEGER PRIMARY KEY, organization_id INTEGER, label TEXT)');
        DB::table('future_module_things')->insert(['id' => 1, 'organization_id' => $this->organization->id, 'label' => 'x']);

        $this->schedule();
        $this->travel(CompanyDeletionService::GRACE_DAYS + 1)->days();
        $this->runHousekeeping();

        $this->assertSame(0, DB::table('future_module_things')->count());
    }

    public function test_erasing_one_company_leaves_another_untouched(): void
    {
        $other = $this->tenant('Rival Constructions');
        $rival = $this->member($other, 'owner');
        $project = $this->makeProject($other);
        $path = 'org-'.$other->id.'/dprs/9/rival.jpg';
        $this->photoFor($other, $path);

        $this->schedule();
        $this->travel(CompanyDeletionService::GRACE_DAYS + 1)->days();
        $this->runHousekeeping();

        $this->assertDatabaseHas('organizations', ['id' => $other->id]);
        $this->assertDatabaseHas('users', ['id' => $rival->id]);
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        Storage::assertExists($path);
    }

    public function test_running_housekeeping_twice_changes_nothing_more(): void
    {
        $this->schedule();
        $this->travel(CompanyDeletionService::GRACE_DAYS + 1)->days();

        $this->runHousekeeping();
        $this->runHousekeeping();

        $this->assertSame('completed', CompanyDeletionRequest::withoutGlobalScopes()->sole()->status);
    }

    public function test_paths_that_leave_the_storage_folder_are_ignored(): void
    {
        $data = app(TenantData::class);

        $this->assertFalse($data->isSafePath('../../.env'));
        $this->assertFalse($data->isSafePath('/etc/passwd'));
        $this->assertFalse($data->isSafePath(''));
        $this->assertFalse($data->isSafePath(null));
        $this->assertTrue($data->isSafePath('org-1/dprs/1/'.Str::random(8).'.jpg'));
    }

    public function test_a_file_that_has_gone_is_counted_and_does_not_stop_the_export(): void
    {
        $path = 'org-'.$this->organization->id.'/dprs/1/gone.jpg';
        $this->photoFor($this->organization, $path);
        Storage::delete($path);

        [, $files] = $this->exportZip();

        $this->assertSame(1, json_decode($files['manifest.json'], true)['files_missing']);
    }
}
