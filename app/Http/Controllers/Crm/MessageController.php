<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmTemplate;
use App\Services\Crm\ActivityService;
use App\Services\Crm\MessageService;
use App\Services\Crm\Subjects;
use App\Services\Crm\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MessageController extends Controller
{
    public function __construct(private MessageService $service) {}

    /** Shows the message filled in for one record, with a button that opens WhatsApp or the mail app. */
    public function compose(Request $request): View
    {
        $user = $request->user();
        $data = $request->validate([
            'template' => ['required', 'integer'],
            'subject_type' => ['required', Rule::in(Subjects::types())],
            'subject_id' => ['required', 'integer'],
        ]);

        $template = CrmTemplate::query()->where('organization_id', $user->organization_id)->where('active', true)->findOrFail($data['template']);
        $subject = Subjects::find($data['subject_type'], $data['subject_id'], $user->organization_id);

        abort_unless($subject, 404);

        return view('modules.crm.messages.compose', [
            'template' => $template,
            'subject' => $subject,
            'subjectType' => $data['subject_type'],
            'subjectTitle' => Subjects::title($subject),
            'message' => $this->service->compose($template, $subject, $user),
            'backUrl' => Subjects::url($data['subject_type'], (int) $data['subject_id']),
        ]);
    }

    /** The person says they sent it, so the timeline shows it. We cannot know that ourselves. */
    public function log(Request $request, ActivityService $activities): RedirectResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', Rule::in(Subjects::types())],
            'subject_id' => ['required', 'integer'],
            'channel' => ['required', Rule::in(array_keys(Vocab::channels()))],
            'summary' => ['required', 'string', 'max:300'],
        ]);

        $activities->logFor($request->user(), $data['subject_type'], (int) $data['subject_id'], $data['channel'], __('Message sent: :text', ['text' => $data['summary']]));

        return redirect()->to(Subjects::url($data['subject_type'], (int) $data['subject_id']))->with('success', __('Logged in the timeline.'));
    }
}
