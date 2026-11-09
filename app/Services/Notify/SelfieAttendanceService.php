<?php

namespace App\Services\Notify;

use App\Models\AttendanceSelfie;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Models\Worker;
use App\Services\AttendanceService;
use App\Support\ClientTime;
use App\Support\Idempotent;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Marks a worker present from a photo taken inside the site's geofence. */
class SelfieAttendanceService
{
    public const CONSENT_VERSION = 'selfie-v1';

    private const EARTH_RADIUS_M = 6371000;

    public function __construct(private AttendanceService $attendance) {}

    /** The note shown before the photo. The text shown is stored with each selfie as proof of consent. */
    public function consentNote(): string
    {
        return __('The worker in this photo agrees to it being taken, with this location, only to record attendance at this site. The photo is kept private and only this company\'s site managers can see it. The worker can ask for it to be deleted at any time.');
    }

    public function rules(Project $project): array
    {
        return [
            'site_id' => ['bail', 'required', 'integer', Rule::exists('sites', 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
            'worker_id' => $this->attendance->workerRule(),
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'selfie' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'consent' => ['accepted'],
            // A phone can be offline for up to a week before it syncs.
            'captured_at' => ['nullable', 'date', 'before_or_equal:+10 minutes', 'after_or_equal:-7 days'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /** A repeated client_uuid returns the original row and stores nothing new. */
    public function record(Project $project, array $data, UploadedFile $photo, User $user): AttendanceSelfie
    {
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->findByUuid($project->organization_id, $uuid))) {
            return $existing;
        }

        $site = Site::where('project_id', $project->id)->findOrFail($data['site_id']);
        $worker = Worker::findOrFail($data['worker_id']);
        $distance = $this->assertInsideGeofence($site, $worker, $data);

        $capturedAt = ClientTime::parse($data['captured_at'] ?? null) ?? now()->startOfSecond();
        $date = $capturedAt->toDateString();

        if ($this->findForDay($project, $worker->id, $date)) {
            throw ValidationException::withMessages(['worker_id' => __('This worker already has a selfie for that day. Delete it first to take another.')]);
        }

        $path = $photo->store('org-'.$project->organization_id.'/attendance-selfies/'.$project->id, ['visibility' => 'private']);

        try {
            $selfie = DB::transaction(fn () => $this->save($project, $site, $worker, $data, $path, $distance, $capturedAt, $date, $user));
        } catch (Throwable $exception) {
            Storage::delete($path);

            throw $exception;
        }

        // Another request won the race and its row came back, so this copy of the photo is not needed.
        if ($selfie->photo_path !== $path) {
            Storage::delete($path);
        }

        return $selfie;
    }

    /** Withdraws consent: the photo and its record go, and the attendance mark stays. */
    public function delete(AttendanceSelfie $selfie): void
    {
        Storage::delete($selfie->photo_path);
        $selfie->delete();
    }

    public function distanceMeters(float $fromLat, float $fromLng, float $toLat, float $toLng): int
    {
        $latDelta = deg2rad($toLat - $fromLat);
        $lngDelta = deg2rad($toLng - $fromLng);

        $a = sin($latDelta / 2) ** 2 + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($lngDelta / 2) ** 2;

        return (int) round(self::EARTH_RADIUS_M * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    private function assertInsideGeofence(Site $site, Worker $worker, array $data): int
    {
        if ($site->lat === null || $site->lng === null) {
            throw ValidationException::withMessages(['site_id' => __('This site has no map location yet, so a selfie cannot be checked against it.')]);
        }

        $radius = (int) $site->geofence_radius_m;

        // A reading vaguer than the fence cannot show anyone was inside it.
        if (($data['accuracy_m'] ?? 0) > $radius) {
            throw ValidationException::withMessages(['lat' => __('The location is too rough to check (:accuracy m). Move to open sky and try again.', ['accuracy' => (int) round((float) $data['accuracy_m'])])]);
        }

        $distance = $this->distanceMeters((float) $data['lat'], (float) $data['lng'], (float) $site->lat, (float) $site->lng);

        if ($distance > $radius) {
            AuditLog::record('attendance.selfie_outside_geofence', $worker, null, ['site_id' => $site->id, 'distance_m' => $distance, 'radius_m' => $radius]);

            throw ValidationException::withMessages(['lat' => __('This location is :distance m from :site. Attendance needs to be within :radius m.', ['distance' => $distance, 'site' => $site->name, 'radius' => $radius])]);
        }

        return $distance;
    }

    private function save(Project $project, Site $site, Worker $worker, array $data, string $path, int $distance, CarbonInterface $capturedAt, string $date, User $user): AttendanceSelfie
    {
        $existing = $this->attendance->findForDay($project, $worker->id, $date);

        // A half day or overtime already marked stays as it is. The selfie only proves presence.
        $attendance = $this->attendance->save($project, $date, [
            'worker_id' => $worker->id,
            'status' => $existing && $existing->status !== 'absent' ? $existing->status : 'present',
            'ot_hours' => $existing?->ot_hours ?? 0,
            'method' => 'selfie',
            'client_uuid' => $data['client_uuid'] ?? null,
        ], $user, $existing);

        return Idempotent::create(
            fn () => AttendanceSelfie::create([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'site_id' => $site->id,
                'worker_id' => $worker->id,
                'attendance_id' => $attendance->id,
                'attendance_date' => $date,
                'photo_path' => $path,
                'lat' => $data['lat'],
                'lng' => $data['lng'],
                'accuracy_m' => $data['accuracy_m'] ?? null,
                'distance_m' => $distance,
                'radius_m' => $site->geofence_radius_m,
                'captured_at' => $capturedAt,
                'captured_by' => $user->id,
                'consent_version' => self::CONSENT_VERSION,
                'consent_text' => $this->consentNote(),
                'consent_at' => now(),
                'client_uuid' => $data['client_uuid'] ?? null,
            ]),
            fn () => $this->findByUuid($project->organization_id, (string) ($data['client_uuid'] ?? '')) ?? $this->findForDay($project, $worker->id, $date),
        );
    }

    private function findByUuid(int $organizationId, string $uuid): ?AttendanceSelfie
    {
        return AttendanceSelfie::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first();
    }

    private function findForDay(Project $project, int $workerId, string $date): ?AttendanceSelfie
    {
        return AttendanceSelfie::where('project_id', $project->id)->where('worker_id', $workerId)->whereDate('attendance_date', $date)->first();
    }
}
