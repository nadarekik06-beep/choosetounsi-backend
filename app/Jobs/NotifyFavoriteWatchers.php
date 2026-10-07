<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\User;
use App\Notifications\Buyer\FavoriteProductNotification;
use App\Services\Notifications\BuyerNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * A favourited product got cheaper or came back in stock: tell the buyers who
 * favourited it (promotions — their preferences, the daily cap and a per-day
 * dedupe apply in BuyerNotifier). Dispatched by the product / variant observers.
 */
class NotifyFavoriteWatchers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 3;

    /** @param string $event price_drop | back_in_stock */
    public function __construct(
        public int $productId,
        public string $event,
        public ?int $variantId = null,
        public ?float $oldPrice = null,
        public ?float $newPrice = null,
    ) {
        $this->onQueue(config('notifications.queue'));
        $this->afterCommit = true;
    }

    public function handle(BuyerNotifier $notifier): void
    {
        $product = Product::find($this->productId);
        if (!$product || !$product->is_active || !$product->is_approved) return;

        $userIds = DB::table('favorites')
            ->where('product_id', $product->id)
            // A variant back in stock matters to whoever favourited that variant or the whole product.
            ->when($this->variantId, fn ($q) => $q->where(fn ($w) => $w->whereNull('variant_id')->orWhere('variant_id', $this->variantId)))
            ->where('user_id', '!=', (int) $product->seller_id)
            ->distinct()
            ->pluck('user_id');

        User::whereIn('id', $userIds)->where('is_active', true)->chunkById(200, function ($users) use ($notifier, $product) {
            foreach ($users as $user) {
                $notifier->send($user, new FavoriteProductNotification($product, $this->event, $this->oldPrice, $this->newPrice));
            }
        });
    }
}
