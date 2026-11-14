<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a company's BOQ files are laid out, remembered from the column check so the next file in the
 * same layout maps itself. Found again by the signature of its heading row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boq_import_layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // sha1 of the heading cells as compared, column by column.
            $table->string('signature', 40);
            // The heading as written, column => text, to show which layout this is.
            $table->json('headings');
            // Column => what it holds, null for a column left out.
            $table->json('fields');
            $table->unsignedTinyInteger('span')->default(1);
            $table->string('sheet_name', 60)->nullable();
            $table->string('unit', 16)->nullable();
            // The file it was first saved from, to recognise it by.
            $table->string('file_name', 160)->nullable();
            $table->unsignedInteger('uses')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'signature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_import_layouts');
    }
};
