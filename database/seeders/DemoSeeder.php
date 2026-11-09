<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use App\Services\SampleDataService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/** Sample company for local development. Every demo user's password is "password". */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo data can only be added in a local environment.');
        }

        $organization = Organization::create([
            'name' => 'Sahyadri Infra Pvt Ltd',
            'slug' => 'sahyadri-infra-demo',
            'plan' => 'business',
            'plan_status' => 'active',
            'state' => 'Maharashtra',
            'address' => 'Kothrud, Pune 411038',
        ]);
        $organization->seedDefaults();

        $users = [];
        foreach (['owner' => 'Asha Kulkarni', 'pm' => 'Rahul Patil', 'qs' => 'Meera Joshi', 'engineer' => 'Sachin More', 'accounts' => 'Neha Deshpande'] as $role => $name) {
            $users[$role] = User::create([
                'organization_id' => $organization->id,
                'name' => $name,
                'email' => $role.'@demo.test',
                'role' => $role,
                'password' => 'password',
            ]);
        }

        // Tenant scoping and audit logs both read the signed-in user.
        Auth::setUser($users['owner']);

        app(SampleDataService::class)->addTowerProject($organization, $users, 'KT-B');
    }
}
