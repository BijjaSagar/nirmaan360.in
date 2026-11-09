<?php

namespace App\Services\Workforce;

use App\Models\AuditLog;
use App\Models\Worker;
use App\Support\QrCode;
use Illuminate\Validation\ValidationException;

/**
 * Worker QR cards. The code holds the worker id, a card version and a signature made with the app key,
 * so nothing secret is stored and a card cannot be forged. Raising the version cancels the old card.
 * Changing APP_KEY cancels every card, and they would have to be printed again.
 */
class WorkerCardService
{
    private const PREFIX = 'N360W';

    public function code(Worker $worker): string
    {
        // A worker just created in this request has not read the column default back yet, which is 1.
        $version = (int) ($worker->qr_version ?? 1);

        return sprintf('%s-%d-%d-%s', self::PREFIX, $worker->id, $version, $this->signature($worker->id, $version));
    }

    public function svg(Worker $worker): string
    {
        return QrCode::svg($this->code($worker));
    }

    /** The worker a scanned code belongs to, within one company. Anything else is the same vague error. */
    public function resolve(string $code, int $organizationId): Worker
    {
        $invalid = ValidationException::withMessages(['code' => __('This is not a worker card from your company.')]);

        if (! preg_match('/^'.self::PREFIX.'-(\d+)-(\d+)-([a-f0-9]{20})$/', trim($code), $match)) {
            throw $invalid;
        }

        [, $id, $version, $signature] = $match;

        if (! hash_equals($this->signature((int) $id, (int) $version), $signature)) {
            throw $invalid;
        }

        $worker = Worker::withoutGlobalScopes()->where('organization_id', $organizationId)->whereNull('deleted_at')->find($id);

        if (! $worker) {
            throw $invalid;
        }

        if ((int) $version !== (int) $worker->qr_version) {
            throw ValidationException::withMessages(['code' => __('This card was replaced by a newer one. Use the new card.')]);
        }

        return $worker;
    }

    /** Make the printed card useless, for a lost card. The next card printed carries the new version. */
    public function reissue(Worker $worker): Worker
    {
        $worker->refresh()->update(['qr_version' => $worker->qr_version + 1]);
        AuditLog::record('worker.card_reissued', $worker, null, ['qr_version' => $worker->qr_version]);

        return $worker;
    }

    private function signature(int $workerId, int $version): string
    {
        return substr(hash_hmac('sha256', "worker-card|{$workerId}|{$version}", (string) config('app.key')), 0, 20);
    }
}
