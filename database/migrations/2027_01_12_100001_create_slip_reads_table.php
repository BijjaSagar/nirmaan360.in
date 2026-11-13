<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One photo of a delivery challan, a weighbridge slip or a supplier bill, read to fill a form
         * before the form is saved. The page picture and what the reader returned live with the
         * document read; this row holds what the code made of it for the form, and, once the form is
         * saved, which record it went into and which fields a person confirmed or typed over.
         */
        Schema::create('slip_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('document_read_id')->index();
            // challan (a delivery challan or weighbridge slip) or bill (a supplier bill).
            $table->string('kind', 12);
            // reading, ready, used, discarded or failed.
            $table->string('status', 12)->default('reading');
            // What the reader said the paper is: delivery_challan, weighbridge_slip, tax_invoice, other or unreadable.
            $table->string('doc_kind', 24)->nullable();
            // What the code made of each printed value: the text as printed, the value offered to the form, warnings.
            $table->json('fields')->nullable();
            $table->json('lines')->nullable();
            $table->json('warnings')->nullable();
            // A supplier found by its GSTIN and materials whose names share words with a line. Ids and names only.
            $table->json('suggestions')->nullable();
            $table->char('image_sha256', 64)->nullable();
            $table->unsignedBigInteger('delivery_challan_id')->nullable();
            $table->unsignedBigInteger('vendor_bill_id')->nullable();
            $table->uuid('used_client_uuid')->nullable();
            // Field names only, never a value: which were confirmed against the photo, which were typed over, by whom and when.
            $table->json('confirmed')->nullable();
            // Set when a person asked for the stronger model to read the photo again.
            $table->timestamp('rechecked_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'image_sha256']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slip_reads');
    }
};
