<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach (['INSERT' => 'insert', 'UPDATE' => 'update'] as $event => $suffix) {
                DB::unprepared(<<<SQL
                    CREATE TRIGGER listings_validate_values_{$suffix}
                    BEFORE {$event} ON listings
                    FOR EACH ROW
                    BEGIN
                        IF NEW.price <= 0 OR NEW.condition_rating NOT BETWEEN 1 AND 4 THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Listing price and condition rating are invalid';
                        END IF;
                    END
                    SQL);

                DB::unprepared(<<<SQL
                    CREATE TRIGGER reviews_validate_values_{$suffix}
                    BEFORE {$event} ON reviews
                    FOR EACH ROW
                    BEGIN
                        IF NEW.rating NOT BETWEEN 1 AND 5 OR NEW.reviewer_id = NEW.reviewed_user_id THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Review rating or participants are invalid';
                        END IF;
                    END
                    SQL);
            }
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT' => 'insert', 'UPDATE' => 'update'] as $event => $suffix) {
                DB::unprepared(<<<SQL
                    CREATE TRIGGER listings_validate_values_{$suffix}
                    BEFORE {$event} ON listings
                    FOR EACH ROW
                    WHEN NEW.price <= 0 OR NEW.condition_rating NOT BETWEEN 1 AND 4
                    BEGIN
                        SELECT RAISE(ABORT, 'Listing price and condition rating are invalid');
                    END
                    SQL);

                DB::unprepared(<<<SQL
                    CREATE TRIGGER reviews_validate_values_{$suffix}
                    BEFORE {$event} ON reviews
                    FOR EACH ROW
                    WHEN NEW.rating NOT BETWEEN 1 AND 5 OR NEW.reviewer_id = NEW.reviewed_user_id
                    BEGIN
                        SELECT RAISE(ABORT, 'Review rating or participants are invalid');
                    END
                    SQL);
            }
        }
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb', 'sqlite'], true)) {
            return;
        }

        foreach ([
            'listings_validate_values_insert',
            'listings_validate_values_update',
            'reviews_validate_values_insert',
            'reviews_validate_values_update',
        ] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }
};
