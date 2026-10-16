<?php

namespace App\Services\Customer;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomerOrderService
{
    public function forUser(int $userId): Collection
    {
        $orders = DB::table('WBO_Orders')
            ->where('customer_user_id', $userId)
            ->orderByDesc('order_date')
            ->get();

        $orderIds = $orders->pluck('order_id')->all();

        $details = $this->details($orderIds);
        $returns = $this->returns($orderIds);
        $returnItems = $this->returnItems(
            $returns->pluck('return_id')->all()
        );

        return $orders
            ->map(function ($order) use (
                $details,
                $returns,
                $returnItems
            ) {
                $items = collect(
                    $details->get($order->order_id, [])
                )
                    ->map(fn ($item) => [
                        'order_detail_id' =>
                            (int) $item->order_detail_id,
                        'product_id' =>
                            (int) $item->product_id,
                        'product_name' =>
                            $item->product_name,
                        'sku' => $item->sku,
                        'quantity' =>
                            (int) $item->quantity,
                        'unit_price' =>
                            (float) $item->unit_price,
                        'line_total' =>
                            (float) $item->unit_price *
                            (int) $item->quantity,
                    ])
                    ->values();

                $return = $returns->get(
                    $order->order_id
                );

                return [
                    'order_id' =>
                        (int) $order->order_id,
                    'order_date' =>
                        $order->order_date,
                    'status' => $order->status,
                    'cancelled_at' =>
                        $order->cancelled_at ?? null,
                    'items' => $items,
                    'total' =>
                        (float) $items->sum('line_total'),
                    'delivery' =>
                        $this->delivery($order),
                    'payment' =>
                        $this->payment($order),
                    'return_request' =>
                        $return
                            ? $this->returnPayload(
                                $return,
                                $returnItems
                            )
                            : null,
                ];
            })
            ->values();
    }

    private function details(array $orderIds): Collection
    {
        if (!$orderIds) {
            return collect();
        }

        return DB::table('WBO_OrderDetails as od')
            ->join(
                'WBO_Products as p',
                'p.product_id',
                '=',
                'od.product_id'
            )
            ->whereIn('od.order_id', $orderIds)
            ->select(
                'od.*',
                'p.name as product_name',
                'p.sku'
            )
            ->get()
            ->groupBy('order_id');
    }

    private function returns(array $orderIds): Collection
    {
        if (
            !$orderIds ||
            !Schema::hasTable('WBO_ReturnRequests')
        ) {
            return collect();
        }

        return DB::table('WBO_ReturnRequests')
            ->whereIn('order_id', $orderIds)
            ->get()
            ->keyBy('order_id');
    }

    private function returnItems(
        array $returnIds
    ): Collection {
        if (
            !$returnIds ||
            !Schema::hasTable('WBO_ReturnItems')
        ) {
            return collect();
        }

        return DB::table('WBO_ReturnItems as ri')
            ->join(
                'WBO_Products as p',
                'p.product_id',
                '=',
                'ri.product_id'
            )
            ->whereIn('ri.return_id', $returnIds)
            ->select(
                'ri.*',
                'p.name as product_name',
                'p.sku'
            )
            ->get()
            ->groupBy('return_id');
    }

    private function delivery(object $order): ?array
    {
        $hasDelivery =
            $order->delivery_street_address !== null ||
            $order->delivery_email !== null;

        if (!$hasDelivery) {
            return null;
        }

        return [
            'full_name' => $order->customer_name,
            'email' => $order->delivery_email,
            'contact_number' =>
                $order->customer_contact,
            'street_address' =>
                $order->delivery_street_address,
            'barangay' =>
                $order->delivery_barangay,
            'city_municipality' =>
                $order->delivery_city_municipality,
            'province' =>
                $order->delivery_province,
            'postal_code' =>
                $order->delivery_postal_code,
            'delivery_notes' =>
                $order->delivery_notes,
        ];
    }

    private function payment(object $order): ?array
    {
        if ($order->payment_method === null) {
            return null;
        }

        return [
            'payment_method' =>
                $order->payment_method,
            'payment_status' =>
                $order->payment_status,
            'amount' => (float) (
                $order->payment_amount ??
                $order->total_amount
            ),
            'reference_number' =>
                $order->payment_reference_number,
            'paid_at' =>
                $order->paid_at,
        ];
    }

    private function returnPayload(
        object $return,
        Collection $returnItems
    ): array {
        $items = collect(
            $returnItems->get(
                $return->return_id,
                []
            )
        )
            ->map(fn ($item) => [
                'return_item_id' =>
                    (int) $item->return_item_id,
                'order_detail_id' =>
                    (int) $item->order_detail_id,
                'product_id' =>
                    (int) $item->product_id,
                'product_name' =>
                    $item->product_name,
                'sku' => $item->sku,
                'quantity' =>
                    (int) $item->quantity,
            ])
            ->values();

        return [
            'return_id' =>
                (int) $return->return_id,
            'status' =>
                $return->status,
            'reason' =>
                $return->reason,
            'inspection_disposition' =>
                $return->inspection_disposition,
            'inspection_notes' =>
                $return->inspection_notes,
            'refund_amount' =>
                (float) $return->refund_amount,
            'requested_at' =>
                $return->requested_at,
            'approved_at' =>
                $return->approved_at,
            'received_at' =>
                $return->received_at,
            'inspected_at' =>
                $return->inspected_at,
            'refunded_at' =>
                $return->refunded_at,
            'items' => $items,
        ];
    }
}