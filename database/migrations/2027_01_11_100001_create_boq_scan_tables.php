<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * The lines read from a scanned or photographed BOQ, staged for a person to check. Nothing
         * here is part of the BOQ: confirming a read writes the BOQ through the Excel import, and the
         * rows are deleted with the read's files. One row is one printed table row (or one row a
         * person added by hand). The read itself is a document_reads row of kind "boq".
         */
        Schema::create('boq_scan_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // Copied from the read when the row is made, so a person limited to other projects never sees it.
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // The read lives in document_reads. No foreign key: the rows go with the read's files, by tending.
            $table->unsignedBigInteger('document_read_id');
            $table->unsignedInteger('page_no');
            // full, top or bottom: the picture the row was read from. A row added by hand takes the page's.
            $table->string('part', 6)->default('full');
            // The reader's own number for the row on its picture. 0 for a row added by hand.
            $table->unsignedSmallInteger('row_no')->default(0);
            $table->unsignedInteger('sort');
            // item, heading, subtotal, total, addon, note, or excluded (left out by a person).
            $table->string('kind', 12);
            // What the reader called it. Null for a row added by hand.
            $table->string('kind_read', 12)->nullable();
            // Who chose the kind, when a person did. A figure on a heading or note stops until someone has.
            $table->foreignId('kind_set_by')->nullable()->constrained('users')->nullOnDelete();

            // The cells as printed, exactly as the reader copied them. The amount comes from the second reading.
            $table->string('item_no_raw', 64)->default('');
            $table->text('description_raw')->nullable();
            $table->string('unit_raw', 32)->default('');
            $table->string('qty_raw', 64)->nullable();
            $table->string('rate_raw', 64)->nullable();
            $table->string('amount_raw', 64)->nullable();
            // The reader's remark on each cell (read, unclear, absent), and the script of each figure's digits.
            $table->json('states')->nullable();
            $table->json('scripts')->nullable();
            // Where on the whole page the reader says the row is, in parts per thousand of its height. Approximate.
            $table->unsignedSmallInteger('y_top')->nullable();
            $table->unsignedSmallInteger('y_bottom')->nullable();

            // The values that would be imported. Worked out by code from the cells, or typed by a person.
            $table->string('section', 120)->nullable();
            $table->string('code', 32)->nullable();
            $table->text('description')->nullable();
            $table->string('unit', 16)->nullable();
            $table->decimal('qty', 14, 3)->nullable();
            $table->decimal('rate', 15, 2)->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->decimal('percent', 7, 4)->nullable();

            // What the row held when it was read, what a person typed since, the ticks given on it and the overrides with their reasons.
            $table->json('initial')->nullable();
            $table->json('typed')->nullable();
            $table->json('acks')->nullable();
            $table->json('overrides')->nullable();

            // The record that a person checked this line before it may be imported: one by one (row), or with the clean rows of its page (page).
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('confirmed_mode', 4)->nullable();

            // A row added by hand carries the client's id, so sending it twice adds it once.
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();

            $table->index(['document_read_id', 'page_no', 'sort']);
            $table->index(['organization_id', 'document_read_id']);
            $table->unique(['organization_id', 'client_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_scan_rows');
    }
};
