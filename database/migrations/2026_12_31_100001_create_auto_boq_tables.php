<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The building dimensions a BOQ is worked out from, one row per project, so they can be
        // edited and run again. Element groups (footings, columns, beams, slabs, walls) are kept
        // as JSON lists of rows; every figure in them is a decimal string, never a float.
        Schema::create('auto_boq_inputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('floors')->default(1);
            $table->decimal('floor_height', 14, 3)->default(0);
            $table->decimal('plinth_area', 14, 3)->default(0);
            $table->decimal('plinth_height', 14, 3)->default(0);
            $table->decimal('foundation_depth', 14, 3)->default(0);
            $table->decimal('stair_area', 14, 3)->default(0);
            $table->decimal('chajja_length', 14, 3)->default(0);
            $table->decimal('chajja_width', 14, 3)->default(0);
            $table->decimal('chajja_thickness', 14, 3)->default(0);
            $table->json('footings')->nullable();
            $table->json('columns')->nullable();
            $table->json('beams')->nullable();
            $table->json('slabs')->nullable();
            $table->json('walls')->nullable();
            // Scalar field => takeoff layer the figure was filled from. No foreign key: the drawings module may be absent.
            $table->json('layers')->nullable();
            // Line codes left out at the last apply, so the next preview starts the same way.
            $table->json('excluded')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->unique('project_id');
        });

        // The company's own value for a typical figure, such as steel per cubic metre of slab.
        // A key with no row uses the default in App\Services\AutoBoq\Assumptions.
        Schema::create('auto_boq_assumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->decimal('value', 14, 3);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_boq_assumptions');
        Schema::dropIfExists('auto_boq_inputs');
    }
};
