<?php

use App\Models\AiDocumentSettings;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\ReaderTrial;
use App\Models\ReaderTrialPage;
use App\Services\DocumentReading\AiDocumentConsent;
use App\Services\DocumentReading\ReadFiles;
use App\Services\DocumentReading\ReadKinds;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * The AI readers as a company that uses them has them: a key saved, the readers released, the
 * company's consent on, and one read with a stored page for every reader that is deployed. So the
 * walks see each reader screen in its working state, not only its "not available" state.
 *
 * A read holds credits, never rupees, so nothing here carries a money figure.
 */
return function (): void {
    // No walk may reach the provider. A page that tried would get this refusal instead of a network call.
    Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'api_error', 'message' => 'No call is made from a test.']], 500)]);

    app(PlatformSettings::class)->set(['anthropic_api_key' => 'test-key-value', 'ai_readers_released' => '1', 'ai_tier_monthly_cap_usd' => '1000']);

    AiDocumentSettings::for($this->organization)->update([
        'enabled' => true,
        'consent_version' => AiDocumentConsent::VERSION,
        'consent_locale' => 'en',
        'consented_by' => $this->users['owner']->id,
        'consented_at' => now(),
    ]);

    // With no reader deployed there is still one read, so the shared routes have a record to be asked for.
    $kinds = array_keys(app(ReadKinds::class)->all()) ?: ['boq'];
    $picture = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAAA//EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AN//Z');

    foreach ($kinds as $kind) {
        $pass = config("modules.document_reading.kinds.{$kind}.picture_pass") ?? 'main';

        $read = DocumentRead::create([
            'organization_id' => $this->organization->id,
            // A tender document belongs to the company, not to a project.
            'project_id' => $kind === 'tender' ? null : $this->project->id,
            'kind' => $kind,
            'status' => DocumentRead::DRAFT,
            'user_id' => $this->users['owner']->id,
            'pages_total' => 1,
            'consent_version' => AiDocumentConsent::VERSION,
        ]);

        $path = ReadFiles::page($read, 1, $pass);
        Storage::put($path, $picture);

        $page = DocumentReadPage::create([
            'document_read_id' => $read->id,
            'page_no' => 1,
            'pass' => $pass,
            'model' => config("modules.document_reading.kinds.{$kind}.passes.{$pass}.model") ?? 'claude-sonnet-5-5',
            'image_path' => $path,
            'image_sha256' => hash('sha256', $picture),
            'image_width' => 1622,
            'image_height' => 2296,
            'image_bytes' => strlen($picture),
        ]);

        // A feature's own file may have filed its read first; the shared routes work with either.
        $this->rows[DocumentRead::class] ??= $read;
        $this->rows[DocumentReadPage::class] ??= $page;
    }

    $this->skippedRoutes['document-reads.pages.image'] = 'serves a stored page picture';
    $this->skippedRoutes['api.document-reads.pages.image'] = 'serves a stored page picture';

    // The platform admin's dry run belongs to no company. Every role of the company gets a 404 on its screens.
    $trial = ReaderTrial::create([
        'uuid' => (string) Str::uuid(),
        'kind' => $kinds[0],
        'pass' => 'main',
        'model' => 'claude-sonnet-5-5',
        'effort' => 'low',
        'max_tokens' => 2000,
        'timeout' => 60,
        'pages_total' => 1,
        'allowed_at' => now(),
        'purge_after' => now()->addDay(),
    ]);

    $this->rows[ReaderTrial::class] ??= $trial;
    $this->rows[ReaderTrialPage::class] ??= ReaderTrialPage::create(['reader_trial_id' => $trial->id, 'page_no' => 1, 'pass' => 'main', 'model' => 'claude-sonnet-5-5']);
    $this->skippedRoutes['ai-reader.admin.trials.pages.image'] = 'serves a stored page picture';
    $this->skippedRoutes['ai-reader.admin.trials.scores'] = 'serves a spreadsheet file';
    $this->skippedRoutes['ai-reader.admin.fixtures'] = 'serves a test PDF';
};
