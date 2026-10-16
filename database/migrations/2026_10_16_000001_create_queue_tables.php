<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mga ahensya/serbisyo sa kiosk (4 lang ngayon)
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();      // DOH, DSWD, PHIC, PAGCOR
            $table->string('name');                     // "MSS / DOH-MAIP"
            $table->string('spoken_name');              // kung paano babanggitin ng TTS
            $table->string('logo_path')->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Bawat PC station / bintana. Pwedeng maraming counter ang isang service.
        Schema::create('counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('name');                     // "DSWD Window 1"
            $table->string('spoken_name');              // "Window 1"
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('staff')->after('email'); // admin | staff
            $table->foreignId('counter_id')->nullable()->after('role')->constrained()->nullOnDelete();
        });

        // Counter ng numero kada service + lane + araw. Ni-lock ito (FOR UPDATE)
        // para walang dobleng numero kahit sabay pumindot sa kiosk.
        Schema::create('ticket_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('lane', 10);
            $table->date('queue_date');
            $table->unsignedInteger('last_number')->default(0);
            $table->unique(['service_id', 'lane', 'queue_date']);
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained();
            $table->string('lane', 10);                 // fast | priority | regular
            $table->unsignedInteger('number');          // 1, 2, 3...
            $table->string('code', 10);                 // F001, P001, R001
            $table->date('queue_date');
            $table->string('status', 12)->default('waiting');
            // waiting -> called -> serving -> done | no_show | cancelled
            $table->foreignId('counter_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('served_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('recall_count')->default(0);
            $table->timestamp('called_at')->nullable();
            $table->timestamp('serving_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['service_id', 'queue_date', 'code']);
            // Pang-"Call Next": hanapin ang pinakamatandang waiting kada lane
            $table->index(['service_id', 'queue_date', 'status', 'lane', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('ticket_sequences');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('counter_id');
            $table->dropColumn('role');
        });
        Schema::dropIfExists('counters');
        Schema::dropIfExists('services');
    }
};
