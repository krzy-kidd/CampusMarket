<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        // Adding a constrained column rebuilds SQLite tables and drops their triggers.
        // Restore the transaction insert guard after that rebuild.
        DB::unprepared('DROP TRIGGER IF EXISTS transactions_validate_claim_insert');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER transactions_validate_claim_insert
            BEFORE INSERT ON transactions
            FOR EACH ROW
            WHEN NEW.buyer_id = NEW.seller_id
                OR NOT EXISTS (
                    SELECT 1 FROM listings
                    WHERE id = NEW.listing_id AND seller_id = NEW.seller_id
                )
                OR NEW.agreed_price <> (SELECT price FROM listings WHERE id = NEW.listing_id)
                OR NEW.transaction_status <> 'pending_agreement'
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

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS transactions_validate_claim_insert');
        }
    }
};
