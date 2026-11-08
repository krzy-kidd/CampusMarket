<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Message;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConversationController extends Controller
{
    private const MESSAGE_PAGE_SIZE = 50;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureActiveAccount($user->account_status);
        $validated = $request->validate(['cursor' => ['nullable', 'string']]);

        $conversations = Conversation::query()
            ->where(fn (Builder $query) => $query
                ->where('buyer_id', $user->id)
                ->orWhere('seller_id', $user->id))
            ->with([
                'listing:id,title,price,listing_status',
                'buyer:id,first_name,last_name',
                'seller:id,first_name,last_name',
                'messages' => fn ($query) => $query->latest('id')->limit(1),
            ])
            ->withCount([
                'messages as unread_count' => fn ($query) => $query
                    ->where('sender_id', '<>', $user->id)
                    ->whereNull('read_at'),
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->cursorPaginate(30, ['*'], 'cursor', $validated['cursor'] ?? null);

        return response()->json([
            'conversations' => $conversations->getCollection()
                ->map(fn (Conversation $conversation): array => [
                    'id' => $conversation->id,
                    'listing' => [
                        'id' => $conversation->listing->id,
                        'title' => $conversation->listing->title,
                        'listing_status' => $conversation->listing->listing_status,
                    ],
                    'participant' => $this->participantPayload($conversation, $user->id),
                    'last_message' => $this->messagePayload($conversation->messages->first()),
                    'unread_count' => $conversation->unread_count,
                    'updated_at' => $conversation->updated_at,
                ]),
            'next_cursor' => $conversations->nextCursor()?->encode(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureActiveAccount($user->account_status);
        $validated = $request->validate([
            'listing_id' => ['required', 'integer', 'exists:listings,id'],
        ]);
        $messageText = $this->validatedMessageText($request);

        $listing = Listing::query()->findOrFail($validated['listing_id']);
        abort_unless($listing->listing_status === 'available', 409, 'This listing is no longer available.');
        abort_if($listing->seller_id === $user->id, 403, 'You cannot message yourself about your own listing.');

        [$conversation, $message] = DB::transaction(function () use ($listing, $user, $messageText): array {
            $conversation = Conversation::query()->firstOrCreate([
                'listing_id' => $listing->id,
                'buyer_id' => $user->id,
                'seller_id' => $listing->seller_id,
            ]);
            $message = $conversation->messages()->create([
                'sender_id' => $user->id,
                'message_text' => $messageText,
                'sent_at' => now(),
            ]);
            $conversation->touch();
            Notification::query()->create([
                'user_id' => $conversation->seller_id,
                'notification_type' => 'new_message',
                'message' => 'You received a new message about '.$listing->title.'.',
                'action_url' => '/messages/'.$conversation->id,
                'created_at' => now(),
            ]);

            return [$conversation, $message];
        });

        return response()->json([
            'conversation_id' => $conversation->id,
            'message' => $this->messagePayload($message),
        ], $conversation->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        $this->ensureActiveAccount($user->account_status);
        $this->ensureParticipant($conversation, $user->id);

        $conversation->messages()
            ->where('sender_id', '<>', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $messages = $conversation->messages()
            ->orderByDesc('id')
            ->cursorPaginate(
                self::MESSAGE_PAGE_SIZE,
                ['id', 'conversation_id', 'sender_id', 'message_text', 'sent_at', 'read_at'],
            );
        $conversation->load([
            'listing:id,title,price,listing_status',
            'buyer:id,first_name,last_name',
            'seller:id,first_name,last_name',
            'transaction:id,transaction_status,agreed_price',
        ]);

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'listing' => [
                    'id' => $conversation->listing->id,
                    'title' => $conversation->listing->title,
                    'price' => $conversation->listing->price,
                    'listing_status' => $conversation->listing->listing_status,
                ],
                'participant' => $this->participantPayload($conversation, $user->id),
                'current_user_role' => $conversation->buyer_id === $user->id ? 'buyer' : 'seller',
                'transaction' => $conversation->transaction
                    ? [
                        'id' => $conversation->transaction->id,
                        'transaction_status' => $conversation->transaction->transaction_status,
                        'agreed_price' => $conversation->transaction->agreed_price,
                    ]
                    : null,
            ],
            'messages' => $messages->getCollection()
                ->reverse()
                ->values()
                ->map(fn (Message $message): array => $this->messagePayload($message)),
            'next_cursor' => $messages->nextCursor()?->encode(),
        ]);
    }

    public function send(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        $this->ensureActiveAccount($user->account_status);
        $this->ensureParticipant($conversation, $user->id);
        $messageText = $this->validatedMessageText($request);

        $message = DB::transaction(function () use ($conversation, $user, $messageText): Message {
            $message = $conversation->messages()->create([
                'sender_id' => $user->id,
                'message_text' => $messageText,
                'sent_at' => now(),
            ]);
            $conversation->touch();
            $recipientId = $conversation->buyer_id === $user->id
                ? $conversation->seller_id
                : $conversation->buyer_id;
            Notification::query()->create([
                'user_id' => $recipientId,
                'notification_type' => 'new_message',
                'message' => 'You received a new message about '.$conversation->listing()->value('title').'.',
                'action_url' => '/messages/'.$conversation->id,
                'created_at' => now(),
            ]);

            return $message;
        });

        return response()->json([
            'message' => $this->messagePayload($message),
        ], 201);
    }

    public function olderMessages(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        $this->ensureActiveAccount($user->account_status);
        $this->ensureParticipant($conversation, $user->id);

        $validated = $request->validate(['cursor' => ['required', 'string']]);
        $messages = $conversation->messages()
            ->orderByDesc('id')
            ->cursorPaginate(
                self::MESSAGE_PAGE_SIZE,
                ['id', 'conversation_id', 'sender_id', 'message_text', 'sent_at', 'read_at'],
                'cursor',
                $validated['cursor'],
            );
        $messages->getCollection()
            ->where('sender_id', '<>', $user->id)
            ->whereNull('read_at')
            ->each(fn (Message $message) => $message->update(['read_at' => now()]));

        return response()->json([
            'messages' => $messages->getCollection()
                ->reverse()
                ->values()
                ->map(fn (Message $message): array => $this->messagePayload($message->fresh())),
            'next_cursor' => $messages->nextCursor()?->encode(),
        ]);
    }

    private function ensureActiveAccount(string $accountStatus): void
    {
        abort_unless($accountStatus === 'active', 403, 'An active account is required to use private messages.');
    }

    private function validatedMessageText(Request $request): string
    {
        $messageText = $request->input('message_text');
        if (is_string($messageText)) {
            $request->merge(['message_text' => trim($messageText)]);
        }

        return $request->validate([
            'message_text' => ['required', 'string', 'max:2000'],
        ])['message_text'];
    }

    private function ensureParticipant(Conversation $conversation, int $userId): void
    {
        abort_unless(
            $conversation->buyer_id === $userId || $conversation->seller_id === $userId,
            404,
            'This conversation was not found.',
        );
    }

    private function participantPayload(Conversation $conversation, int $userId): array
    {
        $participant = $conversation->buyer_id === $userId
            ? $conversation->seller
            : $conversation->buyer;

        return [
            'id' => $participant->id,
            'first_name' => $participant->first_name,
            'last_name' => $participant->last_name,
        ];
    }

    private function messagePayload(?Message $message): ?array
    {
        if (! $message) {
            return null;
        }

        return [
            'id' => $message->id,
            'sender_id' => $message->sender_id,
            'message_text' => $message->message_text,
            'sent_at' => $message->sent_at,
            'read_at' => $message->read_at,
        ];
    }
}
