<?php

namespace App\Services\Crm;

use App\Models\CrmLead;
use App\Models\CrmTemplate;
use App\Models\CrmTender;
use App\Models\Organization;
use App\Models\Party;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Message templates that open in WhatsApp (wa.me) or the person's mail app (mailto:).
 * Nothing is sent from the server and no WhatsApp API is involved: the person reads the message,
 * can change it, and presses send in their own app.
 */
class MessageService
{
    /** Words a template can use, written as {name}. */
    public const PLACEHOLDERS = ['contact', 'company', 'tender', 'reference', 'due_date', 'pre_bid_date', 'value', 'my_name', 'my_company'];

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'channel' => ['required', Rule::in(CrmTemplate::CHANNELS)],
            'subject' => ['nullable', 'string', 'max:200', 'required_if:channel,email'],
            'body' => ['required', 'string', 'max:3000'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    /** @return Collection<int,CrmTemplate> */
    public function templates(int $organizationId, bool $onlyActive = true): Collection
    {
        $this->ensureDefaults($organizationId);

        return CrmTemplate::query()
            ->where('organization_id', $organizationId)
            ->when($onlyActive, fn ($query) => $query->where('active', true))
            ->orderBy('channel')
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    public function create(int $organizationId, array $data): CrmTemplate
    {
        $this->ensureDefaults($organizationId);

        return CrmTemplate::create([
            'organization_id' => $organizationId,
            ...$this->values($data),
            'sort' => (int) CrmTemplate::query()->where('organization_id', $organizationId)->max('sort') + 1,
        ]);
    }

    public function update(CrmTemplate $template, array $data): CrmTemplate
    {
        $template->update($this->values($data));

        return $template;
    }

    public function delete(CrmTemplate $template): void
    {
        $template->delete();
    }

    /** Give a company a starting set once. The person edits them freely after that. */
    public function ensureDefaults(int $organizationId): void
    {
        if (CrmTemplate::query()->where('organization_id', $organizationId)->exists()) {
            return;
        }

        DB::transaction(function () use ($organizationId) {
            // The organisation row lock stops two first visits both seeding the set.
            Organization::lockForUpdate()->find($organizationId);

            if (CrmTemplate::query()->where('organization_id', $organizationId)->exists()) {
                return;
            }

            foreach ($this->defaults() as $index => $template) {
                CrmTemplate::create(['organization_id' => $organizationId, 'sort' => $index + 1, ...$template]);
            }
        });
    }

    /**
     * What a template looks like for one record, with links ready to open.
     *
     * @return array{subject:string,body:string,phone:?string,email:?string,whatsapp_url:?string,mailto_url:?string,empty:list<string>}
     */
    public function compose(CrmTemplate $template, Model $subject, User $user): array
    {
        ['vars' => $vars, 'phone' => $phone, 'email' => $email] = $this->context($subject, $user);

        $subjectLine = $this->render((string) $template->subject, $vars);
        $body = $this->render($template->body, $vars);

        // Placeholders the template uses that this record has nothing for.
        $empty = collect(self::PLACEHOLDERS)
            ->filter(fn ($name) => str_contains($template->body.' '.$template->subject, '{'.$name.'}') && trim((string) ($vars[$name] ?? '')) === '')
            ->values()
            ->all();

        return [
            'subject' => $subjectLine,
            'body' => $body,
            'phone' => $phone,
            'email' => $email,
            'whatsapp_url' => $template->channel === 'whatsapp' ? self::whatsappUrl($phone, $body) : null,
            'mailto_url' => $template->channel === 'email' ? self::mailtoUrl($email, $subjectLine, $body) : null,
            'empty' => $empty,
        ];
    }

    /** Replace {name} with its value. A word we do not know is left as typed so the mistake is visible. */
    public function render(string $text, array $vars): string
    {
        return (string) preg_replace_callback('/\{(\w+)\}/', fn ($match) => array_key_exists($match[1], $vars) ? (string) $vars[$match[1]] : $match[0], $text);
    }

    /** https://wa.me/<number>?text=... or null when there is no usable mobile number. */
    public static function whatsappUrl(?string $phone, string $text): ?string
    {
        $number = self::normalisePhone($phone);

        return $number === null ? null : 'https://wa.me/'.$number.'?text='.rawurlencode($text);
    }

    /** mailto:<address>?subject=...&body=... or null when there is no valid address. */
    public static function mailtoUrl(?string $email, string $subject, string $body): ?string
    {
        $email = trim((string) $email);

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return 'mailto:'.rawurlencode($email).'?subject='.rawurlencode($subject).'&body='.rawurlencode($body);
    }

    /**
     * Digits for wa.me with the country code: a 10-digit Indian mobile gets 91 in front.
     * Numbers written with + or 00 keep their own country code. Anything else is rejected.
     */
    public static function normalisePhone(?string $phone): ?string
    {
        $raw = trim((string) $phone);
        $digits = (string) preg_replace('/\D+/', '', $raw);

        if ($digits === '') {
            return null;
        }

        $international = fn (string $number) => strlen($number) >= 8 && strlen($number) <= 15 ? $number : null;

        if (str_starts_with($raw, '+')) {
            return $international($digits);
        }

        if (str_starts_with($digits, '00')) {
            return $international(substr($digits, 2));
        }

        return match (true) {
            strlen($digits) === 10 && preg_match('/^[6-9]/', $digits) === 1 => '91'.$digits,
            strlen($digits) === 11 && preg_match('/^0[6-9]/', $digits) === 1 => '91'.substr($digits, 1),
            strlen($digits) === 12 && preg_match('/^91[6-9]/', $digits) === 1 => $digits,
            default => null,
        };
    }

    /**
     * The values a template can use for this record, and where the message would go.
     *
     * @return array{vars:array<string,string>,phone:?string,email:?string}
     */
    public function context(Model $subject, User $user): array
    {
        $vars = ['my_name' => $user->name, 'my_company' => (string) $user->organization?->name];

        if ($subject instanceof CrmTender) {
            $party = $subject->party;

            return [
                'vars' => [
                    ...$vars,
                    'contact' => (string) ($party?->contact_person ?: $party?->name ?: $subject->authority),
                    'company' => $subject->authority,
                    'tender' => $subject->title,
                    'reference' => (string) $subject->reference,
                    'due_date' => $subject->due_at?->format('d M Y, h:i A') ?? '',
                    'pre_bid_date' => $subject->pre_bid_at?->format('d M Y, h:i A') ?? '',
                    'value' => inr($subject->headlineValue()),
                ],
                'phone' => $party?->phone,
                'email' => $party?->email,
            ];
        }

        if ($subject instanceof CrmLead) {
            return [
                'vars' => [...$vars, 'contact' => $subject->name, 'company' => $subject->company_name ?: $subject->name, 'tender' => '', 'reference' => '', 'due_date' => '', 'pre_bid_date' => '', 'value' => $subject->expected_value > 0 ? inr($subject->expected_value) : ''],
                'phone' => $subject->phone,
                'email' => $subject->email,
            ];
        }

        /** @var Party $subject */
        return [
            'vars' => [...$vars, 'contact' => $subject->contact_person ?: $subject->name, 'company' => $subject->name, 'tender' => '', 'reference' => '', 'due_date' => '', 'pre_bid_date' => '', 'value' => ''],
            'phone' => $subject->phone,
            'email' => $subject->email,
        ];
    }

    private function values(array $data): array
    {
        return [
            'name' => $data['name'],
            'channel' => $data['channel'],
            'subject' => $data['channel'] === 'email' ? ($data['subject'] ?? null) : null,
            'body' => $data['body'],
            'active' => (bool) ($data['active'] ?? true),
        ];
    }

    /** @return list<array{name:string,channel:string,subject:?string,body:string}> */
    private function defaults(): array
    {
        return [
            [
                'name' => __('Tender follow-up'),
                'channel' => 'whatsapp',
                'subject' => null,
                'body' => __('Hello {contact}, this is {my_name} from {my_company}. We are following up on {tender}. Please let us know if you need anything from our side.'),
            ],
            [
                'name' => __('Bid submitted, thank you'),
                'channel' => 'whatsapp',
                'subject' => null,
                'body' => __('Dear {contact}, thank you for the opportunity. We have submitted our bid for {tender}. Please call me for any clarification. {my_name}, {my_company}'),
            ],
            [
                'name' => __('Pre-bid meeting reminder'),
                'channel' => 'whatsapp',
                'subject' => null,
                'body' => __('Hello {contact}, a reminder that the pre-bid meeting for {tender} is on {pre_bid_date}. {my_name}, {my_company}'),
            ],
            [
                'name' => __('EMD refund request'),
                'channel' => 'whatsapp',
                'subject' => null,
                'body' => __('Dear {contact}, please arrange the refund of our EMD for {tender}. Let me know if you need any document from us. {my_name}, {my_company}'),
            ],
            [
                'name' => __('Introduction'),
                'channel' => 'email',
                'subject' => __('Introduction from {my_company}'),
                'body' => __("Dear {contact},\n\nI am {my_name} from {my_company}. We would like to introduce our construction services to {company}.\n\nPlease let me know a convenient time to speak.\n\nRegards,\n{my_name}\n{my_company}"),
            ],
            [
                'name' => __('Tender clarification'),
                'channel' => 'email',
                'subject' => __('Query on {tender}'),
                'body' => __("Dear {contact},\n\nWith reference to {tender}, we request clarification on the following points:\n\n1. \n\nRegards,\n{my_name}\n{my_company}"),
            ],
        ];
    }
}
