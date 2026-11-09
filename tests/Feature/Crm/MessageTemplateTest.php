<?php

namespace Tests\Feature\Crm;

use App\Models\CrmActivity;
use App\Models\CrmTemplate;
use App\Services\Crm\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsTenant;
use Tests\TestCase;

class MessageTemplateTest extends TestCase
{
    use BuildsCrm, BuildsTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        app()->setLocale('en');
    }

    public function test_indian_mobile_numbers_get_the_country_code(): void
    {
        $this->assertSame('919876543210', MessageService::normalisePhone('9876543210'));
        $this->assertSame('919876543210', MessageService::normalisePhone('98765 43210'));
        $this->assertSame('919876543210', MessageService::normalisePhone('098765-43210'));
        $this->assertSame('919876543210', MessageService::normalisePhone('+91 98765 43210'));
        $this->assertSame('919876543210', MessageService::normalisePhone('919876543210'));
        $this->assertSame('971501234567', MessageService::normalisePhone('+971 50 123 4567'));
        $this->assertSame('971501234567', MessageService::normalisePhone('00971501234567'));
    }

    public function test_numbers_that_cannot_be_dialled_are_refused(): void
    {
        foreach ([null, '', '   ', 'n/a', '12345', '1234567890', '0201234567', '+12', '98765', '5555555555'] as $phone) {
            $this->assertNull(MessageService::normalisePhone($phone), var_export($phone, true));
        }
    }

    public function test_a_whatsapp_link_carries_the_number_and_the_encoded_text(): void
    {
        $url = MessageService::whatsappUrl('9876543210', "Hello Ramesh,\nPlease call & confirm: 50% done #1");

        $this->assertSame('https://wa.me/919876543210?text=Hello%20Ramesh%2C%0APlease%20call%20%26%20confirm%3A%2050%25%20done%20%231', $url);
        $this->assertNull(MessageService::whatsappUrl('not a number', 'Hi'));
    }

    public function test_a_mail_link_carries_the_address_subject_and_body(): void
    {
        $url = MessageService::mailtoUrl('ramesh@example.test', 'Query on NH-48', "Dear Sir,\n\nThanks & regards");

        $this->assertSame('mailto:ramesh%40example.test?subject=Query%20on%20NH-48&body=Dear%20Sir%2C%0A%0AThanks%20%26%20regards', $url);
        $this->assertNull(MessageService::mailtoUrl('not-an-address', 'x', 'y'));
        $this->assertNull(MessageService::mailtoUrl(null, 'x', 'y'));
        $this->assertNull(MessageService::mailtoUrl("a@b.test\r\nBcc: x@y.test", 'x', 'y'));
    }

    public function test_words_in_curly_brackets_are_filled_from_the_tender(): void
    {
        $user = $this->crmUser();
        $client = $this->makeParty($user->organization);
        $tender = $this->makeTender($user->organization, [
            'title' => 'Mula bridge', 'reference' => 'PWD/114', 'party_id' => $client->id, 'bid_value' => '48000000.00',
            'due_at' => '2026-12-20 15:00:00', 'pre_bid_at' => '2026-12-10 11:00:00',
        ]);
        $template = CrmTemplate::create([
            'organization_id' => $user->organization_id, 'name' => 'T', 'channel' => 'whatsapp',
            'body' => '{contact} of {company}: {tender} ({reference}) due {due_date}, pre-bid {pre_bid_date}, worth {value}. From {my_name}, {my_company}.',
        ]);

        $message = app(MessageService::class)->compose($template, $tender->load('party'), $user);

        $this->assertSame(
            'Mr. Deshmukh of PWD Pune: Mula bridge (PWD/114) due 20 Dec 2026, 03:00 PM, pre-bid 10 Dec 2026, 11:00 AM, worth ₹4,80,00,000.00. From '.$user->name.', Acme Infra.',
            $message['body'],
        );
        $this->assertSame('9822012345', $message['phone']);
        $this->assertStringStartsWith('https://wa.me/919822012345?text=Mr.%20Deshmukh', $message['whatsapp_url']);
        $this->assertNull($message['mailto_url']);
        $this->assertSame([], $message['empty']);
    }

    public function test_words_a_record_has_nothing_for_are_reported_and_unknown_words_are_left_alone(): void
    {
        $user = $this->crmUser();
        $lead = $this->makeLead($user->organization, ['phone' => null]);
        $template = CrmTemplate::create(['organization_id' => $user->organization_id, 'name' => 'T', 'channel' => 'whatsapp', 'body' => 'Hi {contact}, about {tender} on {due_date}. {nonsense}']);

        $message = app(MessageService::class)->compose($template, $lead, $user);

        $this->assertSame('Hi Ramesh Kulkarni, about  on . {nonsense}', $message['body']);
        $this->assertSame(['tender', 'due_date'], $message['empty']);
        $this->assertNull($message['whatsapp_url']);
    }

    public function test_an_email_template_fills_the_subject_too(): void
    {
        $user = $this->crmUser();
        $party = $this->makeParty($user->organization);
        $template = CrmTemplate::create(['organization_id' => $user->organization_id, 'name' => 'Mail', 'channel' => 'email', 'subject' => 'Hello {company}', 'body' => "Dear {contact},\n\nRegards"]);

        $message = app(MessageService::class)->compose($template, $party, $user);

        $this->assertSame('Hello PWD Pune', $message['subject']);
        $this->assertStringStartsWith('mailto:pwd%40example.test?subject=Hello%20PWD%20Pune&body=Dear%20Mr.%20Deshmukh', $message['mailto_url']);
        $this->assertNull($message['whatsapp_url']);
    }

    public function test_the_compose_page_shows_the_message_and_never_sends_anything(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization, ['party_id' => $this->makeParty($user->organization)->id]);
        $template = app(MessageService::class)->templates($user->organization_id)->firstWhere('name', 'Tender follow-up');

        $response = $this->get(route('crm.messages.compose', ['template' => $template->id, 'subject_type' => 'tender', 'subject_id' => $tender->id]));

        $response->assertOk()->assertSee('Open in WhatsApp')->assertSee('Mr. Deshmukh')->assertSee('Road widening, NH-48')->assertSee('Nothing is sent from this page.');
        $response->assertSee('wa.me', false);
        $this->assertSame(0, CrmActivity::where('kind', 'whatsapp')->count());
    }

    public function test_the_compose_page_explains_a_missing_number(): void
    {
        $user = $this->crmUser();
        $lead = $this->makeLead($user->organization, ['phone' => null]);
        $template = app(MessageService::class)->templates($user->organization_id)->firstWhere('channel', 'whatsapp');

        $this->get(route('crm.messages.compose', ['template' => $template->id, 'subject_type' => 'lead', 'subject_id' => $lead->id]))
            ->assertOk()->assertSee('no usable mobile number')->assertDontSee('Open in WhatsApp');
    }

    public function test_the_compose_page_rejects_an_unknown_record_type_or_a_switched_off_template(): void
    {
        $user = $this->crmUser();
        $lead = $this->makeLead($user->organization);
        $template = app(MessageService::class)->templates($user->organization_id)->first();

        $this->get(route('crm.messages.compose', ['template' => $template->id, 'subject_type' => 'user', 'subject_id' => $lead->id]))->assertSessionHasErrors('subject_type');
        $template->update(['active' => false]);
        $this->get(route('crm.messages.compose', ['template' => $template->id, 'subject_type' => 'lead', 'subject_id' => $lead->id]))->assertNotFound();
    }

    public function test_the_person_can_log_that_they_sent_it(): void
    {
        $user = $this->crmUser();
        $lead = $this->makeLead($user->organization);

        $this->post(route('crm.messages.log'), ['subject_type' => 'lead', 'subject_id' => $lead->id, 'channel' => 'whatsapp', 'summary' => 'Hello Ramesh'])
            ->assertRedirect(route('crm.leads.show', $lead));

        $entry = CrmActivity::where('kind', 'whatsapp')->firstOrFail();
        $this->assertSame('Message sent: Hello Ramesh', $entry->body);
        $this->assertSame($user->id, $entry->user_id);
        $this->post(route('crm.messages.log'), ['subject_type' => 'lead', 'subject_id' => $lead->id, 'channel' => 'sms', 'summary' => 'x'])->assertSessionHasErrors('channel');
    }

    public function test_a_company_starts_with_a_set_of_templates_in_both_channels(): void
    {
        $user = $this->crmUser();

        $templates = app(MessageService::class)->templates($user->organization_id);

        $this->assertGreaterThanOrEqual(4, $templates->count());
        $this->assertTrue($templates->contains('channel', 'whatsapp'));
        $this->assertTrue($templates->contains('channel', 'email'));
        $this->assertSame($templates->count(), app(MessageService::class)->templates($user->organization_id)->count());
        $this->assertSame(0, CrmTemplate::withoutGlobalScopes()->where('organization_id', '!=', $user->organization_id)->count());
    }

    public function test_templates_can_be_added_edited_switched_off_and_deleted(): void
    {
        $user = $this->crmUser();

        $this->post(route('crm.templates.store'), ['name' => 'Site visit', 'channel' => 'whatsapp', 'body' => 'Hi {contact}, can we visit {tender}?'])->assertSessionHasNoErrors();
        $template = CrmTemplate::where('name', 'Site visit')->firstOrFail();
        $this->assertTrue($template->active);

        $this->patch(route('crm.templates.update', $template), ['name' => 'Site visit v2', 'channel' => 'email', 'subject' => 'Visit {tender}', 'body' => 'Dear {contact}'])->assertSessionHasNoErrors();
        $template->refresh();
        $this->assertSame('Site visit v2', $template->name);
        $this->assertSame('email', $template->channel);
        $this->assertFalse($template->active);
        $this->assertSame([], app(MessageService::class)->templates($user->organization_id)->where('name', 'Site visit v2')->all());

        $this->delete(route('crm.templates.destroy', $template));
        $this->assertNull(CrmTemplate::find($template->id));
    }

    public function test_an_email_template_needs_a_subject_and_every_template_a_known_channel(): void
    {
        $this->crmUser();

        $this->post(route('crm.templates.store'), ['name' => 'Mail', 'channel' => 'email', 'body' => 'x'])->assertSessionHasErrors('subject');
        $this->post(route('crm.templates.store'), ['name' => 'Fax', 'channel' => 'fax', 'body' => 'x'])->assertSessionHasErrors('channel');
        $this->post(route('crm.templates.store'), ['name' => '', 'channel' => 'whatsapp', 'body' => ''])->assertSessionHasErrors(['name', 'body']);
    }

    public function test_the_setup_page_lists_the_words_a_template_can_use(): void
    {
        $this->crmUser();

        $this->get(route('crm.setup'))->assertOk()->assertSee('{contact}', false)->assertSee('{due_date}', false)->assertSee('Tender follow-up');
    }
}
