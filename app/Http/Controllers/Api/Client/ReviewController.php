<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\{Review, ReviewMedia, ReviewTag, ReviewVote, ReviewReport, ReviewPrompt, OrderItem, SellerOrder, Product};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Storage, Log};
use Illuminate\Validation\Rule;

class ReviewController extends Controller
{
    // ── GET /api/client/reviews/eligible ─────────────────────────────────────
    /**
     * Returns order items the authenticated user can review.
     * Conditions:
     *   1. seller_order.status = 'delivered'
     *   2. No review already submitted for that order_item
     */
    public function eligible(Request $request)
    {
        try {
            $user = $request->user();

            // Already-reviewed order_item IDs
            $reviewed = Review::where('user_id', $user->id)
                ->pluck('order_item_id')
                ->toArray();

            $items = OrderItem::with(['product.primaryImage', 'product.images', 'sellerOrder'])
                ->whereHas('sellerOrder', fn($q) => $q->where('status', 'delivered'))
                ->whereHas('order', fn($q) => $q->where('user_id', $user->id))
                ->when(!empty($reviewed), fn($q) => $q->whereNotIn('id', $reviewed))
                ->latest()
                ->get()
                ->map(fn($item) => [
                    'order_item_id' => $item->id,
                    'order_id'      => $item->order_id,
                    'product_id'    => $item->product_id,
                    'product_name'  => $item->product_name ?? $item->product?->name,
                    'product_image' => $item->product?->primary_image_url,
                    'variant_label' => $item->variant_label,
                    'quantity'      => $item->quantity,
                    'delivered_at'  => $item->sellerOrder?->updated_at,
                ]);

            return response()->json(['success' => true, 'data' => $items]);

        } catch (\Exception $e) {
            Log::error('[ReviewController::eligible] ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ── GET /api/client/reviews/tags ─────────────────────────────────────────
    public function tags()
    {
        $tags = ReviewTag::active()->get(['id', 'label', 'label_fr', 'sentiment', 'icon']);
        return response()->json(['success' => true, 'data' => $tags]);
    }

    // ── POST /api/client/reviews ──────────────────────────────────────────────
    /**
     * Submit a new review.
     * - Validates that the order_item belongs to the user and is delivered
     * - Prevents duplicate reviews per order_item
     * - Handles media upload (up to 6 images)
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'order_item_id' => ['required', 'integer', 'exists:order_items,id'],
            'rating'        => ['required', 'integer', 'min:1', 'max:5'],
            'body'          => ['nullable', 'string', 'min:10', 'max:2000'],
            'tag_ids'       => ['nullable', 'array', 'max:6'],
            'tag_ids.*'     => ['integer', 'exists:review_tags,id'],
            'is_anonymous'  => ['boolean'],
            'images'        => ['nullable', 'array', 'max:6'],
            'images.*'      => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'], // 5MB each
        ]);

        // ── 1. Security: verify order_item belongs to this user ───────────
        $orderItem = OrderItem::with('sellerOrder')
            ->whereHas('order', fn($q) => $q->where('user_id', $user->id))
            ->findOrFail($data['order_item_id']);

        // ── 2. Verify delivered ───────────────────────────────────────────
        if (!$orderItem->sellerOrder || $orderItem->sellerOrder->status !== 'delivered') {
            return response()->json([
                'success' => false,
                'message' => 'You can only review products from delivered orders.',
            ], 422);
        }

        // ── 3. Check not already reviewed ────────────────────────────────
        $exists = Review::where('user_id', $user->id)
            ->where('order_item_id', $orderItem->id)
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'You have already reviewed this product.',
            ], 409);
        }

        // ── 4. Get product & seller ───────────────────────────────────────
        $product = Product::findOrFail($orderItem->product_id);

        DB::beginTransaction();
        try {
            // ── 5. Create review ──────────────────────────────────────────
            $review = Review::create([
                'user_id'              => $user->id,
                'product_id'           => $product->id,
                'order_item_id'        => $orderItem->id,
                'seller_id'            => $product->seller_id,
                'rating'               => $data['rating'],
                'body'                 => $data['body'] ?? null,
                'is_anonymous'         => $data['is_anonymous'] ?? false,
                'is_verified_purchase' => true,
                'status'               => 'approved',
            ]);

            // ── 6. Attach tags ────────────────────────────────────────────
            if (!empty($data['tag_ids'])) {
                $review->tags()->sync($data['tag_ids']);
            }

            // ── 7. Upload images ──────────────────────────────────────────
            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $index => $file) {
                    $path = $file->store('reviews/' . $review->id, 'public');
                    ReviewMedia::create([
                        'review_id'  => $review->id,
                        'path'       => $path,
                        'type'       => 'image',
                        'sort_order' => $index,
                        'is_approved'=> true,
                    ]);
                }
            }

            // ── 8. Mark review prompt as completed ────────────────────────
            ReviewPrompt::where('user_id', $user->id)
                ->where('order_item_id', $orderItem->id)
                ->update(['reviewed_at' => now()]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Review submitted successfully!',
                'data'    => ['review_id' => $review->id],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[ReviewController::store] ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to submit review.'], 500);
        }
    }

    // ── POST /api/reviews/{id}/vote ───────────────────────────────────────────
    /**
     * Toggle helpful / not_helpful vote.
     * If the user votes the same type again → remove vote (toggle off).
     */
    public function vote(Request $request, int $id)
    {
        $user = $request->user();
        $data = $request->validate([
            'type' => ['required', Rule::in(['helpful', 'not_helpful'])],
        ]);

        $review    = Review::approved()->findOrFail($id);
        $existing  = ReviewVote::where('review_id', $id)->where('user_id', $user->id)->first();

        if ($existing) {
            if ($existing->type === $data['type']) {
                // Same vote → toggle off
                $existing->delete();
            } else {
                // Different vote → update
                $existing->update(['type' => $data['type']]);
            }
        } else {
            ReviewVote::create(['review_id' => $id, 'user_id' => $user->id, 'type' => $data['type']]);
        }

        $review->recalculateVoteCounts();

        return response()->json([
            'success'          => true,
            'helpful_count'    => $review->fresh()->helpful_count,
            'not_helpful_count'=> $review->fresh()->not_helpful_count,
        ]);
    }

    // ── POST /api/reviews/{id}/report ─────────────────────────────────────────
    public function report(Request $request, int $id)
    {
        $user = $request->user();
        $data = $request->validate([
            'reason' => ['required', Rule::in(['spam', 'fake', 'inappropriate', 'offensive', 'other'])],
            'note'   => ['nullable', 'string', 'max:500'],
        ]);

        $exists = ReviewReport::where('review_id', $id)->where('reported_by', $user->id)->exists();
        if ($exists) {
            return response()->json(['success' => false, 'message' => 'Already reported.'], 409);
        }

        ReviewReport::create([
            'review_id'   => $id,
            'reported_by' => $user->id,
            'reason'      => $data['reason'],
            'note'        => $data['note'] ?? null,
            'status'      => 'pending',
        ]);

        return response()->json(['success' => true, 'message' => 'Review reported.']);
    }

    // ── GET /api/client/reviews/prompts ──────────────────────────────────────
    /**
     * Returns pending review prompts for the storefront popup.
     * Only items where no review has been submitted yet.
     */
    public function pendingPrompts(Request $request)
    {
        $user = $request->user();

        $prompts = ReviewPrompt::with(['product.primaryImage'])
            ->where('user_id', $user->id)
            ->whereNull('reviewed_at')
            ->whereNull('dismissed_at')
            ->latest()
            ->take(3)
            ->get()
            ->map(fn($p) => [
                'prompt_id'    => $p->id,
                'product_id'   => $p->product_id,
                'product_name' => $p->product?->name,
                'product_image'=> $p->product?->primary_image_url,
                'order_item_id'=> $p->order_item_id,
                'sent_at'      => $p->sent_at,
            ]);

        return response()->json(['success' => true, 'data' => $prompts]);
    }

    // ── POST /api/client/reviews/prompts/{id}/dismiss ────────────────────────
    public function dismissPrompt(Request $request, int $id)
    {
        ReviewPrompt::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->update(['dismissed_at' => now()]);

        return response()->json(['success' => true]);
    }
}