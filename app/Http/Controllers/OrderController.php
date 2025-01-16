<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderItemResource;
use App\Notifications\OrderStatusChanged;
use App\Models\Order;
use App\Models\SubOrder;
use App\Models\Product;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\User;
use Exception;
use Log;
use Illuminate\Support\Facades\Auth;

class OrderController extends Controller
{
    use AuthorizesRequests;


    public function index()
    {
        return Order::with('orderItems')->get();
    }

    /**
     * Show an order with its sub-orders and items.
     */
    public function show($id)
    {
        $order = Order::with(['orderItems.product'])->findOrFail($id);
        try {
            $this->authorize('view', $order);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Unauthorized action'
            ]);
        }

        return response()->json(["order_details" => new OrderResource($order)]);
    }


    /**
     * Cancel the entire order.
     */
    public function cancelOrder($id)
    {
        $order = Order::findOrFail($id);
        try {
            $this->authorize('delete', $order);
        } catch (Exception $e) {
            return response()->json(['message' => 'Unauthorized action']);
        }


        if ($order->order_status !== 'pending') {
            return response()->json(['message' => 'Only pending orders can be canceled.'], 400);
        }

        $order->update(['order_status' => 'canceled']);

        $user = $order->user;
        $user->notify(new OrderStatusChanged($order, 'canceled'));

        return response()->json([
            'message' => 'Order canceled successfully.',
            'order' => new OrderResource($order),
        ], 200);
    }
    // In OrderController

    /**
     * Submit the order, changing its status from cart to pending.
     */
    public function submitCart()
    {
        // Get the authenticated user's ID
        $userId = auth()->id();

        // Find the order with order_status 'cart' and ensure it belongs to the authenticated user
        $order = Order::with(['orderItems.product'])
            ->where('user_id', $userId)
            ->where('order_status', 'cart')
            ->first();

        // Check if the order exists
        if (!$order) {
            return response()->json(['message' => 'No cart order found.'], 404);
        }

        try {
            // Check if the user is authorized to update the order
            $this->authorize('update', $order);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Unauthorized action.',
            ], 403);
        }

        $totalItemPrice = 0;
        $totalDeliveryCharge = 0;

        // Loop through order items to update product stock quantity
        foreach ($order->orderItems as $orderItem) {
            $product = $orderItem->product;

            // Check if the product stock is sufficient before reducing the quantity
            if ($product->stock_quantity < $orderItem->quantity) {
                return response()->json([
                    'message' => 'Insufficient stock for product: ' . $product->name,
                ], 400);
            }

            // Subtract the quantity ordered from the product's stock
            $product->stock_quantity -= $orderItem->quantity;
            $product->save();

            // Calculate the total item price using the effective price
            $effectivePrice = $product->effective_price;
            $totalItemPrice += $effectivePrice * $orderItem->quantity;

            // Add delivery charge (assume a fixed charge per product for now)
            $totalDeliveryCharge += 2000 * $orderItem->quantity; // 2000 charge per product quantity
        }

        // Calculate the subtotal
        $subtotal = $totalItemPrice + $totalDeliveryCharge;

        // Update the order status to 'pending' and update prices
        $order->update([
            'order_status' => 'pending',
            'items_price' => $totalItemPrice,
            'delivery_charge' => $totalDeliveryCharge,
            'subtotal' => $subtotal,
        ]);

        // Send notification to the user
        $user = $order->user;
        $user->notify(new OrderStatusChanged($order, 'created'));

        // Return the updated order data
        return response()->json([
            'message' => 'Order submitted successfully.',
            'order' => new OrderResource($order),
        ], 200);
    }


    public function getCart()
    {
        $user = Auth::user();
        $cartOrders = Order::where('user_id', $user->id)
            ->where('order_status', 'cart')
            ->with('orderItems')
            ->get();
        // return $cartOrders;
        if (!$cartOrders) {
            return response()->json([
                "message" => "The cart is empty"
            ]);
        }
        // // Return the cart orders with total price as a JSON response
        return response()->json(["order_details" => OrderResource::collection($cartOrders)]);
    }
  
}
