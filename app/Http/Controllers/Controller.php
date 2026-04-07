<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    /**
     * Reject the request if the authenticated user owns the product.
     *
     * A seller must never purchase, cart, or favorite their own product.
     * This is the single source of truth for that rule — called from
     * CartController, FavoriteController, and CheckoutController.
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException (403)
     */
    protected function ensureNotProductOwner(\Illuminate\Http\Request $request, Product $product): void
    {
        if ($request->user() && $request->user()->id === $product->seller_id) {
            abort(response()->json([
                'success' => false,
                'message' => 'You cannot purchase or save your own product.',
                'code'    => 'OWN_PRODUCT',
            ], 403));
        }
    }
}