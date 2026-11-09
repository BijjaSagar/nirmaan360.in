<?php

namespace Tests\Feature\Access;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A model with neither a project_id nor a route to one is invisible to project
 * limits, so someone limited to one project could read it by id. Every tenant
 * model must therefore be covered, or be named here as company-wide on purpose.
 */
class ProjectScopeCoverageTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_WIDE = [
        'ApprovalRule', 'AuditLog', 'BillingPayment', 'CustomRole', 'Party', 'Project', 'TeamInvitation', 'Worker',
    ];

    public function test_every_tenant_model_is_covered_by_project_limits_or_declared_company_wide(): void
    {
        $uncovered = [];

        foreach (File::allFiles(app_path('Models')) as $file) {
            $class = 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (! class_exists($class) || ! in_array(BelongsToOrganization::class, class_uses_recursive($class), true)) {
                continue;
            }

            $covered = defined($class.'::PROJECT_VIA')
                || defined($class.'::PROJECT_VIA_MORPH')
                || defined($class.'::COMPANY_WIDE')
                || in_array(class_basename($class), self::COMPANY_WIDE, true)
                || Schema::hasColumn((new $class)->getTable(), 'project_id');

            if (! $covered) {
                $uncovered[] = $class;
            }
        }

        $this->assertSame([], $uncovered, 'Add project_id, PROJECT_VIA or COMPANY_WIDE to these models.');
    }
}
