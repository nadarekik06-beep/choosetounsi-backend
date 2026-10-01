<?php
// app/Http/Controllers/Api/Client/PaymentController.php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Orders\SellerOrderNotifier;
use App\Services\PromotionService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\PaymentIntent;

class PaymentController extends Controller
{
    public function __construct(private WalletService $walletService) {}

    // ── GET /api/wallet/balance ────────────────────────────────────────────

    /**
     * Return the authenticated user's current wallet balance.
     * Called by frontend to display balance + enable/disable wallet option.
     */
    public function walletBalance(Request $request)
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'balance' => (float) $request->user()->wallet_balance,
            ],
        ]);
    }

    // ── GET /api/wallet/transactions ──────────────────────────────────────

    public function walletTransactions(Request $request)
    {
        $transactions = $request->user()
            ->walletTransactions()
            ->with('order:id,order_number')
            ->latest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $transactions]);
    }

    // ── POST /api/payment/stripe/create-intent ────────────────────────────

    /**
     * Creates a Stripe PaymentIntent for a pending order.
     *
     * Flow:
     *   1. Frontend calls this after order is created with payment_method='card'
     *      and payment_status='pending'
     *   2. We return client_secret
     *   3. Frontend calls stripe.confirmCardPayment(client_secret, ...)
     *   4. Stripe webhook confirms and sets payment_status='paid'
     */
    public function createStripeIntent(Request $request)
    {
        $request->validate([
            'order_id' => 'required|integer|exists:orders,id',
        ]);

        $order = Order::where('id', $request->order_id)
                      ->where('user_id', $request->user()->id)
                      ->where('payment_method', 'card')
                      ->firstOrFail();

        if ($order->payment_status === 'paid') {
            return response()->json(['success' => false, 'message' => __('messages.payment.already_paid')], 400);
        }

        try {
            Stripe::setApiKey(config('services.stripe.secret'));

            $intent = PaymentIntent::create([
                'amount'   => (int) round($order->total_amount * 1000), // millimes
                'currency' => 'tnd',
                'metadata' => [
                    'order_id'     => $order->id,
                    'order_number' => $order->order_number,
                    'user_id'      => $request->user()->id,
                ],
                'description' => "CHOOSE'Tounsi Order #{$order->order_number}",
            ]);

            // Store the intent ID so the webhook can find this order
            // (not in Order::$fillable — update() silently dropped it)
            $order->forceFill(['stripe_payment_intent_id' => $intent->id])->save();

            return response()->json([
                'success'       => true,
                'client_secret' => $intent->client_secret,
                'intent_id'     => $intent->id,
            ]);

        } catch (\Exception $e) {
            Log::error('[Stripe] createIntent failed', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => __('messages.payment.setup_failed')], 500);
        }
    }

    // ── POST /api/payment/stripe/webhook ─────────────────────────────────
    //
    // IMPORTANT: This route is registered OUTSIDE auth:sanctum middleware
    // in routes/api.php. Stripe sends raw POST bodies — no token.
    // Raw body verification requires the route to NOT use body parsing middleware.

    public function stripeWebhook(Request $request)
    {
        $secret  = config('services.stripe.webhook_secret');
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');

        try {
            Stripe::setApiKey(config('services.stripe.secret'));
            $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (\Exception $e) {
            Log::warning('[Stripe Webhook] Signature verification failed: ' . $e->getMessage());
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $intent = $event->data->object;
        match ($event->type) {
            'payment_intent.succeeded'      => $this->handlePaymentSuccess($intent->id),
            'payment_intent.payment_failed',
            'payment_intent.canceled'       => $this->handlePaymentFailure($intent->id),
            default                         => null,
        };

        // Return 200 immediately — Stripe retries on non-2xx
        return response()->json(['received' => true]);
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function handlePaymentSuccess(string $intentId): void
    {
        $order = Order::where('stripe_payment_intent_id', $intentId)->first();

        if (!$order) {
            Log::error('[Stripe Webhook] No order found for intent: ' . $intentId);
            return;
        }

        if ($order->payment_status === 'paid') {
            return; // Idempotent — already processed
        }

        // Cancelled meanwhile (orders:cancel-abandoned-card): its stock and flash
        // units are gone, so it can't ship. Record the payment and flag a refund.
        if ($order->status === 'cancelled') {
            \Illuminate\Support\Facades\DB::table('orders')->where('id', $order->id)
                ->update(['payment_status' => 'paid', 'updated_at' => now()]);
            Log::error("[Stripe Webhook] Order #{$order->order_number} was paid after being cancelled — refund it manually.");
            return;
        }

        \Illuminate\Support\Facades\DB::table('orders')
            ->where('id', $order->id)
            ->update([
                'payment_status' => 'paid',
                'status'         => 'processing', // auto-advance from pending
                'updated_at'     => now(),
            ]);

        // Also update all seller_orders for this order
        \Illuminate\Support\Facades\DB::table('seller_orders')
            ->where('order_id', $order->id)
            ->update([
                'payment_status' => 'paid',
                'updated_at'     => now(),
            ]);

        // Paid at the flash price after a failed attempt released the units
        app(PromotionService::class)->reclaimForOrder($order->id);

        // Card orders reach the sellers only now that the payment went through.
        app(SellerOrderNotifier::class)->orderPlaced($order->fresh());

        Log::info("[Stripe Webhook] Order #{$order->order_number} marked as paid.");
    }

    /** Failed / cancelled card payment: the order's flash-sale units go back to the quota. */
    private function handlePaymentFailure(string $intentId): void
    {
        $order = Order::where('stripe_payment_intent_id', $intentId)->first();
        if (!$order || $order->payment_status === 'paid') {
            return;
        }

        $released = app(PromotionService::class)->releaseForOrder($order->id);
        Log::info("[Stripe Webhook] Payment failed for order #{$order->order_number}; released {$released} flash unit(s).");
    }
}