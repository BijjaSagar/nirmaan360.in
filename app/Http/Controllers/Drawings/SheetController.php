<?php

namespace App\Http\Controllers\Drawings;

use App\Http\Controllers\Controller;
use App\Models\DrawingSheet;
use App\Models\DrawingVersion;
use App\Models\Issue;
use App\Models\TakeoffLayer;
use App\Models\User;
use App\Services\Drawings\DrawingRegisterService;
use App\Services\Drawings\IssuePinService;
use App\Services\Drawings\TakeoffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SheetController extends Controller
{
    public function __construct(
        private DrawingRegisterService $register,
        private TakeoffService $takeoff,
        private IssuePinService $pins,
    ) {}

    public function show(Request $request, DrawingSheet $sheet): View
    {
        $version = $sheet->version;
        $drawing = $version->drawing;
        abort_if($drawing === null, 404);

        $project = $sheet->project;
        $user = $request->user();

        return view('modules.drawings.viewer', [
            'sheet' => $sheet,
            'version' => $version,
            'drawing' => $drawing,
            'project' => $project,
            'siblings' => $version->sheets()->get(['id', 'page_no', 'sheet_number']),
            'viewer' => [
                'sheet' => [
                    'id' => $sheet->id,
                    'width' => $sheet->width,
                    'height' => $sheet->height,
                    'page' => $sheet->page_no,
                    'metresPerUnit' => $sheet->metres_per_unit,
                    'calibLength' => $sheet->calib_length_m,
                    'calibPoints' => $sheet->calib_points,
                ],
                'file' => ['url' => route('drawings.register.file', $version), 'kind' => $version->isPdf() ? 'pdf' : 'image'],
                'can' => ['takeoff' => $user->can('drawings.takeoff'), 'pin' => $user->can('issue.manage')],
                'layers' => TakeoffLayer::where('project_id', $project->id)->orderBy('name')->get(['id', 'name', 'colour']),
                'layerColours' => config('modules.drawings.layer_colours'),
                'measurements' => $sheet->measurements()->get()->map($this->takeoff->present(...))->values(),
                'boqItems' => $project->boqItems()->orderBy('sort')->orderBy('id')->get(['id', 'code', 'description', 'unit'])
                    ->map(fn ($item) => [
                        'id' => $item->id,
                        'code' => $item->code,
                        'description' => $item->description,
                        'unit' => $item->unit,
                        'dimension' => TakeoffService::dimensionOfUnit($item->unit),
                    ])->values(),
                'pins' => $sheet->pins()->with('issue.assignee')->get()->filter(fn ($pin) => $pin->issue !== null)->map($this->pins->present(...))->values(),
                'openIssues' => Issue::where('project_id', $project->id)->whereIn('status', ['open', 'in_progress'])->latest('id')->limit(200)->get(['id', 'title']),
                'users' => User::where('organization_id', $user->organization_id)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
                'priorities' => collect(config('buildos.issue_priorities'))->map(fn (string $priority) => ['value' => $priority, 'label' => __(ucfirst($priority))])->values(),
                'urls' => [
                    'calibrate' => route('drawings.register.sheets.calibrate', $sheet),
                    'measurements' => route('drawings.takeoff.measurements.store', $sheet),
                    'measurement' => route('drawings.takeoff.measurements.update', '__ID__'),
                    'layers' => route('drawings.takeoff.layers.store', $project),
                    'pins' => route('drawings.register.pins.store', $sheet),
                    'pin' => route('drawings.register.pins.destroy', '__ID__'),
                    'issues' => route('issues.index', $project),
                ],
                'strings' => $this->strings(),
            ],
        ]);
    }

    public function register(Request $request, DrawingVersion $version): JsonResponse
    {
        abort_if($version->drawing === null, 404);

        if (! $version->isViewable()) {
            throw ValidationException::withMessages(['pages' => __('Only PDF and image files have sheets.')]);
        }

        $data = $request->validate($this->register->sheetPageRules());

        return response()->json(['created' => $this->register->registerSheets($version, $data['pages'])]);
    }

    public function update(Request $request, DrawingSheet $sheet): RedirectResponse
    {
        $this->register->updateSheet($sheet, $request->validate([
            'sheet_number' => ['nullable', 'string', 'max:64'],
            'title' => ['nullable', 'string', 'max:200'],
        ]));

        return back()->with('success', __('Sheet updated.'));
    }

    public function calibrate(Request $request, DrawingSheet $sheet): JsonResponse
    {
        $data = $request->validate($this->takeoff->calibrationRules());
        $sheet = $this->takeoff->calibrate($sheet, $data['points'], $data['length_m'], $request->user());

        return response()->json([
            'metresPerUnit' => $sheet->metres_per_unit,
            'calibLength' => $sheet->calib_length_m,
            'calibPoints' => $sheet->calib_points,
            'measurements' => $sheet->measurements()->get()->map($this->takeoff->present(...))->values(),
        ]);
    }

    /** Words the canvas script shows, translated here so the script needs no language files. */
    private function strings(): array
    {
        return [
            'loadFailed' => __('This file could not be opened.'),
            'pickTwo' => __('Click two points a known distance apart.'),
            'scaleSaved' => __('Scale saved. Existing quantities were worked out again.'),
            'setScaleFirst' => __('Set the scale of this sheet before measuring lengths, areas or volumes.'),
            'needsLayer' => __('Choose or add a layer first.'),
            'saved' => __('Saved.'),
            'removed' => __('Removed.'),
            'confirmRemove' => __('Remove this measurement?'),
            'confirmUnpin' => __('Remove this pin? The issue stays open.'),
            'preview' => __('Preview'),
            'previewNote' => __('The saved quantity is worked out on the server.'),
            'noLink' => __('Not linked'),
            'noMeasurements' => __('Nothing measured on this sheet yet.'),
            'noPins' => __('No issues pinned on this sheet.'),
            'count' => __('Count'),
            'length' => __('Length'),
            'area' => __('Area'),
            'volume' => __('Volume'),
            'calibrated' => __('Scale set: :length m between the reference points.'),
            'notCalibrated' => __('No scale yet. Use Set scale to measure lengths and areas.'),
            'pinHint' => __('Click the place on the drawing where the issue is.'),
            'clickHint' => __('Click to add points. Double-click or press Finish when done.'),
            'failed' => __('That did not work. Please try again.'),
            'open' => __('Open'),
            'in_progress' => __('In progress'),
            'resolved' => __('Resolved'),
            'closed' => __('Closed'),
        ];
    }
}
