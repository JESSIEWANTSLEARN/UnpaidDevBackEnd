<?php

namespace App\Http\Controllers\Support\Concerns;

use App\Models\WBOUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait SupportChatHelpers
{
    private function customer(Request $request): WBOUser
    {
        if (
            $request->session()->get('logged_in') !== true ||
            $request->session()->get('role') !== 'System_User'
        ) {
            abort(403, 'System User access required.');
        }

        $user = WBOUser::find((int) $request->session()->get('user_id'));

        if (!$user || $user->account_status !== 'active') {
            abort(401, 'Account unavailable.');
        }

        return $user;
    }

    private function staffRead(Request $request): bool
    {
        if (session('logged_in') !== true) {
            abort(401, 'Authentication required.');
        }

        $role = (string) session('role');

        if (in_array($role, self::STAFF_ROLES, true)) {
            return false;
        }

        if ($role === 'super_admin' && $request->boolean('preview')) {
            return true;
        }

        abort(403, 'Sales Support access required.');
    }

    private function staffAction(): WBOUser
    {
        if (
            session('logged_in') !== true ||
            !in_array((string) session('role'), self::STAFF_ROLES, true)
        ) {
            abort(403, 'Actual Sales role required.');
        }

        $user = WBOUser::find((int) session('user_id'));
        abort_unless($user && $user->account_status === 'active', 401);

        return $user;
    }

    private function ready(): void
    {
        if (self::$supportTablesReady === true) {
            return;
        }

        $ready =
            Schema::hasTable(
                'WBO_Conversations'
            ) &&
            Schema::hasTable(
                'WBO_ConversationMessages'
            ) &&
            Schema::hasTable(
                'WBO_ConversationTransfers'
            );

        if (!$ready) {
            abort(
                503,
                'Support chat database tables are not installed yet.'
            );
        }

        self::$supportTablesReady = true;
    }

    private function owned(WBOUser $user, int $id): object
    {
        $this->ready();

        $row = DB::table('WBO_Conversations')
            ->where('conversation_id', $id)
            ->where('customer_user_id', $user->user_id)
            ->first();

        abort_unless($row, 404, 'Conversation not found.');
        return $row;
    }

    private function conversation(int $id): object
    {
        $this->ready();
        $row = DB::table('WBO_Conversations')->where('conversation_id', $id)->first();
        abort_unless($row, 404, 'Conversation not found.');
        return $row;
    }

    private function summary(object $row): array
    {
        $last = DB::table('WBO_ConversationMessages')
            ->where('conversation_id', $row->conversation_id)
            ->orderByDesc('message_id')
            ->first();

        return [
            'conversation_id' => (int) $row->conversation_id,
            'subject' => $row->subject,
            'status' => $row->status,
            'updated_at' => $row->updated_at,
            'last_message' => $last?->message,
        ];
    }

    private function payload(int $id): array
    {
        $row = $this->conversation($id);

        $customer = DB::table('WBO_Users')
            ->where('user_id', $row->customer_user_id)
            ->select('user_id', 'name', 'email')
            ->first();

        $assigned = $row->assigned_user_id
            ? DB::table('WBO_Users')
                ->where('user_id', $row->assigned_user_id)
                ->select('user_id', 'name', 'role')
                ->first()
            : null;

        $messages = DB::table('WBO_ConversationMessages as m')
            ->leftJoin('WBO_Users as u', 'u.user_id', '=', 'm.sender_user_id')
            ->where('m.conversation_id', $id)
            ->select('m.*', 'u.name as sender_name')
            ->orderBy('m.message_id')
            ->get()
            ->map(fn ($m) => [
                'message_id' => (int) $m->message_id,
                'sender_type' => $m->sender_type,
                'sender_name' => $m->sender_name,
                'message' => $m->message,
                'created_at' => $m->created_at,
            ])
            ->values();

        return [
            'conversation_id' => (int) $row->conversation_id,
            'subject' => $row->subject,
            'status' => $row->status,
            'customer' => $customer,
            'assigned_staff' => $assigned,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
            'closed_at' => $row->closed_at,
            'messages' => $messages,
        ];
    }

    private function message(int $conversationId, ?int $userId, string $type, string $text): void
    {
        DB::table('WBO_ConversationMessages')->insert([
            'conversation_id' => $conversationId,
            'sender_user_id' => $userId,
            'sender_type' => $type,
            'message' => $text,
            'created_at' => now(),
        ]);

        DB::table('WBO_Conversations')
            ->where('conversation_id', $conversationId)
            ->update(['updated_at' => now()]);
    }

    private function transfer(
        int $conversationId,
        ?int $from,
        ?int $to,
        string $type,
        string $note
    ): void {
        DB::table('WBO_ConversationTransfers')->insert([
            'conversation_id' => $conversationId,
            'from_user_id' => $from,
            'to_user_id' => $to,
            'transfer_type' => $type,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    private function closeConversation(object $conversation, int $actorId, string $note): void
    {
        if ($conversation->status === 'CLOSED') {
            return;
        }

        DB::transaction(function () use ($conversation, $actorId, $note) {
            DB::table('WBO_Conversations')
                ->where('conversation_id', $conversation->conversation_id)
                ->update([
                    'status' => 'CLOSED',
                    'closed_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->transfer(
                (int) $conversation->conversation_id,
                $actorId,
                $conversation->assigned_user_id ? (int) $conversation->assigned_user_id : null,
                'CLOSED',
                $note
            );

            $this->message(
                (int) $conversation->conversation_id,
                null,
                'SYSTEM',
                $note
            );
        });
    }

    private function faqReply(string $input, int $customerUserId): string
    {
        $normalized = mb_strtolower(trim($input));
        $product = $this->productFromMessage($input);
        $order = $this->orderFromMessage($input, $customerUserId);

        if ($product) {
            $stock = $this->availableProductStock((int) $product->product_id);
            $price = 'PHP ' . number_format((float) $product->unit_price, 2);
            $description = trim((string) $product->description);

            if (
                str_contains($normalized, 'currently in stock') ||
                str_contains($normalized, 'product in stock') ||
                str_contains($normalized, 'product available')
            ) {
                return $stock > 0
                    ? "{$product->name} currently has {$stock} unit(s) available."
                    : "{$product->name} is currently out of stock.";
            }

            if (
                str_contains($normalized, 'more about this product') ||
                str_contains($normalized, 'product details')
            ) {
                $details = "{$product->name} ({$product->sku}) costs {$price} and currently has {$stock} unit(s) available.";

                return $description !== ''
                    ? $details . ' ' . $description
                    : $details;
            }

            if (
                str_contains($normalized, 'order this product') ||
                str_contains($normalized, 'how do i order')
            ) {
                return $stock > 0
                    ? "{$product->name} is available. Click its product card in Support to open the product page, choose the quantity, add it to Cart, then continue to checkout."
                    : "{$product->name} is currently out of stock, so checkout is unavailable for this product.";
            }
        }

        if ($order) {
            $status = strtoupper((string) $order->status);
            $paymentStatus = strtoupper((string) ($order->payment_status ?? 'PENDING'));
            $paymentMethod = str_replace('_', ' ', strtoupper((string) ($order->payment_method ?? 'NOT SET')));

            if (
                str_contains($normalized, 'status of this order') ||
                str_contains($normalized, 'track my order') ||
                str_contains($normalized, 'order status') ||
                str_contains($normalized, 'when will this order be processed') ||
                str_contains($normalized, 'when will this order be fulfilled')
            ) {
                return "Order #{$order->order_id} is currently {$status}. Payment status: {$paymentStatus}. If you need a more specific processing update, choose Talk to staff.";
            }

            if (str_contains($normalized, 'payment')) {
                return "Order #{$order->order_id} uses {$paymentMethod}. Its current payment status is {$paymentStatus}. Choose Talk to staff if this does not match your payment.";
            }

            if (
                str_contains($normalized, 'cancel this order') ||
                str_contains($normalized, 'cancel an order')
            ) {
                if (in_array($status, ['FULFILLED', 'CANCELLED'], true)) {
                    return "Order #{$order->order_id} is already {$status}. Choose Talk to staff if you need help with this order.";
                }

                return "Order #{$order->order_id} is currently {$status}. Choose Talk to staff so Sales Support can review whether it can still be cancelled.";
            }

            if (
                str_contains($normalized, 'leave a review') ||
                str_contains($normalized, 'review for this order')
            ) {
                return $status === 'FULFILLED'
                    ? "Order #{$order->order_id} is fulfilled. Open Reviews to review an eligible purchased product."
                    : "Order #{$order->order_id} is currently {$status}. Reviews become available after an eligible purchase is fulfilled.";
            }

            if (
                str_contains($normalized, 'damaged') ||
                str_contains($normalized, 'defective') ||
                str_contains($normalized, 'problem with this completed order')
            ) {
                return "Keep Order #{$order->order_id} selected and choose Talk to staff so Sales Support can review the item concern.";
            }

            if (
                str_contains($normalized, 'cancelled or unfulfilled') ||
                str_contains($normalized, 'why was this order cancelled')
            ) {
                return "Order #{$order->order_id} is currently {$status}. Choose Talk to staff if you need the specific reason recorded by Sales Support.";
            }
        }

        if (
            str_contains($normalized, 'place an order') ||
            str_contains($normalized, 'order this product')
        ) {
            return 'Open Products, choose an item, add it to your cart, then open Cart and continue to checkout. Review the quantity, delivery information, and available payment option before submitting the order.';
        }

        if (
            str_contains($normalized, 'track my order') ||
            str_contains($normalized, 'status of this order') ||
            str_contains($normalized, 'order status')
        ) {
            return 'Open Orders to view the current status of your submitted orders. You can also use the + button in Support to select a specific order before asking a question.';
        }

        if (str_contains($normalized, 'payment')) {
            return 'Payment information and payment status are linked to your order. Complete checkout using an available payment option. If a submitted order shows an unexpected payment status, choose Talk to staff so Sales Support can review it.';
        }

        if (
            str_contains($normalized, 'delivery information') ||
            str_contains($normalized, 'change my delivery') ||
            str_contains($normalized, 'delivery work')
        ) {
            return 'Your delivery information is entered during checkout and your saved default delivery details can be updated from Account. For an order that has already been submitted, choose Talk to staff before requesting a delivery change.';
        }

        if (
            str_contains($normalized, 'cancel this order') ||
            str_contains($normalized, 'cancel an order')
        ) {
            return 'Cancellation depends on the current order status. Choose Talk to staff so Sales Support can review the selected order before any cancellation is made.';
        }

        if (
            str_contains($normalized, 'leave a review') ||
            str_contains($normalized, 'review for this order')
        ) {
            return 'Fulfilled purchases become eligible for review. Open Reviews and choose the product under Ready to review.';
        }

        if (
            str_contains($normalized, 'damaged') ||
            str_contains($normalized, 'defective')
        ) {
            return 'Please keep the affected order selected and choose Talk to staff. Sales Support can review the order and the damaged or defective item concern with you.';
        }

        if (
            str_contains($normalized, 'currently in stock') ||
            str_contains($normalized, 'product in stock') ||
            str_contains($normalized, 'product available')
        ) {
            return 'Select a product with the + button in Support to see its current available stock and ask product-specific questions.';
        }

        if (
            str_contains($normalized, 'more about this product') ||
            str_contains($normalized, 'product details')
        ) {
            return 'Select a product with the + button in Support, then click its product card to open the full product page.';
        }

        if (!Schema::hasTable('WBO_FAQs')) {
            return 'FAQ is unavailable right now. Choose Talk to staff.';
        }

        $words = collect(preg_split('/[^a-z0-9]+/i', $normalized, -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn ($word) => mb_strlen($word) >= 4)
            ->unique()
            ->values();

        $best = null;
        $score = 0;

        foreach (DB::table('WBO_FAQs')->where('is_active', true)->get() as $faq) {
            $haystack = mb_strtolower($faq->category . ' ' . $faq->question);
            $current = $words->filter(fn ($word) => str_contains($haystack, $word))->count();

            if ($current > $score) {
                $score = $current;
                $best = $faq;
            }
        }

        return $best && $score > 0
            ? (string) $best->answer
            : 'I could not find a close FAQ match. Choose Talk to staff for human assistance.';
    }

    private function productFromMessage(string $input): ?object
    {
        if (!preg_match('/\[Product #(\d+)\s*-/i', $input, $matches)) {
            return null;
        }

        return DB::table('WBO_Products')
            ->select('product_id', 'sku', 'name', 'description', 'unit_price')
            ->where('product_id', (int) $matches[1])
            ->where('is_visible', true)
            ->first();
    }

    private function availableProductStock(int $productId): int
    {
        return (int) DB::table('WBO_Batches')
            ->where('product_id', $productId)
            ->where(function ($query) {
                $query
                    ->whereNull('expiry_date')
                    ->orWhereDate('expiry_date', '>=', now()->toDateString());
            })
            ->sum('current_quantity');
    }

    private function orderFromMessage(string $input, int $customerUserId): ?object
    {
        if (!preg_match('/\[Order #(\d+)\s*-/i', $input, $matches)) {
            return null;
        }

        return DB::table('WBO_Orders')
            ->select(
                'order_id',
                'status',
                'total_amount',
                'payment_method',
                'payment_status'
            )
            ->where('order_id', (int) $matches[1])
            ->where('customer_user_id', $customerUserId)
            ->first();
    }

    private function notifySales(string $title, string $message): void
    {
        if (!Schema::hasTable('WBO_Notifications')) return;

        $ids = DB::table('WBO_Users')
            ->whereIn('role', self::STAFF_ROLES)
            ->where('account_status', 'active')
            ->pluck('user_id');

        foreach ($ids as $id) {
            $this->notifyUser((int) $id, $title, $message);
        }
    }

    private function notifyUser(int $id, string $title, string $message): void
    {
        if (!Schema::hasTable('WBO_Notifications')) return;

        DB::table('WBO_Notifications')->insert([
            'alert_tier' => 'Yellow',
            'title' => mb_substr($title, 0, 150),
            'message' => mb_substr($message, 0, 500),
            'related_product_id' => null,
            'related_batch_id' => null,
            'recipient_user_id' => $id,
            'triggered_at' => now(),
            'status' => 'UNREAD',
            'acknowledged_at' => null,
            'resolved_at' => null,
        ]);
    }

    private function audit(Request $request, int $userId, string $action, string $description): void
    {
        if (!Schema::hasTable('WBO_AuditLogs')) return;

        DB::table('WBO_AuditLogs')->insert([
            'user_id' => $userId,
            'action' => $action,
            'description' => $description,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }
}