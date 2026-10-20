<?php

namespace App\Console\Commands\Intigo;

use App\Services\Intigo\HttpIntigoClient;
use App\Services\Intigo\IntigoException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Read-only live check of the Intigo setup: the public regions endpoint and
 * the account's pickup addresses (validates INTIGO_API_KEY). Never creates,
 * changes or cancels a parcel, whatever INTIGO_MODE says.
 */
class Ping extends Command
{
    protected $signature = 'intigo:ping';

    protected $description = 'Read-only check of the Intigo API (regions + pickup addresses). Never creates parcels.';

    public function handle(HttpIntigoClient $live): int
    {
        $this->line('Mode: <info>' . config('intigo.mode') . '</info>' . (config('intigo.mode') === 'fake' ? ' (parcels are faked; this check still calls the real API, read-only)' : ''));
        $this->line('Base URL: ' . config('intigo.base_url'));
        $this->line('API key: ' . (filled(config('intigo.api_key')) ? 'set' : '<error>missing</error>'));
        $this->line('Webhook secret: ' . (filled(config('services.intigo.webhook_secret')) ? 'set' : '<comment>missing (webhooks will be refused with 401)</comment>'));
        $ok = true;

        // 1. Public regions
        try {
            $res = Http::acceptJson()->timeout(15)->get(config('intigo.base_url') . '/regions/cities');
            $count = count($res->json('cities') ?? []);
            $res->successful() && $count > 0
                ? $this->info("✓ Regions: {$count} governorates")
                : $this->error('✗ Regions: HTTP ' . $res->status());
            $ok = $ok && $res->successful();
        } catch (\Throwable $e) {
            $this->error('✗ Regions: ' . $e->getMessage());
            $ok = false;
        }

        // 2. Pickup addresses (needs the key)
        try {
            $addresses = $live->pickupAddresses();
            $this->info('✓ API key accepted: ' . count($addresses) . ' pickup address(es)');
            foreach ($addresses as $a) {
                $this->line("    index {$a['index']}: {$a['label']}");
            }
        } catch (IntigoException $e) {
            $this->error('✗ Pickup addresses: ' . $e->getMessage());
            $ok = false;
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
