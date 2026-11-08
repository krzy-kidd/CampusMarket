<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('verification_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->string('cor_file_path', 1024);
            $table->enum('submission_status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamp('submitted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();

            $table->unique(['verification_id', 'attempt_number']);
            $table->index(['submission_status', 'submitted_at']);
            $table->index(['user_id', 'academic_year_id', 'attempt_number'], 'verification_attempts_user_year_attempt_idx');
        });

        Schema::table('verifications', function (Blueprint $table) {
            $table->foreignId('current_attempt_id')
                ->nullable()
                ->unique()
                ->constrained('verification_attempts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('verifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_attempt_id');
        });

        Schema::dropIfExists('verification_attempts');
    }
};
