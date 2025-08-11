<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('backup_logs', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['database', 'files', 'uploads'])->index();
            $table->enum('frequency', ['manual', 'daily', 'weekly', 'monthly'])->default('manual');
            $table->enum('status', ['started', 'success', 'failed', 'warning'])->index();
            $table->string('filename')->nullable();
            $table->string('location')->nullable();
            $table->bigInteger('size_bytes')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->json('metadata')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            $table->index(['type', 'status', 'started_at']);
            $table->index('started_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backup_logs');
    }
};
