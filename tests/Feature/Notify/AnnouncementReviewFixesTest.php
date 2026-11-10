<?php

namespace Tests\Feature\Notify;

use App\Jobs\Notify\DeliverNotification;
use App\Jobs\Notify\SendAnnouncement;
use App\Jobs\Notify\SendAnnouncementBatch;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\CustomRole;
use App\Models\Organization;
use App\Models\Party;
use App\Models\User;
use App\Services\Notify\AnnouncementService;
use App\Services\Notify\MoneyMention;
use App\Services\Notify\PushDispatcher;
use App\Support\PlatformSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;

/** The findings of the review of feat/announcements, each held down by a test. */
class AnnouncementReviewFixesTest extends NotifyTestCase
{
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

    private function contractorLogin(Organization $organization): User
    {
        $party = Party::create(['organization_id' => $organization->id, 'type' => 'subcontractor', 'name' => 'Shree Formwork']);

        return User::factory()->create(['organization_id' => $organization->id, 'role' => User::CONTRACTOR_ROLE, 'contractor_party_id' => $party->id, 'is_active' => true]);
    }

    private function send(User $as, array $overrides = []): Announcement
    {
        $this->actingAs($as)->post(route('notify.announcements.store'), $this->form($overrides))->assertSessionHasNoErrors();

        return Announcement::latest('id')->firstOrFail();
    }

    // 1
    public function test_everyone_means_the_team_not_contractor_logins(): void
    {
        $this->connectFcm();
        Queue::fake([DeliverNotification::class]);
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $contractor = $this->contractorLogin($organization);
        $this->phone($contractor);
        $admin = $this->member($this->tenant('Platform Staff'), 'owner');
        $admin->forceFill(['is_platform_admin' => true])->save();

        $this->send($owner);
        $this->actingAs($admin)->post(route('admin.announcements.store'), $this->form(['title' => 'Platform news']))->assertSessionHasNoErrors();

        $this->assertCount(0, $this->pushesFor($contractor));
        Sanctum::actingAs($contractor);
        $this->getJson(self::API.'/contractor-portal/announcements')->assertOk()->assertJsonCount(0, 'data');

        // Ticking the contractor role still reaches them.
        $this->send($owner, ['audience' => 'roles', 'roles' => ['contractor'], 'title' => 'For contractors']);
        $this->assertCount(1, $this->pushesFor($contractor));
        Sanctum::actingAs($contractor);
        $this->getJson(self::API.'/contractor-portal/announcements')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'For contractors');
    }

    // 2
    public function test_money_is_found_however_it_is_written(): void
    {
        foreach ([
            '५,००० रुपये', 'रु. 5000', '5000 Rs', 'Rupees 5000', '45 lakh', 'Rs: 5000', 'Rs - 5000', "₹\u{200B}5000", '₹ 5,000', 'INR 300',
            '2 crore', '3 करोड़', "3 करो\u{095C}", 'Pay 5000/-', '₨500', '10 lac', '1.5 cr', 'रू 200', '२ लाख',
            // A scale word between, Romanised Hindi, punctuation between, and amounts written in words.
            'हर मज़दूर को 2 हज़ार रुपये बोनस', 'प्रत्येकी ५ हजार रुपयांचे बक्षीस', 'Diwali bonus: 5 thousand rupees each', '5k rupees', '5 thousand Rs', '५ हजार रु.',
            '500 rupaye', 'rupay 300', 'rupiya 300', 'rupye 300', 'Amount (₹): 5000', 'Amount (Rs): 5000', 'Rs. ~5000', 'Rs/- 5000',
            'पाँच हजार रुपये', 'Rupees five thousand only', '₹ (five thousand)',
            'Har mazdoor ko 500 rupaya bonus milega', '500 rupaiyaa', 'Diwali bonus Rs. one thousand for every worker', 'रु. पाच हजार फक्त',
            'रु. पाँच हजार बोनस', 'INR five lakh', '2 crs', '५ लक्ष', 'रु० 500', '500 रु',
        ] as $text) {
            $this->assertTrue(MoneyMention::in($text), $text);
        }

        foreach (['Floor 3 crane', 'Wear helmets from Monday 16 November.', 'RSVP by 5 pm', 'Block 2 is ready', 'crore of people', 'Meeting at 10:30', '5 thousand workers on site', 'रूप बदला', 'सोमवारपासून हेल्मेट घाला.',
            // Words that end or start with रु or रू are not rupees.
            'टॉवर B मध्ये स्लॅबचे काम सुरू. 2 दिवस लिफ्ट बंद राहील.', 'काम सुरू: 10 नोव्हेंबर', 'कल से काम शुरू। 2 दिन छुट्टी के बाद', 'फ्लैट 302 रूम की सफाई करें', '3 रूफ शीट बदलो', 'गुरु 5 बजे आएंगे', 'लक्ष द्या: 3 नंबर गेट बंद'] as $text) {
            $this->assertFalse(MoneyMention::in($text), $text);
        }
    }

    public function test_money_in_any_language_or_ticked_by_hand_keeps_it_from_people_without_money_view(): void
    {
        $this->connectFcm();
        Queue::fake([DeliverNotification::class]);
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $engineer = $this->member($organization, 'engineer');
        $this->phone($owner, 'phone-owner');
        $this->phone($engineer, 'phone-engineer');

        $marathi = $this->send($owner, ['body_mr' => 'बोनस ५,००० रुपये मिळेल.']);
        $ticked = $this->send($owner, ['title' => 'Bonus chart in the picture', 'has_money' => '1']);
        $plain = $this->send($owner, ['title' => 'No figures here']);

        $this->assertSame([true, true, false], [$marathi->mentions_money, $ticked->mentions_money, $plain->mentions_money]);
        $this->assertCount(3, $this->pushesFor($owner));
        $this->assertCount(1, $this->pushesFor($engineer));

        // The writer is told, so a message taken for money is not a surprise.
        $this->actingAs($owner)->post(route('notify.announcements.store'), $this->form(['title' => 'Rs 500 each']))
            ->assertSessionHas('success', fn (string $text) => str_contains($text, __('It has money figures, so it goes only to people who may see money.')));
    }

    // 3
    public function test_the_history_hides_money_figures_from_an_admin_without_money_view(): void
    {
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $role = CustomRole::create(['organization_id' => $organization->id, 'name' => 'Office admin', 'permissions' => ['settings.manage'], 'created_by' => $owner->id]);
        $officeAdmin = $this->member($organization, 'engineer');
        $officeAdmin->forceFill(['custom_role_id' => $role->id])->save();

        $this->send($owner, ['title' => 'Bonus ₹5000 for the best site', 'body' => 'Paid with the March bill.']);

        $this->actingAs($officeAdmin)->get(route('notify.announcements'))->assertOk()
            ->assertSee(__('Message with money figures'))->assertDontSee('Bonus ₹5000')->assertDontSee('Paid with the March bill.');
        $this->actingAs($owner)->get(route('notify.announcements'))->assertOk()->assertSee('Bonus ₹5000 for the best site');
    }

    // 4
    public function test_the_app_list_checks_money_view_again_when_it_is_read(): void
    {
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $manager = $this->member($organization, 'admin');
        $this->send($owner, ['title' => 'Budget 45 lakh approved']);

        Sanctum::actingAs($manager);
        $this->getJson(self::API.'/announcements')->assertOk()->assertJsonCount(1, 'data');

        $manager->forceFill(['role' => 'engineer'])->save();
        $this->getJson(self::API.'/announcements')->assertOk()->assertJsonCount(0, 'data');
    }

    // 5
    public function test_a_partly_sent_one_can_be_sent_again_to_the_people_it_missed(): void
    {
        $this->connectFcm();
        Queue::fake([DeliverNotification::class]);
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $reached = $this->member($organization, 'engineer');
        $this->phone($reached, 'phone-reached');

        $announcement = $this->send($owner);
        $this->assertCount(1, $this->pushesFor($reached));

        // The run stopped after its batch was queued, and then failed for good.
        Announcement::whereKey($announcement->id)->update(['status' => Announcement::SENDING]);
        $announcement->refresh();
        app(AnnouncementService::class)->runFailed($announcement->id, 'once', new RuntimeException('worker died'), $announcement->run_token, (string) $announcement->started_by);
        $this->assertSame(Announcement::PARTIAL, $announcement->refresh()->status);
        $this->actingAs($owner)->get(route('notify.announcements'))->assertSee(__('Partly sent: it stopped before reaching everyone.'))->assertSee(__('Send again'));

        $missed = $this->member($organization, 'engineer');
        $this->phone($missed, 'phone-missed');
        $this->actingAs($owner)->post(route('notify.announcements.retry', $announcement))->assertSessionHasNoErrors();

        $this->assertCount(1, $this->pushesFor($reached), 'nobody is pushed twice');
        $this->assertCount(1, $this->pushesFor($missed));
        $this->assertSame(Announcement::SENT, $announcement->refresh()->status);
        $this->actingAs($owner)->post(route('notify.announcements.retry', $announcement))->assertSessionHasErrors('announcement');

        // Once the claims on the run have lapsed, a partly sent one cannot be sent again: it would reach people twice.
        Announcement::whereKey($announcement->id)->update(['status' => Announcement::PARTIAL]);
        $this->travel(24)->hours();
        $this->actingAs($owner)->post(route('notify.announcements.retry', $announcement))->assertSessionHasErrors('announcement');
    }

    public function test_a_run_that_queued_nobody_is_failed_not_partial(): void
    {
        Queue::fake([SendAnnouncement::class]);
        $owner = $this->member($this->tenant(), 'owner');
        $announcement = $this->send($owner);

        // A stale job, whose run was queued again under another token, changes nothing.
        app(AnnouncementService::class)->runFailed($announcement->id, 'once', null, 'stale-token', 'a-copy');
        $this->assertSame(Announcement::SENDING, $announcement->refresh()->status);

        app(AnnouncementService::class)->runFailed($announcement->id, 'once', null, $announcement->run_token, 'a-copy');

        $this->assertSame(Announcement::FAILED, $announcement->refresh()->status);
    }

    public function test_send_again_is_counted_from_when_the_run_started_not_from_later_counting(): void
    {
        $this->connectFcm();
        Queue::fake([DeliverNotification::class]);
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $this->phone($this->member($organization, 'engineer'));
        $announcement = $this->send($owner);
        Announcement::whereKey($announcement->id)->update(['status' => Announcement::PARTIAL]);

        // Delivery counts keep moving updated_at long after the run started.
        $this->travel(24)->hours();
        Announcement::whereKey($announcement->id)->increment('sent_count');
        $this->assertFalse($announcement->refresh()->canBeRetried());
        $this->actingAs($owner)->post(route('notify.announcements.retry', $announcement))->assertSessionHasErrors('announcement');
    }

    public function test_a_send_again_does_not_restart_the_window_of_the_people_first_reached(): void
    {
        $this->connectFcm();
        Queue::fake([DeliverNotification::class]);
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $this->phone($this->member($organization, 'engineer'));
        $announcement = $this->send($owner);
        $firstBatch = $announcement->refresh()->first_batch_at;
        $this->assertNotNull($firstBatch);

        // Twenty hours later it is sent again and ends partly sent once more.
        Announcement::whereKey($announcement->id)->update(['status' => Announcement::PARTIAL]);
        $this->travel(20)->hours();
        $this->actingAs($owner)->post(route('notify.announcements.retry', $announcement))->assertSessionHasNoErrors();
        Announcement::whereKey($announcement->id)->update(['status' => Announcement::PARTIAL]);
        $this->assertTrue($firstBatch->equalTo($announcement->refresh()->first_batch_at));

        // Six hours on, the first people's claims have lapsed, so it is not offered again.
        $this->travel(6)->hours();
        $this->assertFalse($announcement->refresh()->canBeRetried());
    }

    public function test_a_batch_that_runs_out_of_retries_marks_a_one_off_partly_sent(): void
    {
        Queue::fake([DeliverNotification::class]);
        $this->connectFcm();
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $announcement = $this->send($owner);
        $this->assertSame(Announcement::SENT, $announcement->status);

        (new SendAnnouncementBatch($announcement->id, 'once', [$owner->id]))->failed(new RuntimeException('queue table locked'));

        $this->assertSame(Announcement::PARTIAL, $announcement->refresh()->status);
        $this->assertTrue($announcement->canBeRetried());
        $this->assertTrue(AuditLog::where('action', 'announcement.batch_failed')->where('auditable_id', $announcement->id)->exists());
    }

    public function test_send_again_is_only_for_the_company_that_owns_it_and_its_admins(): void
    {
        Queue::fake([SendAnnouncement::class]);
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $announcement = $this->send($owner);
        Announcement::whereKey($announcement->id)->update(['status' => Announcement::FAILED]);

        $this->actingAs($this->member($this->tenant('Other Infra'), 'owner'))->post(route('notify.announcements.retry', $announcement))->assertNotFound();
        $this->actingAs($this->member($organization, 'engineer'))->post(route('notify.announcements.retry', $announcement))->assertForbidden();
        $this->actingAs($owner)->post(route('admin.announcements.retry', $announcement))->assertNotFound();
        $this->assertSame(Announcement::FAILED, $announcement->refresh()->status);

        $this->actingAs($owner)->post(route('notify.announcements.retry', $announcement))->assertSessionHasNoErrors();
        $this->assertSame(Announcement::SENDING, $announcement->refresh()->status);
    }

    // 6
    public function test_a_send_now_that_cannot_be_queued_is_picked_up_by_the_scheduler_with_its_picture(): void
    {
        $this->connectFcm();
        Queue::fake([DeliverNotification::class]);
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $colleague = $this->member($organization, 'engineer');
        $this->phone($colleague);

        config(['queue.default' => 'missing']);
        $announcement = $this->send($owner);
        $this->assertSame(Announcement::SCHEDULED, $announcement->status);
        $this->assertNotNull($announcement->next_run_at);
        $this->assertCount(0, $this->pushesFor($colleague));

        config(['queue.default' => 'sync']);
        $this->artisan('announcements:send-due')->assertSuccessful();
        $this->assertCount(1, $this->pushesFor($colleague));
        $this->assertSame(Announcement::SENT, $announcement->refresh()->status);
    }

    public function test_a_run_waiting_too_long_is_queued_again_and_runs_once_whichever_copy_comes_first(): void
    {
        $this->connectFcm();
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $colleague = $this->member($organization, 'engineer');
        $this->phone($colleague);
        Queue::fake([SendAnnouncement::class, DeliverNotification::class]);
        $announcement = $this->send($owner);
        $this->assertSame(Announcement::SENDING, $announcement->status);

        // The claimed job has not started. A few minutes is not enough to call it lost.
        $this->travel(5)->minutes();
        $this->artisan('announcements:send-due');
        Queue::assertPushed(SendAnnouncement::class, 1);

        $this->travel(15)->minutes();
        $this->artisan('announcements:send-due');
        $this->artisan('announcements:send-due');
        Queue::assertPushed(SendAnnouncement::class, 2);
        [$first, $second] = Queue::pushed(SendAnnouncement::class)->values()->all();
        $this->assertSame($first->token, $second->token, 'the same run');
        $this->assertNotSame($first->copy, $second->copy);

        // The copy queued again happens to run first and takes the run; the first copy, still waiting, then does nothing.
        Queue::fake([DeliverNotification::class]);
        app()->call([$second, 'handle']);
        app()->call([$first, 'handle']);
        $this->assertSame([1, Announcement::SENT], [$announcement->refresh()->runs, $announcement->status]);
        $this->assertCount(1, $this->pushesFor($colleague));
        $this->assertSame($second->copy, $announcement->started_by);
    }

    public function test_a_run_stopped_before_it_started_can_be_stopped_however_old(): void
    {
        Queue::fake([SendAnnouncement::class]);
        $owner = $this->member($this->tenant(), 'owner');
        $announcement = $this->send($owner);
        Announcement::whereKey($announcement->id)->update(['created_at' => now()->subDays(3), 'scheduled_for' => now()->subDays(3)]);

        $this->assertTrue($announcement->refresh()->canBeCancelled());
        $this->actingAs($owner)->post(route('notify.announcements.cancel', $announcement))->assertSessionHasNoErrors();

        [$job] = Queue::pushed(SendAnnouncement::class)->values()->all();
        app()->call([$job, 'handle']);
        $this->assertSame([0, Announcement::CANCELLED], [$announcement->refresh()->runs, $announcement->status]);
    }

    public function test_a_reminders_last_run_is_queued_again_when_it_waits_too_long(): void
    {
        Queue::fake([SendAnnouncement::class]);
        $this->at('2026-11-10 08:00');
        $owner = $this->member($this->tenant(), 'owner');
        $this->send($owner, ['schedule' => 'daily', 'repeat_time' => '09:00', 'repeat_until' => '2026-11-10']);

        $this->at('2026-11-10 09:00');
        $this->artisan('announcements:send-due');
        $this->assertSame(Announcement::SENDING, Announcement::sole()->status, 'its last run');

        $this->at('2026-11-10 09:30');
        $this->artisan('announcements:send-due');
        Queue::assertPushed(SendAnnouncement::class, 2);
        [$first, $second] = Queue::pushed(SendAnnouncement::class)->values()->all();
        $this->assertSame([$first->token, $first->runKey], [$second->token, $second->runKey], 'the same run, not the next day');
    }

    public function test_a_contractor_login_without_the_portal_or_its_subcontractor_gets_nothing(): void
    {
        $this->connectFcm();
        Queue::fake([DeliverNotification::class]);
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $linked = $this->contractorLogin($organization);
        $unlinked = $this->contractorLogin($organization);
        Party::whereKey($unlinked->contractor_party_id)->update(['type' => 'supplier']);
        $this->phone($linked, 'phone-linked');
        $this->phone($unlinked, 'phone-unlinked');

        $this->send($owner, ['audience' => 'roles', 'roles' => ['contractor']]);
        $this->assertSame([1, 0], [count($this->pushesFor($linked)), count($this->pushesFor($unlinked))]);
        Sanctum::actingAs($unlinked);
        $this->getJson(self::API.'/contractor-portal/announcements')->assertForbidden();

        // A company whose plan has no contractor portal does not reach its contractor logins at all.
        $free = $this->tenant('Free Builders', 'free');
        $freeOwner = $this->member($free, 'owner');
        $freeContractor = $this->contractorLogin($free);
        $this->phone($freeContractor, 'phone-free');
        $this->actingAs($freeOwner)->post(route('notify.announcements.store'), $this->form(['audience' => 'roles', 'roles' => ['contractor']]))->assertSessionHasNoErrors();
        $this->assertCount(0, $this->pushesFor($freeContractor));
    }

    // 7
    public function test_a_batch_retries_the_people_it_could_not_queue_and_counts_each_one_queued(): void
    {
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $first = $this->member($organization, 'engineer');
        $flaky = $this->member($organization, 'engineer');
        $last = $this->member($organization, 'engineer');
        $this->connectFcm();
        Queue::fake([SendAnnouncement::class]);
        $announcement = $this->send($owner);

        $queued = [];
        $failOnce = true;
        $this->partialMock(PushDispatcher::class, function ($mock) use (&$queued, &$failOnce, $flaky) {
            $mock->shouldReceive('queue')->andReturnUsing(function (User $user) use (&$queued, &$failOnce, $flaky) {
                if ($user->is($flaky) && $failOnce) {
                    $failOnce = false;
                    throw new RuntimeException('queue table locked');
                }

                // Like the receipts: whoever was already queued under this run is passed over.
                if (in_array($user->id, $queued, true)) {
                    return 0;
                }

                $queued[] = $user->id;

                return 1;
            });
        });
        $service = app(AnnouncementService::class);
        $ids = [$first->id, $flaky->id, $last->id];

        try {
            $service->sendBatch($announcement, 'once', $ids);
            $this->fail('A batch that missed someone must fail so the queue tries it again.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not queued for 1 people', $exception->getMessage());
        }
        $this->assertSame(2, $announcement->refresh()->phones_count, 'counted as each one is queued');

        $service->sendBatch($announcement, 'once', $ids);
        $this->assertSame(3, $announcement->refresh()->phones_count);
        $this->assertEqualsCanonicalizing($ids, $queued);
    }

    // 8
    public function test_a_one_off_held_for_quiet_hours_can_still_be_stopped(): void
    {
        $this->connectFcm();
        Queue::fake([DeliverNotification::class]);
        $this->at('2026-11-10 22:30');
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $colleague = $this->member($organization, 'engineer');
        $this->phone($colleague);

        $announcement = $this->send($owner, ['title' => 'Wrong figure, sorry']);
        $this->assertSame(Announcement::SENT, $announcement->status);
        $held = $this->pushesFor($colleague)[0];
        $this->assertNotNull($held->delay);

        $this->actingAs($owner)->post(route('notify.announcements.cancel', $announcement))->assertSessionHasNoErrors();
        $this->assertSame(Announcement::CANCELLED, $announcement->refresh()->status);

        $this->fakeFcm();
        $this->at('2026-11-11 07:00');
        app()->call([$held, 'handle']);
        Http::assertNothingSent();

        // Past the longest quiet hours nothing can be waiting any more, so there is nothing to stop.
        $old = $this->send($owner, ['title' => 'Old news']);
        $this->travel(Announcement::STOP_WINDOW_HOURS + 1)->hours();
        $this->actingAs($owner)->post(route('notify.announcements.cancel', $old))->assertSessionHasErrors('announcement');
    }

    // 9
    public function test_the_scheduler_lock_expires_in_minutes_not_a_day(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'announcements:send-due'));

        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(5, $event->expiresAt);
        $this->assertTrue($event->onOneServer);
    }

    // 10
    public function test_a_company_has_limits_on_waiting_and_daily_announcements(): void
    {
        Queue::fake([SendAnnouncement::class]);
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');

        foreach (range(1, AnnouncementService::COMPANY_ACTIVE_MAX) as $index) {
            // Past the page's own limit of 20 posts a minute per person.
            $this->travel(4)->seconds();
            $this->send($owner, ['schedule' => 'daily', 'repeat_time' => '09:00', 'title' => 'Reminder '.$index]);
        }
        $this->travel(1)->minutes();
        $this->actingAs($owner)->post(route('notify.announcements.store'), $this->form(['schedule' => 'daily', 'repeat_time' => '09:00']))->assertSessionHasErrors('schedule');

        // With room again, the day's count still stops it. It is the company's, whoever writes.
        $this->actingAs($owner)->post(route('notify.announcements.cancel', Announcement::first()))->assertSessionHasNoErrors();
        $other = $this->member($organization, 'admin');
        $this->actingAs($other)->post(route('notify.announcements.store'), $this->form())->assertSessionHasErrors('title');
        $this->assertSame(AnnouncementService::COMPANY_ACTIVE_MAX, Announcement::count());

        // Another company is not held back.
        $this->send($this->member($this->tenant('Other Infra'), 'owner'));
        $this->assertSame(AnnouncementService::COMPANY_ACTIVE_MAX + 1, Announcement::count());
    }

    public function test_the_daily_limit_is_the_india_calendar_day(): void
    {
        Queue::fake([SendAnnouncement::class]);
        $this->at('2026-11-10 23:00');
        $owner = $this->member($this->tenant(), 'owner');

        foreach (range(1, AnnouncementService::COMPANY_DAILY_MAX) as $index) {
            $this->travel(4)->seconds();
            $this->send($owner, ['title' => 'Notice '.$index]);
        }
        $this->travel(1)->minutes();
        $this->actingAs($owner)->post(route('notify.announcements.store'), $this->form())->assertSessionHasErrors('title');

        $this->at('2026-11-11 00:05');
        $this->actingAs($owner)->post(route('notify.announcements.store'), $this->form())->assertSessionHasNoErrors();
    }

    public function test_one_scheduler_pass_starts_every_due_run_across_companies(): void
    {
        Queue::fake([SendAnnouncement::class]);
        $this->at('2026-11-10 10:00');
        $busy = $this->tenant('Busy Infra');
        $quiet = $this->tenant('Quiet Infra');

        foreach ([[$busy, 70], [$quiet, 1]] as [$organization, $count]) {
            foreach (range(1, $count) as $index) {
                Announcement::create([
                    'organization_id' => $organization->id, 'client_uuid' => (string) Str::uuid(), 'title' => 'Due '.$index, 'body' => 'Due',
                    'audience' => 'all', 'target_organization_id' => $organization->id, 'schedule' => 'once',
                    'scheduled_for' => now()->subMinutes(5), 'next_run_at' => now()->subMinutes($organization->is($quiet) ? 1 : 5), 'status' => Announcement::SCHEDULED,
                ]);
            }
        }

        $this->artisan('announcements:send-due')->assertSuccessful();

        Queue::assertPushed(SendAnnouncement::class, 71);
        $this->assertSame(0, Announcement::due()->count());
    }

    // 11
    public function test_pushes_that_end_early_are_counted_as_skipped(): void
    {
        $this->connectFcm();
        Queue::fake([DeliverNotification::class]);
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $leaver = $this->member($organization, 'engineer');
        $stayer = $this->member($organization, 'engineer');
        $this->phone($leaver, 'phone-leaver');
        $this->phone($stayer, 'phone-stayer');
        $announcement = $this->send($owner);
        [$leaving] = $this->pushesFor($leaver);
        [$staying] = $this->pushesFor($stayer);

        $leaver->forceFill(['is_active' => false])->save();
        app()->call([$leaving, 'handle']);

        // Push disconnected before the job ran.
        app(PlatformSettings::class)->set(['fcm_service_account' => null]);
        app()->call([$staying, 'handle']);

        $this->assertSame([0, 2], [$announcement->refresh()->sent_count, $announcement->skipped_count]);
    }
}
