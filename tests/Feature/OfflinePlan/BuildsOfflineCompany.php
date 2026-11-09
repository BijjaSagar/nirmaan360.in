<?php

namespace Tests\Feature\OfflinePlan;

use App\Models\DeviceLicence;
use App\Models\Organization;
use App\Models\User;
use App\Services\OfflinePlan\DeviceLicenceService;
use Illuminate\Testing\TestResponse;

/** Companies on the offline plan and the phones that hold their licences. Needs BuildsTenant. */
trait BuildsOfflineCompany
{
    protected const OFFLINE_API = '/api/v1/offline';

    protected function offlineTenant(string $name = 'Phone Co'): Organization
    {
        return $this->tenant($name, 'offline');
    }

    /** @return array{device_id: string, device_name: string, platform: string, app_version: string} */
    protected function phone(string $id = 'phone-0001', string $name = 'Ravi Pixel'): array
    {
        return ['device_id' => $id, 'device_name' => $name, 'platform' => 'android', 'app_version' => '1.4.0'];
    }

    protected function activatePhone(string $id = 'phone-0001', string $name = 'Ravi Pixel'): TestResponse
    {
        return $this->postJson(self::OFFLINE_API.'/devices/activate', $this->phone($id, $name));
    }

    protected function refreshPhone(string $id = 'phone-0001'): TestResponse
    {
        return $this->postJson(self::OFFLINE_API.'/devices/refresh', ['device_id' => $id]);
    }

    /** A licence issued straight through the service, for tests that are not about the API. */
    protected function licenceFor(User $user, string $id = 'phone-0001', string $name = 'Ravi Pixel'): DeviceLicence
    {
        return app(DeviceLicenceService::class)->activate($user, $this->phone($id, $name))['licence'];
    }
}
