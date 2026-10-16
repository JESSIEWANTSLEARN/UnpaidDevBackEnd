<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\WBOUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SupportChatController extends Controller
{
    use Concerns\SupportChatHelpers;
    private const STAFF_ROLES = ['Sales_Manager', 'Sales_Staff'];

    private static ?bool $supportTablesReady = null;

    public function customerIndex(Request $request): JsonResponse
    {
        $user = $this->customer($request);
        $this->ready();

        $rows = DB::table('WBO_Conversations as c')
            ->select(
                'c.conversation_id',
                'c.subject',
                'c.status',
                'c.updated_at',
                DB::raw(
                    '(SELECT m.message
                      FROM WBO_ConversationMessages m
                      WHERE m.conversation_id = c.conversation_id
                      ORDER BY m.message_id DESC
                      LIMIT 1) AS last_message'
                )
            )
            ->where(
                'c.customer_user_id',
                $user->user_id
            )
            ->orderByDesc('c.updated_at')
            ->limit(30)
            ->get()
            ->map(fn ($row) => [
                'conversation_id' =>
                    (int) $row->conversation_id,
                'subject' => $row->subject,
                'status' => $row->status,
                'updated_at' => $row->updated_at,
                'last_message' =>
                    $row->last_message,
            ])
            ->values();

        return response()->json(['success' => true, 'conversations' => $rows]);
    }

    public function customerStart(Request $request): JsonResponse
    {
        $user = $this->customer($request);
        $this->ready();

        $validated = $request->validate([
            'subject' => ['nullable', 'string', 'max:150'],
        ]);

        $existing = DB::table('WBO_Conversations')
            ->where('customer_user_id', $user->user_id)
            ->where('status', '<>', 'CLOSED')
            ->orderByDesc('updated_at')
            ->first();

        if ($existing) {
            return response()->json([
                'success' => true,
                'conversation' => $this->payload((int) $existing->conversation_id),
                'message' => 'Existing support conversation opened.',
            ]);
        }

        $id = DB::transaction(function () use ($user, $validated) {
            $id = DB::table('WBO_Conversations')->insertGetId([
                'customer_user_id' => $user->user_id,
                'assigned_user_id' => null,
                'subject' => trim((string) ($validated['subject'] ?? '')) ?: 'Customer support',
                'status' => 'BOT',
                'created_at' => now(),
                'updated_at' => now(),
                'closed_at' => null,
            ]);

            $this->message(
                (int) $id,
                null,
                'BOT',
                'Hi! Ask a question and I will search the Walang Brownout FAQ. You can also choose Talk to staff.'
            );

            return (int) $id;
        });

        $this->audit($request, (int) $user->user_id, 'SUPPORT_CONVERSATION_STARTED', "Conversation #{$id} started.");

        return response()->json([
            'success' => true,
            'message' => 'Support conversation started.',
            'conversation' => $this->payload($id),
        ], 201);
    }

    public function customerShow(Request $request, int $conversationId): JsonResponse
    {
        $user = $this->customer($request);
        $this->owned($user, $conversationId);

        return response()->json([
            'success' => true,
            'conversation' => $this->payload($conversationId),
        ]);
    }

    public function customerMessage(Request $request, int $conversationId): JsonResponse
    {
        $user = $this->customer($request);
        $conversation = $this->owned($user, $conversationId);

        if ($conversation->status === 'CLOSED') {
            return response()->json(['success' => false, 'message' => 'Conversation is closed.'], 409);
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:3000'],
        ]);

        $text = trim($validated['message']);

        DB::transaction(function () use ($conversation, $user, $text) {
            $this->message((int) $conversation->conversation_id, (int) $user->user_id, 'CUSTOMER', $text);

            if ($conversation->status === 'BOT') {
                $this->message(
                    (int) $conversation->conversation_id,
                    null,
                    'BOT',
                    $this->faqReply($text, (int) $user->user_id)
                );
            }
        });

        return response()->json([
            'success' => true,
            'conversation' => $this->payload($conversationId),
        ]);
    }

    public function customerEscalate(Request $request, int $conversationId): JsonResponse
    {
        $user = $this->customer($request);
        $conversation = $this->owned($user, $conversationId);

        if ($conversation->status === 'CLOSED') {
            return response()->json(['success' => false, 'message' => 'Conversation is closed.'], 409);
        }

        if (!in_array($conversation->status, ['WAITING_STAFF', 'ACTIVE'], true)) {
            DB::transaction(function () use ($conversation, $user) {
                DB::table('WBO_Conversations')
                    ->where('conversation_id', $conversation->conversation_id)
                    ->update([
                        'status' => 'WAITING_STAFF',
                        'assigned_user_id' => null,
                        'updated_at' => now(),
                    ]);

                $this->transfer(
                    (int) $conversation->conversation_id,
                    (int) $user->user_id,
                    null,
                    'ESCALATED',
                    'Customer requested staff assistance.'
                );

                $this->message(
                    (int) $conversation->conversation_id,
                    null,
                    'SYSTEM',
                    'Conversation transferred to Sales Support.'
                );
            });

            $this->notifySales(
                "Support request #{$conversationId}",
                "{$user->name} requested staff assistance."
            );

            $this->audit($request, (int) $user->user_id, 'SUPPORT_ESCALATED', "Conversation #{$conversationId} escalated.");
        }

        return response()->json([
            'success' => true,
            'conversation' => $this->payload($conversationId),
        ]);
    }

    public function customerClose(Request $request, int $conversationId): JsonResponse
    {
        $user = $this->customer($request);
        $conversation = $this->owned($user, $conversationId);

        $this->closeConversation(
            $conversation,
            (int) $user->user_id,
            'Customer closed the conversation.'
        );

        return response()->json([
            'success' => true,
            'conversation' => $this->payload($conversationId),
        ]);
    }

    public function staffIndex(Request $request): JsonResponse
    {
        $preview = $this->staffRead($request);
        $this->ready();

        $rows = DB::table('WBO_Conversations as c')
            ->join(
                'WBO_Users as u',
                'u.user_id',
                '=',
                'c.customer_user_id'
            )
            ->leftJoin(
                'WBO_Users as a',
                'a.user_id',
                '=',
                'c.assigned_user_id'
            )
            ->select(
                'c.*',
                'u.name as customer_name',
                'u.email as customer_email',
                'a.name as assigned_name',
                DB::raw(
                    '(SELECT m.message
                      FROM WBO_ConversationMessages m
                      WHERE m.conversation_id = c.conversation_id
                      ORDER BY m.message_id DESC
                      LIMIT 1) AS last_message'
                )
            )
            ->orderByRaw(
                "CASE c.status
                    WHEN 'WAITING_STAFF' THEN 0
                    WHEN 'ACTIVE' THEN 1
                    WHEN 'BOT' THEN 2
                    ELSE 3
                END"
            )
            ->orderByDesc('c.updated_at')
            ->limit(100)
            ->get()
            ->map(fn ($row) => [
                'conversation_id' =>
                    (int) $row->conversation_id,
                'customer_name' =>
                    $row->customer_name,
                'customer_email' =>
                    $row->customer_email,
                'assigned_name' =>
                    $row->assigned_name,
                'subject' => $row->subject,
                'status' => $row->status,
                'updated_at' => $row->updated_at,
                'last_message' =>
                    $row->last_message,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'preview' => $preview,
            'conversations' => $rows,
        ]);
    }

    public function staffShow(Request $request, int $conversationId): JsonResponse
    {
        $preview = $this->staffRead($request);
        $this->conversation($conversationId);

        return response()->json([
            'success' => true,
            'preview' => $preview,
            'conversation' => $this->payload($conversationId),
        ]);
    }

    public function staffClaim(Request $request, int $conversationId): JsonResponse
    {
        $staff = $this->staffAction();
        $conversation = $this->conversation($conversationId);

        if ($conversation->status === 'CLOSED') {
            return response()->json(['success' => false, 'message' => 'Conversation is closed.'], 409);
        }

        if (
            $conversation->assigned_user_id &&
            (int) $conversation->assigned_user_id !== (int) $staff->user_id &&
            $staff->role !== 'Sales_Manager'
        ) {
            return response()->json(['success' => false, 'message' => 'Assigned to another staff member.'], 409);
        }

        DB::transaction(function () use ($conversation, $staff) {
            $old = $conversation->assigned_user_id ? (int) $conversation->assigned_user_id : null;

            DB::table('WBO_Conversations')
                ->where('conversation_id', $conversation->conversation_id)
                ->update([
                    'assigned_user_id' => $staff->user_id,
                    'status' => 'ACTIVE',
                    'updated_at' => now(),
                ]);

            $this->transfer(
                (int) $conversation->conversation_id,
                $old,
                (int) $staff->user_id,
                $old && $old !== (int) $staff->user_id ? 'REASSIGNED' : 'ASSIGNED',
                'Conversation claimed by Sales Support.'
            );

            $this->message(
                (int) $conversation->conversation_id,
                null,
                'SYSTEM',
                "{$staff->name} joined the support conversation."
            );
        });

        return response()->json([
            'success' => true,
            'conversation' => $this->payload($conversationId),
        ]);
    }

    public function staffMessage(Request $request, int $conversationId): JsonResponse
    {
        $staff = $this->staffAction();
        $conversation = $this->conversation($conversationId);

        if ($conversation->status === 'CLOSED') {
            return response()->json(['success' => false, 'message' => 'Conversation is closed.'], 409);
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:3000'],
        ]);

        DB::transaction(function () use ($conversation, $staff, $validated) {
            if ((int) ($conversation->assigned_user_id ?? 0) !== (int) $staff->user_id) {
                DB::table('WBO_Conversations')
                    ->where('conversation_id', $conversation->conversation_id)
                    ->update([
                        'assigned_user_id' => $staff->user_id,
                        'status' => 'ACTIVE',
                        'updated_at' => now(),
                    ]);

                $this->transfer(
                    (int) $conversation->conversation_id,
                    $conversation->assigned_user_id ? (int) $conversation->assigned_user_id : null,
                    (int) $staff->user_id,
                    $conversation->assigned_user_id ? 'REASSIGNED' : 'ASSIGNED',
                    'Assigned by staff reply.'
                );
            }

            $this->message(
                (int) $conversation->conversation_id,
                (int) $staff->user_id,
                'STAFF',
                trim($validated['message'])
            );
        });

        $this->notifyUser(
            (int) $conversation->customer_user_id,
            'Support replied',
            "New reply in support conversation #{$conversationId}."
        );

        return response()->json([
            'success' => true,
            'conversation' => $this->payload($conversationId),
        ]);
    }

    public function staffClose(Request $request, int $conversationId): JsonResponse
    {
        $staff = $this->staffAction();
        $conversation = $this->conversation($conversationId);

        $this->closeConversation(
            $conversation,
            (int) $staff->user_id,
            'Sales Support closed the conversation.'
        );

        $this->notifyUser(
            (int) $conversation->customer_user_id,
            'Support conversation closed',
            "Support conversation #{$conversationId} was closed."
        );

        return response()->json([
            'success' => true,
            'conversation' => $this->payload($conversationId),
        ]);
    }
}
