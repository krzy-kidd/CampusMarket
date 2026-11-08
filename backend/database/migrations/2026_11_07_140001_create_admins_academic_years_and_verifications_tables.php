<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->enum('role', ['verification_admin', 'super_admin']);
            $table->enum('account_status', ['active', 'deactivated'])->default('active');
            $table->unsignedTinyInteger('active_super_admin_guard')
                ->nullable()
                ->storedAs("CASE WHEN role = 'super_admin' AND account_status = 'active' THEN 1 ELSE NULL END")
                ->unique();
            $table->timestamps();
        });

        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->string('academic_year', 20)->unique();
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_current')->default(false);
            $table->unsignedTinyInteger('current_year_guard')
                ->nullable()
                ->storedAs('CASE WHEN is_current = 1 THEN 1 ELSE NULL END')
                ->unique();
            $table->timestamps();
        });

        Schema::create('verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->string('cor_file_path', 1024);
            $table->enum('submission_status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamp('submitted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'academic_year_id']);
            $table->index(['submission_status', 'submitted_at']);
        });

        $this->createAcademicYearOverlapTriggers();
    }

    public function down(): void
    {
        $this->dropAcademicYearOverlapTriggers();

        Schema::dropIfExists('verifications');
        Schema::dropIfExists('academic_years');
        Schema::dropIfExists('admins');
    }

    private function createAcademicYearOverlapTriggers(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER academic_years_no_overlap_insert
                BEFORE INSERT ON academic_years
                FOR EACH ROW
                BEGIN
                    IF NEW.start_date > NEW.end_date OR EXISTS (
                        SELECT 1 FROM academic_years
                        WHERE NEW.start_date <= end_date AND NEW.end_date >= start_date
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Academic year dates cannot overlap or be reversed';
                    END IF;
                END
                SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER academic_years_no_overlap_update
                BEFORE UPDATE ON academic_years
                FOR EACH ROW
                BEGIN
                    IF NEW.start_date > NEW.end_date OR EXISTS (
                        SELECT 1 FROM academic_years
                        WHERE id <> NEW.id
                          AND NEW.start_date <= end_date
                          AND NEW.end_date >= start_date
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Academic year dates cannot overlap or be reversed';
                    END IF;
                END
                SQL);
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER academic_years_no_overlap_insert
                BEFORE INSERT ON academic_years
                FOR EACH ROW
                WHEN NEW.start_date > NEW.end_date OR EXISTS (
                    SELECT 1 FROM academic_years
                    WHERE NEW.start_date <= end_date AND NEW.end_date >= start_date
                )
                BEGIN
                    SELECT RAISE(ABORT, 'Academic year dates cannot overlap or be reversed');
                END
                SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER academic_years_no_overlap_update
                BEFORE UPDATE ON academic_years
                FOR EACH ROW
                WHEN NEW.start_date > NEW.end_date OR EXISTS (
                    SELECT 1 FROM academic_years
                    WHERE id <> NEW.id
                      AND NEW.start_date <= end_date
                      AND NEW.end_date >= start_date
                )
                BEGIN
                    SELECT RAISE(ABORT, 'Academic year dates cannot overlap or be reversed');
                END
                SQL);
        }
    }

    private function dropAcademicYearOverlapTriggers(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb', 'sqlite'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS academic_years_no_overlap_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS academic_years_no_overlap_update');
        }
    }
};
