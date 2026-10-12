<?php

namespace App\Console\Commands\Intigo;

use App\Http\Controllers\Webhooks\IntigoWebhookController;
use App\Services\Intigo\IntigoStatusMap;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Sends a correctly signed parcel.status_changed webhook to our own route, to
 * test every status path locally (fake mode):
 *
 *   php artisan intigo:simulate-webhook FAKE-12 5000
 *   php artisan intigo:simulate-webhook FAKE-12 6900 --direct     (no web server needed)
 *
 * Default: POSTs to APP_URL/api/webhooks/intigo (php artisan serve running).
 * --direct: the same request through the HTTP kernel in this process.
 */
class SimulateWebhook extends Command
{
    protected $signature = 'intigo:simulate-webhook {nid} {status : Intigo status code (1000, 2000, 4000, 5000, 6000, 6900, 9000…)}
        {--label= : status label (default: the usual French label)}
        {--url= : webhook URL (default APP_URL/api/webhooks/intigo)}
        {--direct : run the request in-process instead of over HTTP}
        {--bad-signature : sign with a wrong secret (expect 401)}';

    protected $description = 'Send a signed fake Intigo webhook to our own endpoint (local testing)';

    public function handle(): int
    {
        $secret = config('intigo.webhook_secret');
        if (!filled($secret)) {
            $this->error('Set INTIGO_WEBHOOK_SECRET in .env first (any random string for local tests), then php artisan config:clear.');
            return self::FAILURE;
        }

        $nid    = (string) $this->argument('nid');
        $status = (int) $this->argument('status');
        $old    = DB::table('seller_orders')->where('intigo_nid', $nid)->value('intigo_status_code');
        if ($old === null && !DB::table('seller_orders')->where('intigo_nid', $nid)->exists()) {
            $this->warn("No parcel with nid {$nid} here: the webhook will be logged and ignored.");
        }

        $body = json_encode([
            'event'      => 'parcel.status_changed',
            'event_type' => $this->eventType($status),
            'timestamp'  => now()->timezone(config('intigo.timezone'))->format('Y-m-d\TH:i:s.uP'),
            'data'       => [
                'nid'          => $nid,
                'old_status'   => $old !== null ? (int) $old : null,
                'new_status'   => $status,
                'status_label' => $this->option('label') ?: IntigoStatusMap::label($status),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $signature = IntigoWebhookController::sign($body, $this->option('bad-signature') ? $secret . '-wrong' : $secret);
        $headers = [
            'Content-Type'        => 'application/json',
            'X-Webhook-Event'     => 'parcel.status_changed',
            'X-Webhook-Timestamp' => (string) now()->timestamp,
            'X-Webhook-Signature' => $signature,
            'X-Intigo-Simulated'  => '1',
        ];

        $this->line("→ {$nid}: " . ($old ?? '—') . " → {$status}");
        [$code, $answer] = $this->option('direct') ? $this->direct($body, $headers) : $this->overHttp($body, $headers);
        if ($code === null) {
            return self::FAILURE;
        }
        $this->line("← HTTP {$code} {$answer}");

        $so = DB::table('seller_orders')->where('intigo_nid', $nid)->first(['id', 'status', 'intigo_status_code', 'intigo_status_label', 'intigo_sync_status', 'intigo_attention']);
        if ($so) {
            $result = DB::table('intigo_webhook_events')->where('nid', $nid)->where('new_status', $status)->orderByDesc('id')->value('result');
            $this->info("Parcel #{$so->id}: status {$so->status}, Intigo {$so->intigo_status_code} {$so->intigo_status_label}, sync {$so->intigo_sync_status}");
            if ($result) $this->line("Event: {$result}");
            if ($so->intigo_attention) $this->warn("Admin attention: {$so->intigo_attention}");
        }
        return $code >= 200 && $code < 300 ? self::SUCCESS : self::FAILURE;
    }

    private function overHttp(string $body, array $headers): array
    {
        $url = $this->option('url') ?: rtrim(config('app.url'), '/') . '/api/webhooks/intigo';
        try {
            $res = Http::withHeaders($headers)->withBody($body, 'application/json')->timeout(15)->post($url);
        } catch (\Throwable $e) {
            $this->error("Could not reach {$url} ({$e->getMessage()}). Start php artisan serve, or use --direct.");
            return [null, null];
        }
        return [$res->status(), $res->body()];
    }

    private function direct(string $body, array $headers): array
    {
        $server = [];
        foreach ($headers as $k => $v) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        }
        $server['CONTENT_TYPE'] = 'application/json';
        $request = Request::create('/api/webhooks/intigo', 'POST', [], [], [], $server, $body);

        $kernel   = app(HttpKernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);   // runs the after-response processing
        return [$response->getStatusCode(), $response->getContent()];
    }

    private function eventType(int $status): string
    {
        return match (IntigoStatusMap::target($status)) {
            'delivered'          => 'delivered',
            'out_for_delivery'   => 'out_for_delivery',
            'returned_to_seller' => 'returned',
            'refused'            => 'return_in_progress',
            'handed_to_courier'  => 'in_transit',
            'confirmed'          => 'pickup',
            IntigoStatusMap::CARRIER_CANCELLED => 'cancelled',
            default              => 'status_changed',
        };
    }
}
