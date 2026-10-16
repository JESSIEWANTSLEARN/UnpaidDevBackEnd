<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\WBOUser;
use App\Services\Shared\NotificationService;
use App\Services\Auth\PasswordHistoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Authenticated customer account, order, profile, notification, and password endpoints. */
class SystemUserController extends Controller
{
    private function currentUser(Request $request): WBOUser
    {
        if (
            !$request->session()->get('logged_in') ||
            !$request->session()->get('user_id')
        ) {
            abort(401, 'Please log in first.');
        }

        if ($request->session()->get('role') !== 'System_User') {
            abort(403, 'This page is only available to System Users.');
        }

        $user = WBOUser::find(
            $request->session()->get('user_id')
        );

        if (
            !$user ||
            $user->account_status !== 'active'
        ) {
            $request->session()->invalidate();

            abort(
                401,
                'Your account is unavailable.'
            );
        }

        return $user;
    }

    // all the other methods continue here...

    private function audit(Request $request, int $userId, string $action, ?string $description = null): void
    {
        try {
            DB::table('WBO_AuditLogs')->insert([
                'user_id' => $userId,
                'action' => $action,
                'description' => $description,
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }


    public function notifications(
        Request $request,
        NotificationService $notifications
    ) {
        $user = $this->currentUser($request);

        $notifications->syncCustomerOrderNotifications(
            (int) $user->user_id
        );

        $items = Schema::hasTable('WBO_Notifications')
            ? DB::table('WBO_Notifications')
                ->where(
                    'recipient_user_id',
                    $user->user_id
                )
                ->orderByDesc('triggered_at')
                ->orderByDesc('notification_id')
                ->limit(30)
                ->get()
                ->map(fn ($item) => [
                    'notification_id' =>
                        (int) $item->notification_id,
                    'alert_tier' => $item->alert_tier,
                    'title' => $item->title,
                    'message' => $item->message,
                    'related_product_id' =>
                        $item->related_product_id,
                    'related_batch_id' =>
                        $item->related_batch_id,
                    'triggered_at' => $item->triggered_at,
                    'status' => $item->status,
                    'acknowledged_at' =>
                        $item->acknowledged_at,
                ])
                ->values()
            : collect();

        return response()->json([
            'success' => true,
            'notifications' => $items,
            'unread_count' =>
                $items
                    ->where('status', 'UNREAD')
                    ->count(),
        ]);
    }

    public function readNotification(
        Request $request,
        int $notificationId
    ) {
        $user = $this->currentUser($request);

        $notification = DB::table(
            'WBO_Notifications'
        )
            ->where(
                'notification_id',
                $notificationId
            )
            ->where(
                'recipient_user_id',
                $user->user_id
            )
            ->first();

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found.',
            ], 404);
        }

        if ($notification->status === 'UNREAD') {
            DB::table('WBO_Notifications')
                ->where(
                    'notification_id',
                    $notificationId
                )
                ->update([
                    'status' => 'ACKNOWLEDGED',
                    'acknowledged_at' => now(),
                ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read.',
        ]);
    }

    public function readAllNotifications(
        Request $request
    ) {
        $user = $this->currentUser($request);

        DB::table('WBO_Notifications')
            ->where(
                'recipient_user_id',
                $user->user_id
            )
            ->where('status', 'UNREAD')
            ->update([
                'status' => 'ACKNOWLEDGED',
                'acknowledged_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read.',
        ]);
    }
    public function me(Request $request)
    {
        $user = $this->currentUser($request);

        $stats = DB::table('WBO_Orders')
            ->where('customer_user_id', $user->user_id)
            ->selectRaw('COUNT(*) AS total_orders')
            ->selectRaw("SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) AS pending_orders")
            ->selectRaw("SUM(CASE WHEN status = 'FULFILLED' THEN 1 ELSE 0 END) AS fulfilled_orders")
            ->first();

        $profilePhoto = Schema::hasTable('WBO_UserProfilePhotos')
            ? DB::table('WBO_UserProfilePhotos')
                ->where('user_id', $user->user_id)
                ->select('updated_at')
                ->first()
            : null;

        $hasDeliveryAddress =
            trim((string) ($user->street_address ?? '')) !== '' ||
            trim((string) ($user->barangay ?? '')) !== '' ||
            trim((string) ($user->city_municipality ?? '')) !== '' ||
            trim((string) ($user->province ?? '')) !== '' ||
            trim((string) ($user->postal_code ?? '')) !== '';

        return response()->json([
            'success' => true,
            'user' => [
                'user_id' => $user->user_id,
                'name' => $user->name,
                'email' => $user->email,
                'contact_number' => $user->contact_number,
                'role' => $user->role,
                'account_status' => $user->account_status,
                'has_profile_photo' => $profilePhoto !== null,
                'profile_photo_version' => $profilePhoto
                    ? (string) $profilePhoto->updated_at
                    : null,
            ],
            'delivery_address' => $hasDeliveryAddress ? [
                'street_address' => $user->street_address,
                'barangay' => $user->barangay,
                'city_municipality' => $user->city_municipality,
                'province' => $user->province,
                'postal_code' => $user->postal_code,
            ] : null,
            'stats' => [
                'total_orders' => (int) ($stats->total_orders ?? 0),
                'pending_orders' => (int) ($stats->pending_orders ?? 0),
                'fulfilled_orders' => (int) ($stats->fulfilled_orders ?? 0),
            ],
        ]);
    }

    public function orders(Request $request)
    {
        $user = $this->currentUser($request);

        $orders = DB::table('WBO_Orders')
            ->where('customer_user_id', $user->user_id)
            ->orderByDesc('order_date')
            ->get();

        $ids = $orders->pluck('order_id')->all();
        $details = collect();

        if ($ids) {
            $details = DB::table('WBO_OrderDetails as od')
                ->join('WBO_Products as p', 'p.product_id', '=', 'od.product_id')
                ->whereIn('od.order_id', $ids)
                ->select('od.*', 'p.name as product_name', 'p.sku')
                ->get()
                ->groupBy('order_id');
        }

        $payload = $orders->map(function ($order) use ($details) {
            $items = collect($details->get($order->order_id, []))
                ->map(function ($item) {
                    return [
                        'order_detail_id' => $item->order_detail_id,
                        'product_id' => $item->product_id,
                        'product_name' => $item->product_name,
                        'sku' => $item->sku,
                        'quantity' => (int) $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'line_total' =>
                            (float) $item->unit_price *
                            (int) $item->quantity,
                    ];
                })
                ->values();

            $hasDelivery =
                $order->delivery_street_address !== null ||
                $order->delivery_email !== null;

            $delivery = $hasDelivery ? [
                'full_name' => $order->customer_name,
                'email' => $order->delivery_email,
                'contact_number' => $order->customer_contact,
                'street_address' => $order->delivery_street_address,
                'barangay' => $order->delivery_barangay,
                'city_municipality' =>
                    $order->delivery_city_municipality,
                'province' => $order->delivery_province,
                'postal_code' => $order->delivery_postal_code,
                'delivery_notes' => $order->delivery_notes,
            ] : null;

            $payment = $order->payment_method !== null ? [
                'payment_method' => $order->payment_method,
                'payment_status' => $order->payment_status,
                'amount' => (float) (
                    $order->payment_amount ??
                    $order->total_amount
                ),
                'reference_number' =>
                    $order->payment_reference_number,
                'paid_at' => $order->paid_at,
            ] : null;

            return [
                'order_id' => $order->order_id,
                'order_date' => $order->order_date,
                'status' => $order->status,
                'items' => $items,
                'total' => (float) $items->sum('line_total'),
                'delivery' => $delivery,
                'payment' => $payment,
            ];
        });

        return response()->json([
            'success' => true,
            'orders' => $payload,
        ]);
    }

    public function placeOrder(
        Request $request
    ) {
        $user = $this->currentUser($request);

        $requiredOrderColumns = [
            'delivery_email',
            'delivery_street_address',
            'delivery_barangay',
            'delivery_city_municipality',
            'delivery_province',
            'delivery_postal_code',
            'delivery_notes',
            'payment_method',
            'payment_status',
            'payment_amount',
            'payment_reference_number',
            'paid_at',
        ];

        foreach ($requiredOrderColumns as $column) {
            if (!Schema::hasColumn('WBO_Orders', $column)) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'The simplified checkout database patch is not installed yet.',
                ], 409);
            }
        }

        $validator = Validator::make($request->all(), [
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' =>
                ['required', 'integer', 'min:1'],
            'delivery' => ['required', 'array'],
            'delivery.full_name' =>
                ['required', 'string', 'max:100'],
            'delivery.contact_number' =>
                ['required', 'string', 'max:20'],
            'delivery.street_address' =>
                ['required', 'string', 'max:255'],
            'delivery.barangay' =>
                ['required', 'string', 'max:100'],
            'delivery.city_municipality' =>
                ['required', 'string', 'max:100'],
            'delivery.province' =>
                ['required', 'string', 'max:100'],
            'delivery.postal_code' =>
                ['required', 'string', 'max:20'],
            'delivery.delivery_notes' =>
                ['nullable', 'string', 'max:500'],
            'payment_method' =>
                ['required', 'string', 'in:CASH_ON_DELIVERY'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Please check your order.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $items = collect($validated['items'])
            ->groupBy('product_id')
            ->map(fn ($rows, $productId) => [
                'product_id' => (int) $productId,
                'quantity' =>
                    (int) collect($rows)->sum('quantity'),
            ])
            ->values();

        $paymentMethod = $validated['payment_method'];

        $delivery = [
            'full_name' =>
                trim($validated['delivery']['full_name']),
            'email' => $user->email,
            'contact_number' =>
                trim($validated['delivery']['contact_number']),
            'street_address' =>
                trim($validated['delivery']['street_address']),
            'barangay' =>
                trim($validated['delivery']['barangay']),
            'city_municipality' =>
                trim($validated['delivery']['city_municipality']),
            'province' =>
                trim($validated['delivery']['province']),
            'postal_code' =>
                trim($validated['delivery']['postal_code']),
            'delivery_notes' =>
                isset($validated['delivery']['delivery_notes']) &&
                trim(
                    (string) $validated['delivery']['delivery_notes']
                ) !== ''
                    ? trim(
                        $validated['delivery']['delivery_notes']
                    )
                    : null,
        ];

        try {
            $orderId = DB::transaction(function () use (
                $items,
                $user,
                $request,
                $delivery,
                $paymentMethod
            ) {
                $prepared = [];

                foreach ($items as $item) {
                    $product = DB::table('WBO_Products')
                        ->where(
                            'product_id',
                            $item['product_id']
                        )
                        ->where('is_visible', true)
                        ->first();

                    if (!$product) {
                        throw ValidationException::withMessages([
                            'items' => [
                                'One selected product is no longer available.',
                            ],
                        ]);
                    }

                    $stock = (int) DB::table('WBO_Batches')
                        ->where(
                            'product_id',
                            $product->product_id
                        )
                        ->where(function ($query) {
                            $query
                                ->whereNull('expiry_date')
                                ->orWhereDate(
                                    'expiry_date',
                                    '>=',
                                    now()->toDateString()
                                );
                        })
                        ->sum('current_quantity');

                    if ($item['quantity'] > $stock) {
                        throw ValidationException::withMessages([
                            'items' => [
                                "{$product->name} only has {$stock} unit(s) available.",
                            ],
                        ]);
                    }

                    $prepared[] = [
                        'product_id' => $product->product_id,
                        'quantity' => $item['quantity'],
                        'unit_price' => $product->unit_price,
                    ];
                }

                $totalAmount = (float) collect($prepared)
                    ->sum(
                        fn ($item) =>
                            (float) $item['unit_price'] *
                            (int) $item['quantity']
                    );

                $orderId = DB::table('WBO_Orders')
                    ->insertGetId([
                        'customer_user_id' => $user->user_id,
                        'customer_name' => $delivery['full_name'],
                        'customer_contact' =>
                            $delivery['contact_number'],
                        'delivery_email' => $delivery['email'],
                        'delivery_street_address' =>
                            $delivery['street_address'],
                        'delivery_barangay' =>
                            $delivery['barangay'],
                        'delivery_city_municipality' =>
                            $delivery['city_municipality'],
                        'delivery_province' =>
                            $delivery['province'],
                        'delivery_postal_code' =>
                            $delivery['postal_code'],
                        'delivery_notes' =>
                            $delivery['delivery_notes'],
                        'order_date' => now(),
                        'status' => 'PENDING',
                        'total_amount' => $totalAmount,
                        'payment_method' => $paymentMethod,
                        'payment_status' => 'PENDING',
                        'payment_amount' => $totalAmount,
                        'payment_reference_number' => null,
                        'paid_at' => null,
                    ]);

                foreach ($prepared as $item) {
                    DB::table('WBO_OrderDetails')->insert([
                        'order_id' => $orderId,
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                    ]);
                }

                $this->audit(
                    $request,
                    $user->user_id,
                    'ORDER_CREATED',
                    "Customer placed order #{$orderId}"
                );

                return $orderId;
            });

            $amount = (float) DB::table('WBO_Orders')
                ->where('order_id', $orderId)
                ->value('total_amount');

            return response()->json([
                'success' => true,
                'message' => 'Order placed successfully.',
                'order_id' => $orderId,
                'total_amount' => $amount,
                'payment' => [
                    'payment_method' => $paymentMethod,
                    'payment_status' => 'PENDING',
                    'amount' => $amount,
                ],
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' =>
                    collect($e->errors())->flatten()->first(),
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function profilePhoto(Request $request)
    {
        $user = $this->currentUser($request);

        if (!Schema::hasTable('WBO_UserProfilePhotos')) {
            abort(404);
        }

        $photo = DB::table('WBO_UserProfilePhotos')
            ->where('user_id', $user->user_id)
            ->first();

        if (!$photo) {
            abort(404);
        }

        return response($photo->photo_data, 200, [
            'Content-Type' => $photo->mime_type,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function updateProfilePhoto(Request $request)
    {
        $user = $this->currentUser($request);

        if (!Schema::hasTable('WBO_UserProfilePhotos')) {
            return response()->json([
                'success' => false,
                'message' => 'Profile photo storage is not installed yet.',
            ], 409);
        }

        $validated = $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $file = $validated['photo'];
        $bytes = file_get_contents($file->getRealPath());

        if ($bytes === false) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to read the selected image.',
            ], 422);
        }

        DB::table('WBO_UserProfilePhotos')->updateOrInsert(
            ['user_id' => $user->user_id],
            [
                'photo_data' => $bytes,
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'file_size' => (int) $file->getSize(),
                'updated_at' => now(),
            ]
        );

        $this->audit(
            $request,
            $user->user_id,
            'PROFILE_PHOTO_UPDATED',
            'System User updated account profile photo'
        );

        return response()->json([
            'success' => true,
            'message' => 'Profile photo updated successfully.',
            'profile_photo_version' => now()->timestamp,
        ]);
    }

    public function deleteProfilePhoto(Request $request)
    {
        $user = $this->currentUser($request);

        if (!Schema::hasTable('WBO_UserProfilePhotos')) {
            return response()->json([
                'success' => false,
                'message' => 'Profile photo storage is not installed yet.',
            ], 409);
        }

        DB::table('WBO_UserProfilePhotos')
            ->where('user_id', $user->user_id)
            ->delete();

        $this->audit(
            $request,
            $user->user_id,
            'PROFILE_PHOTO_REMOVED',
            'System User removed account profile photo'
        );

        return response()->json([
            'success' => true,
            'message' => 'Profile photo removed successfully.',
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $this->currentUser($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'contact_number' => ['nullable', 'string', 'max:20'],
        ]);

        $user->name = trim($validated['name']);
        $user->contact_number = $validated['contact_number'] ? trim($validated['contact_number']) : null;
        $user->save();

        $request->session()->put('name', $user->name);
        $this->audit($request, $user->user_id, 'PROFILE_UPDATED', 'System User updated account profile');

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'user' => [
                'user_id' => $user->user_id,
                'name' => $user->name,
                'email' => $user->email,
                'contact_number' => $user->contact_number,
                'role' => $user->role,
            ],
        ]);
    }


    public function updateDeliveryAddress(Request $request)
    {
        $user = $this->currentUser($request);

        $requiredUserColumns = [
            'street_address',
            'barangay',
            'city_municipality',
            'province',
            'postal_code',
        ];

        foreach ($requiredUserColumns as $column) {
            if (!Schema::hasColumn('WBO_Users', $column)) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'The simplified user-address database patch is not installed yet.',
                ], 409);
            }
        }

        $validated = $request->validate([
            'street_address' =>
                ['required', 'string', 'max:255'],
            'barangay' =>
                ['required', 'string', 'max:100'],
            'city_municipality' =>
                ['required', 'string', 'max:100'],
            'province' =>
                ['required', 'string', 'max:100'],
            'postal_code' =>
                ['required', 'string', 'max:20'],
        ]);

        $deliveryAddress = [
            'street_address' =>
                trim($validated['street_address']),
            'barangay' =>
                trim($validated['barangay']),
            'city_municipality' =>
                trim($validated['city_municipality']),
            'province' =>
                trim($validated['province']),
            'postal_code' =>
                trim($validated['postal_code']),
        ];

        DB::table('WBO_Users')
            ->where('user_id', $user->user_id)
            ->update($deliveryAddress);

        $this->audit(
            $request,
            $user->user_id,
            'DELIVERY_ADDRESS_UPDATED',
            'System User updated default delivery address'
        );

        return response()->json([
            'success' => true,
            'message' =>
                'Delivery information saved successfully.',
            'delivery_address' => $deliveryAddress,
        ]);
    }

    public function updatePassword(
        Request $request,
        PasswordHistoryService $passwordHistory
    )
    {
        $user = $this->currentUser($request);

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (!Hash::check($validated['current_password'], $user->password_hash)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect.',
            ], 422);
        }

        $passwordHistory->assertNotReused(
            (int) $user->user_id,
            $validated['password'],
            $user->password_hash
        );

        DB::transaction(function () use (
            $user,
            $validated,
            $passwordHistory
        ) {
            $passwordHistory->rememberCurrent(
                (int) $user->user_id,
                $user->password_hash
            );

            $user->password_hash = Hash::make($validated['password']);
            $user->save();
        });

        $this->audit($request, $user->user_id, 'PASSWORD_CHANGED', 'System User changed account password');

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully.',
        ]);
    }
}
