<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('transaction_id')
                ->nullable()
                ->unique()
                ->constrained()
                ->restrictOnDelete();
        });

        $this->createIntegrityTriggers();
    }

    public function down(): void
    {
        $this->dropIntegrityTriggers();

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transaction_id');
        });
    }

    private function createIntegrityTriggers(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER conversations_validate_participants_insert
                BEFORE INSERT ON conversations
                FOR EACH ROW
                BEGIN
                    IF NEW.buyer_id = NEW.seller_id
                        OR NOT EXISTS (
                            SELECT 1 FROM listings
                            WHERE id = NEW.listing_id AND seller_id = NEW.seller_id
                        )
                    THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Conversation participants must be distinct and match the listing seller';
                    END IF;
                END
                SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER conversations_validate_participants_update
                BEFORE UPDATE ON conversations
                FOR EACH ROW
                BEGIN
                    IF NEW.buyer_id = NEW.seller_id
                        OR NOT EXISTS (
                            SELECT 1 FROM listings
                            WHERE id = NEW.listing_id AND seller_id = NEW.seller_id
                        )
                    THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Conversation participants must be distinct and match the listing seller';
                    END IF;
                END
                SQL);

            foreach (['INSERT' => 'insert', 'UPDATE' => 'update'] as $event => $suffix) {
                $invalidTransition = $event === 'INSERT'
                    ? "NEW.transaction_status <> 'pending_agreement'"
                    : "(OLD.transaction_status <> NEW.transaction_status AND NOT (
                        (OLD.transaction_status = 'pending_agreement' AND NEW.transaction_status IN ('meetup_arranged', 'cancelled'))
                        OR (OLD.transaction_status = 'meetup_arranged' AND NEW.transaction_status IN ('completed', 'cancelled', 'disputed'))
                        OR (OLD.transaction_status = 'disputed' AND NEW.transaction_status IN ('meetup_arranged', 'cancelled'))
                    ))";
                $priceSnapshotGuard = $event === 'INSERT'
                    ? 'NEW.agreed_price <> (SELECT price FROM listings WHERE id = NEW.listing_id)'
                    : '0 = 1';

                DB::unprepared(<<<SQL
                    CREATE TRIGGER messages_validate_sender_{$suffix}
                    BEFORE {$event} ON messages
                    FOR EACH ROW
                    BEGIN
                        IF NOT EXISTS (
                            SELECT 1 FROM conversations
                            WHERE id = NEW.conversation_id
                              AND NEW.sender_id IN (buyer_id, seller_id)
                        ) THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Message sender must belong to the conversation';
                        END IF;
                    END
                    SQL);

                DB::unprepared(<<<SQL
                    CREATE TRIGGER transactions_validate_claim_{$suffix}
                    BEFORE {$event} ON transactions
                    FOR EACH ROW
                    BEGIN
                        IF NEW.buyer_id = NEW.seller_id
                            OR NOT EXISTS (
                                SELECT 1 FROM listings
                                WHERE id = NEW.listing_id AND seller_id = NEW.seller_id
                            )
                            OR {$priceSnapshotGuard}
                            OR {$invalidTransition}
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
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Transaction participants, listing owner, or active claim are invalid';
                        END IF;
                    END
                    SQL);
            }
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER conversations_validate_participants_insert
                BEFORE INSERT ON conversations
                FOR EACH ROW
                WHEN NEW.buyer_id = NEW.seller_id
                    OR NOT EXISTS (
                        SELECT 1 FROM listings
                        WHERE id = NEW.listing_id AND seller_id = NEW.seller_id
                    )
                BEGIN
                    SELECT RAISE(ABORT, 'Conversation participants must be distinct and match the listing seller');
                END
                SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER conversations_validate_participants_update
                BEFORE UPDATE ON conversations
                FOR EACH ROW
                WHEN NEW.buyer_id = NEW.seller_id
                    OR NOT EXISTS (
                        SELECT 1 FROM listings
                        WHERE id = NEW.listing_id AND seller_id = NEW.seller_id
                    )
                BEGIN
                    SELECT RAISE(ABORT, 'Conversation participants must be distinct and match the listing seller');
                END
                SQL);

            foreach (['INSERT' => 'insert', 'UPDATE' => 'update'] as $event => $suffix) {
                $invalidTransition = $event === 'INSERT'
                    ? "NEW.transaction_status <> 'pending_agreement'"
                    : "(OLD.transaction_status <> NEW.transaction_status AND NOT (
                            (OLD.transaction_status = 'pending_agreement' AND NEW.transaction_status IN ('meetup_arranged', 'cancelled'))
                            OR (OLD.transaction_status = 'meetup_arranged' AND NEW.transaction_status IN ('completed', 'cancelled', 'disputed'))
                            OR (OLD.transaction_status = 'disputed' AND NEW.transaction_status IN ('meetup_arranged', 'cancelled'))
                        ))";
                $priceSnapshotGuard = $event === 'INSERT'
                    ? 'NEW.agreed_price <> (SELECT price FROM listings WHERE id = NEW.listing_id)'
                    : '0 = 1';

                DB::unprepared(<<<SQL
                    CREATE TRIGGER messages_validate_sender_{$suffix}
                    BEFORE {$event} ON messages
                    FOR EACH ROW
                    WHEN NOT EXISTS (
                        SELECT 1 FROM conversations
                        WHERE id = NEW.conversation_id
                          AND NEW.sender_id IN (buyer_id, seller_id)
                    )
                    BEGIN
                        SELECT RAISE(ABORT, 'Message sender must belong to the conversation');
                    END
                    SQL);

                DB::unprepared(<<<SQL
                    CREATE TRIGGER transactions_validate_claim_{$suffix}
                    BEFORE {$event} ON transactions
                    FOR EACH ROW
                    WHEN NEW.buyer_id = NEW.seller_id
                        OR NOT EXISTS (
                            SELECT 1 FROM listings
                            WHERE id = NEW.listing_id AND seller_id = NEW.seller_id
                        )
                        OR {$priceSnapshotGuard}
                        OR {$invalidTransition}
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
                        SELECT RAISE(ABORT, 'Transaction participants, listing owner, or active claim are invalid');
                    END
                    SQL);
            }
        }
    }

    private function dropIntegrityTriggers(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb', 'sqlite'], true)) {
            return;
        }

        foreach ([
            'conversations_validate_participants_insert',
            'conversations_validate_participants_update',
            'messages_validate_sender_insert',
            'messages_validate_sender_update',
            'transactions_validate_claim_insert',
            'transactions_validate_claim_update',
        ] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }
};
