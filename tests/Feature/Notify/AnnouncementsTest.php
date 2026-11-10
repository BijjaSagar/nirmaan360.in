<?php

namespace Tests\Feature\Notify;

use App\Jobs\Notify\DeliverNotification;
use App\Jobs\Notify\SendAnnouncement;
use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Party;
use App\Models\User;
use App\Services\Notify\AnnouncementService;
use App\Services\Notify\FcmGateway;
use App\Services\Notify\NotificationMessage;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/** Broadcasts and reminders: who may send them, who they reach, when they go, and what the app lists. */
class AnnouncementsTest extends NotifyTestCase
{
    private function platformAdmin(?Organization $organization = null): User
    {
        $admin = $this->member($organization ?? $this->tenant('Platform Staff'), 'owner');
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }

    private function contractorLogin(Organization $organization): User
    {
        $party = Party::create(['organization_id' => $organization->id, 'type' => 'subcontractor', 'name' => 'Shree Formwork']);

        return User::factory()->create(['organization_id' => $organization->id, 'role' => User::CONTRACTOR_ROLE, 'contractor_party_id' => $party->id, 'is_active' => true]);
    }

    /** @return array<string, mixed> */
    private function form(array $overrides = []): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'title' => 'Site safety week',
            'body' => 'Wear helmets on every site from Monday.',
            'audience' => 'all',
            'schedule' => 'now',
            ...$overrides,
        ];
    }

    private function sendAsPlatform(User $admin, array $overrides = []): Announcement
    {
        $this->actingAs($admin)->post(route('admin.announcements.store'), $this->form($overrides))->assertRedirect(route('admin.announcements'))->assertSessionHasNoErrors();

        return Announcement::latest('id')->firstOrFail();
    }

    public function test_only_platform_staff_open_the_platform_page_and_only_company_admins_open_the_company_page(): void
    {
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $engineer = $this->member($organization, 'engineer');

        $this->get(route('admin.announcements'))->assertRedirect(route('login'));
        $this->actingAs($owner)->get(route('admin.announcements'))->assertNotFound();
        $this->actingAs($owner)->post(route('admin.announcements.store'), $this->form())->assertNotFound();
        $this->actingAs($this->platformAdmin())->get(route('admin.announcements'))->assertOk()->assertSee(__('Everyone, every company'));

        $this->actingAs($owner)->get(route('notify.announcements'))->assertOk()->assertSee(__('Everyone in the company'))->assertDontSee(__('One company'));
        $this->actingAs($engineer)->get(route('notify.announcements'))->assertForbidden();
        $this->actingAs($engineer)->post(route('notify.announcements.store'), $this->form())->assertForbidden();

        $this->assertSame(0, Announcement::count());
    }

    public function test_a_platform_announcement_reaches_every_company_with_the_announcement_target(): void
    {
        $this->connectFcm();
        Queue::fake([DeliverNotification::class]);
        $first = $this->member($this->tenant('First Infra'), 'engineer');
        $second = $this->member($this->tenant('Second Builders'), 'viewer');
        $this->phone($first, 'phone-first');
        $this->phone($second, 'phone-second');

        $announcement = $this->sendAsPlatform($this->platformAdmin());

        foreach ([$first, $second] as $user) {
            $pushes = $this->pushesFor($user);
            $this->assertCount(1, $pushes);
            $this->assertSame('Site safety week | Wear helmets on every site from Monday.', $this->wordsOf($pushes[0]));
            $data = $this->dataOf($pushes[0]);
            $this->assertSame(['announcement', (string) $announcement->id, 'announcements', 'announcement.sent', '0'], [$data['type'], $data['id'], $data['category'], $data['event'], $data['repeats']]);
        }

        $announcement->refresh();
        $this->assertSame(Announcement::SENT, $announcement->status);
        $this->assertNull($announcement->organization_id);
        $this->assertSame(1, $announcement->runs);
        // The two people above and the admin, who has no phone but still gets it in the app.
        $this->assertSame(3, $announcement->recipients_count);
        $this->assertSame(2, $announcement->phones_count);
        $this->assertTrue(AuditLog::where('action', 'announcement.created')->where('auditable_id', $announcement->id)->exists());
        $this->assertTrue(AuditLog::where('action', 'announcement.sent')->where('auditable_id', $announcement->id)->exists());
    }

    public function test_a_company_admin_reaches_only_their_own_company_and_sees_only_their_own_history(): void
    {
        $this->connectFcm();
        Queue::fake([DeliverNotification::class]);
        $mine = $this->tenant('Mine Infra');
        $other = $this->tenant('Other Infra');
        $owner = $this->member($mine, 'owner');
        $colleague = $this->member($mine, 'engineer');
        $stranger = $this->member($other, 'engineer');
        $this->phone($colleague, 'phone-colleague');
        $this->phone($stranger, 'phone-stranger');

        $platform = $this->sendAsPlatform($this->platformAdmin(), ['title' => 'Platform news']);
        Queue::fake([DeliverNotification::class]);

        $this->actingAs($owner)->post(route('notify.announcements.store'), $this->form(['title' => 'Our own news']))->assertRedirect(route('notify.announcements'));
        $ours = Announcement::latest('id')->firstOrFail();

        $this->assertSame($mine->id, $ours->organization_id);
        $this->assertCount(1, $this->pushesFor($colleague));
        $this->assertCount(0, $this->pushesFor($stranger));
        $this->assertSame([$mine->id], AnnouncementRecipient::withoutGlobalScopes()->where('announcement_id', $ours->id)->distinct()->pluck('organization_id')->all());

        $this->actingAs($owner)->get(route('notify.announcements'))->assertOk()->assertSee('Our own news')->assertDontSee('Platform news');

        // Someone else's announcement does not exist for a company admin.
        $this->actingAs($owner)->post(route('notify.announcements.cancel', $platform))->assertNotFound();
        $theirs = Announcement::create([
            'organization_id' => $other->id, 'client_uuid' => (string) Str::uuid(), 'title' => 'Theirs', 'body' => 'Theirs', 'audience' => 'all',
            'target_organization_id' => $other->id, 'schedule' => 'once', 'next_run_at' => now()->addDay(), 'status' => Announcement::SCHEDULED,
        ]);
        $this->actingAs($owner)->post(route('notify.announcements.cancel', $theirs))->assertNotFound();
        $this->assertSame(Announcement::SCHEDULED, $theirs->refresh()->status);
    }

    public function test_a_company_admin_cannot_aim_outside_their_company(): void
    {
        $mine = $this->tenant('Mine Infra');
        $other = $this->tenant('Other Infra');
        $owner = $this->member($mine, 'owner');
        $foreignProject = $this->makeProject($other);

        $this->actingAs($owner)->post(route('notify.announcements.store'), $this->form(['audience' => 'project', 'project_id' => $foreignProject->id]))->assertSessionHasErrors('project_id');
        $this->actingAs($owner)->post(route('notify.announcements.store'), $this->form(['audience' => 'company', 'organization_id' => $other->id]))->assertSessionHasErrors(['audience', 'organization_id']);

        $this->assertSame(0, Announcement::count());
    }

    public function test_roles_and_project_audiences_pick_the_right_people(): void
    {
        $this->connectFcm();
        Queue::fake([DeliverNotification::class]);
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $pm = $this->member($organization, 'pm');
        $engineer = $this->member($organization, 'engineer');
        $assigned = $this->member($organization, 'engineer');
        $project = $this->makeProject($organization);
        $elsewhere = $this->makeProject($organization, ['name' => 'Elsewhere']);
        $this->limitTo($engineer, $elsewhere);
        $this->limitTo($assigned, $project);
        foreach ([$owner, $pm, $engineer, $assigned] as $index => $user) {
            $this->phone($user, 'phone-'.$index);
        }

        $this->actingAs($owner)->post(route('notify.announcements.store'), $this->form(['audience' => 'roles', 'roles' => ['pm']]))->assertSessionHasNoErrors();
        $this->assertSame([0, 1, 0, 0], [count($this->pushesFor($owner)), count($this->pushesFor($pm)), count($this->pushesFor($engineer)), count($this->pushesFor($assigned))]);

        Queue::fake([DeliverNotification::class]);
        $this->actingAs($owner)->post(route('notify.announcements.store'), $this->form(['audience' => 'project', 'project_id' => $project->id]))->assertSessionHasNoErrors();
        // The owner and the PM see every project; the engineer limited to another project is left out.
        $this->assertSame([1, 1, 0, 1], [count($this->pushesFor($owner)), count($this->pushesFor($pm)), count($this->pushesFor($engineer)), count($this->pushesFor($assigned))]);
        $this->assertSame((string) $project->id, $this->dataOf($this->pushesFor($assigned)[0])['project_id']);
    }

    public function test_the_announcements_switch_and_quiet_hours_are_obeyed_and_the_app_still_lists_it(): void
    {
        $this->connectFcm();
        Queue::fake([DeliverNotification::class]);
        $this->at('2026-11-10 23:15');
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $switchedOff = $this->member($organization, 'engineer');
        $sleeping = $this->member($organization, 'engineer');
        $this->phone($switchedOff, 'phone-off');
        $this->phone($sleeping, 'phone-sleeping');
        $this->prefer($switchedOff, ['push_categories' => ['announcements' => false]]);

        $this->actingAs($owner)->post(route('notify.announcements.store'), $this->form())->assertSessionHasNoErrors();

        $this->assertCount(0, $this->pushesFor($switchedOff));
        $held = $this->pushesFor($sleeping);
        $this->assertCount(1, $held);
        $this->assertEquals(now()->setTimezone('Asia/Kolkata')->addDay()->setTime(7, 0)->toIso8601String(), $held[0]->delay->setTimezone('Asia/Kolkata')->toIso8601String());

        Sanctum::actingAs($switchedOff);
        $this->getJson(self::API.'/announcements')->assertOk()->assertJsonPath('data.0.title', 'Site safety week');
    }

    public function test_a_picture_is_kept_on_a_public_address_and_put_in_the_push_for_android_and_iphone(): void
    {
        Storage::fake();
        URL::forceRootUrl('https://nirmaan360.test');
        URL::forceScheme('https');
        $this->connectFcm();
        Queue::fake([DeliverNotification::class]);
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $colleague = $this->member($organization, 'engineer');
        $this->phone($colleague);

        $this->actingAs($owner)->post(route('notify.announcements.store'), $this->form(['image' => UploadedFile::fake()->image('banner.png', 1200, 600)]))->assertSessionHasNoErrors();
        $announcement = Announcement::sole();

        $url = $announcement->imageUrl();
        $this->assertStringStartsWith('https://nirmaan360.test/announcement-images/', $url);
        Storage::assertExists($announcement->image_path);

        $message = app(FcmGateway::class)->payload('token', $this->pushesFor($colleague)[0]->message)['message'];
        $this->assertSame($url, $message['notification']['image']);
        $this->assertSame($url, $message['android']['notification']['image']);
        $this->assertSame(1, $message['apns']['payload']['aps']['mutable-content']);
        $this->assertSame(['image' => $url], $message['apns']['fcm_options']);

        // Firebase and phones fetch it signed out. A wrong token finds nothing.
        auth()->logout();
        $this->get('/announcement-images/'.$announcement->image_token)->assertOk()->assertHeader('Cache-Control', 'max-age=604800, public');
        $this->get('/announcement-images/'.Str::random(40))->assertNotFound();
    }

    public function test_firebase_is_sent_the_announcement_with_its_picture_for_android_and_iphone(): void
    {
        Storage::fake();
        URL::forceRootUrl('https://nirmaan360.test');
        URL::forceScheme('https');
        $this->connectFcm();
        $this->fakeFcm();
        $this->at('2026-11-10 11:00');
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $colleague = $this->member($organization, 'engineer');
        $this->phone($colleague, 'colleague-token');

        // The test queue runs each job at once, so this goes all the way to the HTTP call to Firebase.
        $this->actingAs($owner)->post(route('notify.announcements.store'), $this->form(['image' => UploadedFile::fake()->image('banner.jpg', 1200, 600)]))->assertSessionHasNoErrors();
        $announcement = Announcement::sole();
        $url = $announcement->imageUrl();

        $sent = Http::recorded(fn (Request $request) => str_contains($request->url(), 'fcm.googleapis.com/v1/projects/demo-project/messages:send'));
        $this->assertCount(1, $sent);
        $message = $sent->values()->first()[0]->data()['message'];

        $this->assertSame('colleague-token', $message['token']);
        $this->assertSame(['title' => 'Site safety week', 'body' => 'Wear helmets on every site from Monday.', 'image' => $url], $message['notification']);
        $this->assertSame(['channel_id' => 'updates', 'tag' => 'announcement-'.$announcement->id, 'image' => $url], $message['android']['notification']);
        $this->assertSame('HIGH', $message['android']['priority']);
        $this->assertSame(['sound' => 'default', 'mutable-content' => 1], $message['apns']['payload']['aps']);
        $this->assertSame(['image' => $url], $message['apns']['fcm_options']);
        $this->assertSame('announcement-'.$announcement->id, $message['apns']['headers']['apns-collapse-id']);
        $this->assertSame([
            'repeats' => '0',
            'type' => 'announcement',
            'id' => (string) $announcement->id,
            'category' => 'announcements',
            'event' => 'announcement.sent',
            'critical' => '0',
        ], $message['data']);

        $announcement->refresh();
        $this->assertSame([1, 1, 0], [$announcement->phones_count, $announcement->sent_count, $announcement->failed_count]);
    }

    public function test_a_push_without_a_picture_or_with_an_http_one_carries_no_picture_fields(): void
    {
        $plain = app(FcmGateway::class)->payload('token', new NotificationMessage('announcement.sent', 'Title', 'Body'))['message'];
        $http = app(FcmGateway::class)->payload('token', new NotificationMessage('announcement.sent', 'Title', 'Body', image: 'http://example.test/a.png'))['message'];

        foreach ([$plain, $http] as $message) {
            $this->assertArrayNotHasKey('image', $message['notification']);
            $this->assertArrayNotHasKey('image', $message['android']['notification']);
            $this->assertArrayNotHasKey('mutable-content', $message['apns']['payload']['aps']);
            $this->assertArrayNotHasKey('fcm_options', $message['apns']);
        }
    }

    public function test_the_app_lists_only_what_was_sent_to_this_person_in_their_language(): void
    {
        $mine = $this->tenant('Mine Infra');
        $other = $this->tenant('Other Infra');
        $owner = $this->member($mine, 'owner');
        $colleague = $this->member($mine, 'engineer');
        $stranger = $this->member($other, 'engineer');

        $this->actingAs($owner)->post(route('notify.announcements.store'), $this->form([
            'title_mr' => 'सुरक्षा सप्ताह',
            'body_mr' => 'सोमवारपासून हेल्मेट घाला.',
        ]))->assertSessionHasNoErrors();

        Sanctum::actingAs($colleague);
        $this->getJson(self::API.'/announcements')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Site safety week')
            ->assertJsonPath('data.0.from', 'Mine Infra')
            ->assertJsonPath('data.0.image_url', null)
            ->assertJsonPath('data.0.repeats', false);
        $this->getJson(self::API.'/announcements?locale=mr')->assertOk()->assertJsonPath('data.0.title', 'सुरक्षा सप्ताह')->assertJsonPath('data.0.body', 'सोमवारपासून हेल्मेट घाला.');
        // Hindi was left empty, so it falls back to English.
        $this->getJson(self::API.'/announcements?locale=hi')->assertOk()->assertJsonPath('data.0.title', 'Site safety week');

        Sanctum::actingAs($stranger);
        $this->getJson(self::API.'/announcements')->assertOk()->assertJsonCount(0, 'data');

        $this->getJson(self::API.'/announcements')->assertOk();
        auth()->forgetGuards();
        $this->getJson(self::API.'/announcements')->assertUnauthorized();
    }

    public function test_a_contractor_login_gets_announcements_through_the_portal(): void
    {
        $this->connectFcm();
        Queue::fake([DeliverNotification::class]);
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $contractor = $this->contractorLogin($organization);
        $this->phone($contractor);

        $this->actingAs($owner)->post(route('notify.announcements.store'), $this->form(['audience' => 'roles', 'roles' => ['contractor']]))->assertSessionHasNoErrors();
        $this->assertCount(1, $this->pushesFor($contractor));

        Sanctum::actingAs($contractor);
        $this->getJson(self::API.'/contractor-portal/announcements')->assertOk()->assertJsonPath('data.0.title', 'Site safety week');
        $this->getJson(self::API.'/contractor-portal/notification-preferences')->assertOk()
            ->assertJsonPath('data.push.categories.1.key', 'announcements');
    }

    public function test_an_announcement_set_for_later_goes_once_at_its_time(): void
    {
        $this->connectFcm();
        Queue::fake([DeliverNotification::class]);
        $this->at('2026-11-10 10:00');
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $colleague = $this->member($organization, 'engineer');
        $this->phone($colleague);

        $this->actingAs($owner)->post(route('notify.announcements.store'), $this->form(['schedule' => 'once', 'send_at' => '2026-11-10T09:00']))->assertSessionHasErrors('send_at');
        $this->actingAs($owner)->post(route('notify.announcements.store'), $this->form(['schedule' => 'once', 'send_at' => '2026-11-10T18:30']))->assertSessionHasNoErrors();
        $announcement = Announcement::sole();
        $this->assertSame(Announcement::SCHEDULED, $announcement->status);

        $this->artisan('announcements:send-due')->assertSuccessful();
        $this->assertCount(0, $this->pushesFor($colleague));

        $this->at('2026-11-10 18:31');
        $this->artisan('announcements:send-due')->assertSuccessful();
        $this->artisan('announcements:send-due')->assertSuccessful();

        $this->assertCount(1, $this->pushesFor($colleague));
        $this->assertSame(Announcement::SENT, $announcement->refresh()->status);
        $this->assertNull($announcement->next_run_at);
    }

    public function test_a_daily_reminder_goes_every_day_until_its_last_day_or_until_it_is_stopped(): void
    {
        $this->connectFcm();
        Queue::fake([DeliverNotification::class]);
        $this->at('2026-11-10 10:00');
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $colleague = $this->member($organization, 'engineer');
        $this->phone($colleague);

        $this->actingAs($owner)->post(route('notify.announcements.store'), $this->form(['schedule' => 'daily', 'repeat_time' => '09:00', 'repeat_until' => '2026-11-12']))->assertSessionHasNoErrors();
        $reminder = Announcement::sole();
        $this->assertSame('2026-11-11 09:00', $reminder->next_run_at->setTimezone('Asia/Kolkata')->format('Y-m-d H:i'));

        $this->at('2026-11-11 09:00');
        $this->artisan('announcements:send-due');
        $this->assertCount(1, $this->pushesFor($colleague));
        $this->assertSame('1', $this->dataOf($this->pushesFor($colleague)[0])['repeats']);
        $this->assertSame('announcement.reminder', $this->pushesFor($colleague)[0]->message->event);
        $this->assertSame('2026-11-12 09:00', $reminder->refresh()->next_run_at->setTimezone('Asia/Kolkata')->format('Y-m-d H:i'));

        $this->at('2026-11-12 09:01');
        $this->artisan('announcements:send-due');
        $this->assertCount(2, $this->pushesFor($colleague));
        $reminder->refresh();
        $this->assertSame(Announcement::SENT, $reminder->status);
        $this->assertSame(2, $reminder->runs);
        // Still one person, however many times it reached them, and one row in their list.
        $this->assertSame(2, $reminder->recipients_count);
        $this->assertSame(1, AnnouncementRecipient::withoutGlobalScopes()->where('announcement_id', $reminder->id)->where('user_id', $colleague->id)->count());

        // A weekly one, stopped before it starts, sends nothing.
        $this->actingAs($owner)->post(route('notify.announcements.store'), $this->form(['schedule' => 'weekly', 'repeat_time' => '08:30', 'repeat_weekday' => 1]))->assertSessionHasNoErrors();
        $weekly = Announcement::latest('id')->first();
        $this->assertSame('2026-11-16 08:30 Mon', $weekly->next_run_at->setTimezone('Asia/Kolkata')->format('Y-m-d H:i D'));
        $this->actingAs($owner)->post(route('notify.announcements.cancel', $weekly))->assertSessionHasNoErrors();
        $this->assertSame(Announcement::CANCELLED, $weekly->refresh()->status);
        $this->assertTrue(AuditLog::where('action', 'announcement.cancelled')->where('auditable_id', $weekly->id)->exists());

        $this->at('2026-11-16 08:31');
        $this->artisan('announcements:send-due');
        $this->assertCount(2, $this->pushesFor($colleague));
    }

    public function test_the_same_form_sent_twice_sends_once(): void
    {
        Queue::fake([SendAnnouncement::class]);
        $owner = $this->member($this->tenant(), 'owner');
        $form = $this->form();

        $this->actingAs($owner)->post(route('notify.announcements.store'), $form)->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('notify.announcements.store'), $form)->assertSessionHasNoErrors();

        $this->assertSame(1, Announcement::count());
        Queue::assertPushed(SendAnnouncement::class, 1);
    }

    public function test_each_phones_answer_from_firebase_is_counted(): void
    {
        $this->connectFcm();
        Queue::fake([DeliverNotification::class]);
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $colleague = $this->member($organization, 'engineer');
        $this->phone($colleague, 'phone-a');
        $this->phone($colleague, 'phone-b');

        $this->actingAs($owner)->post(route('notify.announcements.store'), $this->form())->assertSessionHasNoErrors();
        $announcement = Announcement::sole();
        $jobs = $this->pushesFor($colleague);
        $this->assertCount(2, $jobs);

        $this->fakeFcmAnswers([200, ['name' => 'projects/demo-project/messages/1']], [404, ['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]]]);
        foreach ($jobs as $job) {
            app()->call([$job, 'handle']);
        }

        $announcement->refresh();
        $this->assertSame([2, 1, 1], [$announcement->phones_count, $announcement->sent_count, $announcement->failed_count]);
    }

    public function test_stopping_a_reminder_drops_the_copies_held_for_quiet_hours(): void
    {
        $this->connectFcm();
        Queue::fake([DeliverNotification::class]);
        $this->at('2026-11-10 22:00');
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $colleague = $this->member($organization, 'engineer');
        $this->phone($colleague);

        $this->actingAs($owner)->post(route('notify.announcements.store'), $this->form(['schedule' => 'daily', 'repeat_time' => '22:30']))->assertSessionHasNoErrors();
        $reminder = Announcement::sole();

        $this->at('2026-11-10 22:30');
        $this->artisan('announcements:send-due');
        $held = $this->pushesFor($colleague);
        $this->assertCount(1, $held);
        $this->assertNotNull($held[0]->delay, 'held until quiet hours end');

        $this->actingAs($owner)->post(route('notify.announcements.cancel', $reminder))->assertSessionHasNoErrors();

        // 07:00: the held job runs, and Firebase is not called.
        $this->fakeFcm();
        $this->at('2026-11-11 07:00');
        app()->call([$held[0], 'handle']);
        Http::assertNothingSent();
        $this->assertSame([0, 1], [$reminder->refresh()->sent_count, $reminder->skipped_count]);
    }

    public function test_a_rupee_amount_reaches_only_people_who_may_see_money_by_push_and_in_the_app(): void
    {
        $this->connectFcm();
        Queue::fake([DeliverNotification::class]);
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $engineer = $this->member($organization, 'engineer');
        $this->phone($owner, 'phone-owner');
        $this->phone($engineer, 'phone-engineer');

        $this->actingAs($owner)->post(route('notify.announcements.store'), $this->form(['title_hi' => 'बोनस ₹5000 मिलेगा']))->assertSessionHasNoErrors();

        $this->assertCount(1, $this->pushesFor($owner));
        $this->assertCount(0, $this->pushesFor($engineer));
        Sanctum::actingAs($engineer);
        $this->getJson(self::API.'/announcements')->assertOk()->assertJsonCount(0, 'data');
        Sanctum::actingAs($owner);
        $this->getJson(self::API.'/announcements?locale=hi')->assertOk()->assertJsonPath('data.0.title', 'बोनस ₹5000 मिलेगा');
    }

    public function test_a_picture_is_redrawn_as_a_small_jpeg_before_it_is_kept(): void
    {
        Storage::fake();
        $owner = $this->member($this->tenant(), 'owner');

        $this->actingAs($owner)->post(route('notify.announcements.store'), $this->form(['image' => UploadedFile::fake()->image('wide.png', 2400, 1200)]))->assertRedirect(route('notify.announcements'))->assertSessionHasNoErrors();

        $path = Announcement::sole()->image_path;
        $this->assertStringEndsWith('.jpg', $path);
        $info = getimagesizefromstring(Storage::get($path));
        $this->assertSame([1200, 600, 'image/jpeg'], [$info[0], $info[1], $info['mime']]);
    }

    public function test_a_large_audience_is_pushed_in_short_batches(): void
    {
        $this->connectFcm();
        Queue::fake([DeliverNotification::class]);
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        foreach (range(1, 3) as $index) {
            $this->phone($this->member($organization, 'engineer'), 'phone-'.$index);
        }

        $this->actingAs($owner)->post(route('notify.announcements.store'), $this->form())->assertSessionHasNoErrors();

        $announcement = Announcement::sole();
        $this->assertSame([4, 3], [$announcement->recipients_count, $announcement->phones_count]);
        $this->assertCount(3, Queue::pushed(DeliverNotification::class));

        // Sent again for the same run, as a retried job would be: nobody is pushed twice and nothing is counted twice.
        app(AnnouncementService::class)->sendBatch($announcement, 'once', User::where('organization_id', $organization->id)->pluck('id')->all());
        $this->assertCount(3, Queue::pushed(DeliverNotification::class));
        $this->assertSame(3, $announcement->refresh()->phones_count);
    }

    public function test_a_run_that_cannot_start_is_shown_as_failed_and_logged(): void
    {
        Queue::fake([SendAnnouncement::class]);
        $owner = $this->member($this->tenant(), 'owner');
        $this->actingAs($owner)->post(route('notify.announcements.store'), $this->form())->assertSessionHasNoErrors();
        $announcement = Announcement::sole();

        (new SendAnnouncement($announcement->id, 'once'))->failed(new \RuntimeException('database went away'));

        $this->assertSame(Announcement::FAILED, $announcement->refresh()->status);
        $this->assertTrue(AuditLog::where('action', 'announcement.failed')->where('auditable_id', $announcement->id)->exists());
        $this->actingAs($owner)->get(route('notify.announcements'))->assertOk()->assertSee(__('Could not be sent. Write it again to retry.'));
    }

    public function test_the_history_shows_when_a_stopped_one_was_meant_to_go(): void
    {
        $this->at('2026-11-10 11:00');
        $owner = $this->member($this->tenant(), 'owner');
        $this->actingAs($owner)->post(route('notify.announcements.store'), $this->form(['schedule' => 'once', 'send_at' => '2026-11-20T18:30']))->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('notify.announcements.cancel', Announcement::sole()))->assertSessionHasNoErrors();

        $this->actingAs($owner)->get(route('notify.announcements'))->assertOk()->assertSee('Once, on 20 Nov 2026, 18:30');
    }

    public function test_the_choices_are_checked(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin)->post(route('admin.announcements.store'), $this->form(['title' => '', 'audience' => 'everyone', 'schedule' => 'hourly']))
            ->assertSessionHasErrors(['title', 'audience', 'schedule']);
        $this->actingAs($admin)->post(route('admin.announcements.store'), $this->form(['audience' => 'company']))->assertSessionHasErrors('organization_id');
        $this->actingAs($admin)->post(route('admin.announcements.store'), $this->form(['audience' => 'roles', 'roles' => ['emperor']]))->assertSessionHasErrors('roles.0');
        $this->actingAs($admin)->post(route('admin.announcements.store'), $this->form(['schedule' => 'weekly', 'repeat_time' => '25:00']))->assertSessionHasErrors(['repeat_time', 'repeat_weekday']);
        $this->actingAs($admin)->post(route('admin.announcements.store'), $this->form(['image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')]))->assertSessionHasErrors('image');
        $this->actingAs($admin)->post(route('admin.announcements.store'), $this->form(['image' => UploadedFile::fake()->image('huge.jpg')->size(3000)]))->assertSessionHasErrors('image');

        $this->assertSame(0, Announcement::count());
    }

    public function test_staff_are_offered_the_announcements_switch(): void
    {
        Sanctum::actingAs($this->member($this->tenant(), 'viewer'));

        $this->assertContains('announcements', array_column($this->getJson(self::API.'/notification-preferences')->json('data.push.categories'), 'key'));
        $this->putJson(self::API.'/notification-preferences', ['categories' => ['announcements' => false]])->assertOk()
            ->assertJsonPath('data.push.categories.3.key', 'announcements')
            ->assertJsonPath('data.push.categories.3.enabled', false);
    }
}
