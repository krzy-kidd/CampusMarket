<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NavigationSummaryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $unreadMessages = Message::query()
            ->join('conversations', 'messages.conversation_id', '=', 'conversations.id')
            ->where('messages.sender_id', '<>', $user->id)
            ->whereNull('messages.read_at')
            ->where(fn (Builder $query) => $query
                ->where('conversations.buyer_id', $user->id)
                ->orWhere('conversations.seller_id', $user->id))
            ->count('messages.id');

        return response()->json([
            'unread_notifications' => $user->campusNotifications()->where('is_read', false)->count(),
            'unread_messages' => $unreadMessages,
            'active_transactions' => Transaction::query()
                ->where(fn (Builder $query) => $query
                    ->where('buyer_id', $user->id)
                    ->orWhere('seller_id', $user->id))
                ->whereIn('transaction_status', Transaction::ACTIVE_STATUSES)
                ->count(),
            'available_listings' => $user->listings()->where('listing_status', 'available')->count(),
        ]);
    }
}
