<?php

namespace App\Http\Middleware;

use App\Support\StoreCards;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Storefront product payloads: each product's `seller` becomes the public store
 * block (store name + logo, see App\Support\StoreCards) in one place, whatever
 * controller or service built the cards. Only on the buyer-facing routes listed
 * under the "store.cards" alias in routes/api.php.
 */
class StoreBrandedCards
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if ($response instanceof JsonResponse && $response->isSuccessful()) {
            $response->setData(StoreCards::brandPayload($response->getData()));
        }

        return $response;
    }
}
