<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NavigationSummaryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $unreadMessages = Message::query()
            ->join('conversations', 'messages.conversation_id', '=', 'conversations.id')
            ->where('messages.sender_id', '<>', $user->id)
            ->whereNull('messages.read_at')
            ->where(fn ($query) => $query
                ->where('conversations.buyer_id', $user->id)
                ->orWhere('conversations.seller_id', $user->id))
            ->selectRaw('COUNT(messages.id)');
        $unreadNotifications = $user->campusNotifications()
            ->where('is_read', false)
            ->selectRaw('COUNT(*)');
        $activeTransactions = Transaction::query()
            ->where(fn ($query) => $query
                ->where('buyer_id', $user->id)
                ->orWhere('seller_id', $user->id))
            ->whereIn('transaction_status', Transaction::ACTIVE_STATUSES)
            ->selectRaw('COUNT(*)');
        $availableListings = $user->listings()
            ->where('listing_status', 'available')
            ->selectRaw('COUNT(*)');
        $summary = DB::query()
            ->selectSub($unreadNotifications, 'unread_notifications')
            ->selectSub($unreadMessages, 'unread_messages')
            ->selectSub($activeTransactions, 'active_transactions')
            ->selectSub($availableListings, 'available_listings')
            ->first();

        return response()->json([
            'unread_notifications' => (int) $summary->unread_notifications,
            'unread_messages' => (int) $summary->unread_messages,
            'active_transactions' => (int) $summary->active_transactions,
            'available_listings' => (int) $summary->available_listings,
        ]);
    }
}
