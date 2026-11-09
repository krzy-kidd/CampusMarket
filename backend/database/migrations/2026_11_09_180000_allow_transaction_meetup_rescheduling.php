<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->replaceUpdateTrigger(true);
    }

    public function down(): void
    {
        $this->replaceUpdateTrigger(false);
    }

    private function replaceUpdateTrigger(bool $allowRescheduling): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb', 'sqlite'], true)) {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS transactions_validate_claim_update');
        $rescheduleTransition = $allowRescheduling
            ? "OR (OLD.transaction_status = 'meetup_arranged' AND NEW.transaction_status = 'pending_agreement')"
            : '';
        $transitionGuard = "(OLD.transaction_status <> NEW.transaction_status AND NOT (
            (OLD.transaction_status = 'pending_agreement' AND NEW.transaction_status IN ('meetup_arranged', 'cancelled'))
            OR (OLD.transaction_status = 'meetup_arranged' AND NEW.transaction_status IN ('completed', 'cancelled', 'disputed'))
            {$rescheduleTransition}
            OR (OLD.transaction_status = 'disputed' AND NEW.transaction_status IN ('meetup_arranged', 'cancelled'))
        ))";

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER transactions_validate_claim_update
                BEFORE UPDATE ON transactions
                FOR EACH ROW
                BEGIN
                    IF NEW.buyer_id = NEW.seller_id
                        OR NOT EXISTS (
                            SELECT 1 FROM listings
                            WHERE id = NEW.listing_id AND seller_id = NEW.seller_id
                        )
                        OR {$transitionGuard}
                        OR (
                            NEW.transaction_status IN ('pending_agreement', 'meetup_arranged', 'disputed')
                            AND (NEW.active_listing_id IS NULL OR NEW.active_listing_id <> NEW.listing_id)
                        )
                        OR (
                            NEW.transaction_status IN ('completed', 'cancelled')
                            AND NEW.active_listing_id IS NOT NULL
                        )
                        OR (
                            NEW.transaction_status IN ('meetup_arranged', 'completed')
                            AND (
                                NEW.meetup_date IS NULL
                                OR NEW.meetup_time IS NULL
                                OR NEW.meetup_location IS NULL
                                OR NEW.buyer_meetup_confirmed_at IS NULL
                                OR NEW.seller_meetup_confirmed_at IS NULL
                            )
                        )
                        OR (
                            NEW.transaction_status = 'completed'
                            AND (
                                NEW.buyer_completion_confirmed_at IS NULL
                                OR NEW.seller_completion_confirmed_at IS NULL
                                OR NEW.completed_at IS NULL
                            )
                        )
                        OR (
                            NEW.transaction_status = 'cancelled'
                            AND (NEW.cancellation_reason IS NULL OR TRIM(NEW.cancellation_reason) = '')
                        )
                    THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Transaction state, confirmations, participants, or listing claim are invalid';
                    END IF;
                END
                SQL);
        } else {
            DB::unprepared(<<<SQL
                CREATE TRIGGER transactions_validate_claim_update
                BEFORE UPDATE ON transactions
                FOR EACH ROW
                WHEN NEW.buyer_id = NEW.seller_id
                    OR NOT EXISTS (
                        SELECT 1 FROM listings
                        WHERE id = NEW.listing_id AND seller_id = NEW.seller_id
                    )
                    OR {$transitionGuard}
                    OR (
                        NEW.transaction_status IN ('pending_agreement', 'meetup_arranged', 'disputed')
                        AND (NEW.active_listing_id IS NULL OR NEW.active_listing_id <> NEW.listing_id)
                    )
                    OR (
                        NEW.transaction_status IN ('completed', 'cancelled')
                        AND NEW.active_listing_id IS NOT NULL
                    )
                    OR (
                        NEW.transaction_status IN ('meetup_arranged', 'completed')
                        AND (
                            NEW.meetup_date IS NULL
                            OR NEW.meetup_time IS NULL
                            OR NEW.meetup_location IS NULL
                            OR NEW.buyer_meetup_confirmed_at IS NULL
                            OR NEW.seller_meetup_confirmed_at IS NULL
                        )
                    )
                    OR (
                        NEW.transaction_status = 'completed'
                        AND (
                            NEW.buyer_completion_confirmed_at IS NULL
                            OR NEW.seller_completion_confirmed_at IS NULL
                            OR NEW.completed_at IS NULL
                        )
                    )
                    OR (
                        NEW.transaction_status = 'cancelled'
                        AND (NEW.cancellation_reason IS NULL OR TRIM(NEW.cancellation_reason) = '')
                    )
                BEGIN
                    SELECT RAISE(ABORT, 'Transaction state, confirmations, participants, or listing claim are invalid');
                END
                SQL);
        }
    }
};
