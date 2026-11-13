<?php

namespace App\Services\Reports;

use App\Models\Approval;
use App\Models\ApprovalStep;
use App\Models\BoqItem;
use App\Models\Concerns\OrganizationScope;
use App\Models\Dpr;
use App\Models\DprMachinery;
use App\Models\DprManpower;
use App\Models\DprPhoto;
use App\Models\DprWorkItem;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Support\Dec;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * The DPR as a PDF, and the WhatsApp share of it.
 *
 * There is no WhatsApp API here. Sharing opens a wa.me link carrying a short text and a signed
 * link to the PDF. Whoever holds the link can open that one PDF until it expires.
 */
class DprReportService
{
    public const LINK_DAYS = 7;

    /** Photos put into the PDF. Each is shrunk first, so the file stays small enough to send. */
    public const MAX_PHOTOS = 8;

    private const PHOTO_EDGE = 640;

    private const MAX_PIXELS = 40_000_000;

    public function pdf(Dpr $dpr): string
    {
        return PdfRenderer::render('modules.reports.pdf.dpr', $this->data($dpr));
    }

    public function filename(Dpr $dpr): string
    {
        return 'DPR-'.$dpr->report_date->format('Y-m-d').'-'.$dpr->id.'.pdf';
    }

    /**
     * Everything the PDF shows. It reads each table with the organisation named outright, so it gives the
     * same answer whether or not someone is signed in, or is signed in to another company.
     *
     * @return array<string,mixed>
     */
    public function data(Dpr $dpr): array
    {
        $org = $dpr->organization_id;
        $owned = fn (string $model) => $model::withoutGlobalScope(OrganizationScope::class)->where('organization_id', $org);

        $workItems = $owned(DprWorkItem::class)->where('dpr_id', $dpr->id)->orderBy('id')->get();
        $boqItems = $owned(BoqItem::class)->withTrashed()->whereIn('id', $workItems->pluck('boq_item_id')->filter())->get()->keyBy('id');
        $photos = $owned(DprPhoto::class)->where('dpr_id', $dpr->id)->orderBy('id')->get();

        $approval = $owned(Approval::class)->where('approvable_type', $dpr->getMorphClass())->where('approvable_id', $dpr->id)->latest('id')->first();
        $steps = $approval ? $owned(ApprovalStep::class)->where('approval_id', $approval->id)->orderBy('id')->get() : collect();
        $users = User::whereIn('id', $steps->pluck('user_id')->push($dpr->created_by)->filter()->unique())->get()->keyBy('id');

        return [
            'dpr' => $dpr,
            'organization' => Organization::findOrFail($org),
            'project' => $owned(Project::class)->withTrashed()->find($dpr->project_id),
            'site' => $dpr->site_id ? $owned(Site::class)->withTrashed()->find($dpr->site_id) : null,
            'author' => $users->get($dpr->created_by)?->name,
            'manpower' => $owned(DprManpower::class)->where('dpr_id', $dpr->id)->orderBy('id')->get(),
            'machinery' => $owned(DprMachinery::class)->where('dpr_id', $dpr->id)->orderBy('id')->get(),
            'work' => $workItems->map(fn (DprWorkItem $row) => [
                'code' => $boqItems->get($row->boq_item_id)?->code,
                'description' => $row->description ?: $boqItems->get($row->boq_item_id)?->description,
                'qty' => Dec::qty($row->qty),
                'unit' => $boqItems->get($row->boq_item_id)?->unit,
            ]),
            'photos' => $photos->take(self::MAX_PHOTOS)->map(fn (DprPhoto $photo) => [
                'src' => $this->thumbnail($photo),
                'caption' => $photo->caption,
                'taken_at' => $photo->taken_at,
            ])->filter(fn (array $photo) => $photo['src'] !== null)->values(),
            'morePhotos' => max(0, $photos->count() - self::MAX_PHOTOS),
            'steps' => $steps->map(fn (ApprovalStep $step) => [
                'role' => config('buildos.approval_level_roles.'.$step->role, (string) $step->role),
                'actor' => $users->get($step->user_id)?->name ?? $step->actor_name ?? '—',
                'action' => $step->action,
                'at' => $step->acted_at,
                'remark' => $step->remark,
            ]),
        ];
    }

    /** A temporary link to the PDF that works without signing in. */
    public function signedUrl(Dpr $dpr): string
    {
        return URL::temporarySignedRoute('dprs.shared', now()->addDays(self::LINK_DAYS), ['id' => $dpr->id]);
    }

    /**
     * The link, its message and the wa.me address, made once so all three carry the same signature.
     * The field app's "Share on WhatsApp" uses this; it opens no more than the web's button does.
     *
     * @return array{url: string, text: string, whatsapp_url: string, expires_at: Carbon}
     */
    public function share(Dpr $dpr): array
    {
        $expires = now()->addDays(self::LINK_DAYS);
        $url = URL::temporarySignedRoute('dprs.shared', $expires, ['id' => $dpr->id]);
        $text = $this->whatsappText($dpr, $url);

        return ['url' => $url, 'text' => $text, 'whatsapp_url' => 'https://wa.me/?text='.rawurlencode($text), 'expires_at' => $expires];
    }

    /** The message that goes into WhatsApp, in the signed-in user's language. $link is a signed link already made for it. */
    public function whatsappText(Dpr $dpr, ?string $link = null): string
    {
        $dpr->loadMissing('project', 'site', 'manpower', 'workItems.boqItem');

        $lines = [
            $dpr->organization?->name.' · '.__('Daily progress report'),
            $dpr->project->name.($dpr->site ? ' · '.$dpr->site->name : ''),
            $dpr->report_date->format('d M Y').' · '.__(Str::headline(strtolower($dpr->status))),
            __('Weather').': '.($dpr->weather ?: '—').' · '.__('Manpower').': '.$dpr->manpower->sum('count'),
        ];

        if ($dpr->workItems->isNotEmpty()) {
            $lines[] = __('Work done').':';

            foreach ($dpr->workItems->take(5) as $row) {
                $lines[] = '- '.trim(($row->boqItem?->code ? $row->boqItem->code.' ' : '').Str::limit($row->description ?: (string) $row->boqItem?->description, 60)).' · '.fmt_qty($row->qty).' '.$row->boqItem?->unit;
            }

            if ($dpr->workItems->count() > 5) {
                $lines[] = '- '.__('and :count more', ['count' => $dpr->workItems->count() - 5]);
            }
        }

        $lines[] = '';
        $lines[] = __('Full report (PDF, link works for :days days):', ['days' => self::LINK_DAYS]);
        $lines[] = $link ?? $this->signedUrl($dpr);

        return implode("\n", $lines);
    }

    public function whatsappUrl(Dpr $dpr): string
    {
        return 'https://wa.me/?text='.rawurlencode($this->whatsappText($dpr));
    }

    /** A photo shrunk to a small JPEG and inlined, or null when it cannot be read. */
    private function thumbnail(DprPhoto $photo): ?string
    {
        try {
            if (! Storage::exists($photo->path)) {
                return null;
            }

            $bytes = Storage::get($photo->path);
            $size = @getimagesizefromstring($bytes);

            // A very large picture would use more memory to open than the report is worth.
            if (! $size || $size[0] * $size[1] > self::MAX_PIXELS) {
                return null;
            }

            $source = @imagecreatefromstring($bytes);

            if (! $source) {
                return null;
            }

            [$width, $height] = [imagesx($source), imagesy($source)];
            $scale = min(1, self::PHOTO_EDGE / max($width, $height));
            $target = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
            imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));
            imagecopyresampled($target, $source, 0, 0, 0, 0, imagesx($target), imagesy($target), $width, $height);

            ob_start();
            imagejpeg($target, null, 72);
            $jpeg = (string) ob_get_clean();

            return 'data:image/jpeg;base64,'.base64_encode($jpeg);
        } catch (Throwable) {
            return null;
        }
    }
}
