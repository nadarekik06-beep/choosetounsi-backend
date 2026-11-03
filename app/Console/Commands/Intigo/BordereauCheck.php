<?php

namespace App\Console\Commands\Intigo;

use App\Services\Intigo\HttpBordereauClient;
use App\Services\Intigo\IntigoException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only check of Intigo's bordereau endpoint on one existing parcel:
 * prints the HTTP status, content type, size and whether it is a PDF, plus
 * Intigo's error message when it is not. Never saves or prints the PDF (buyer
 * data), never creates anything. Calls the real API whatever INTIGO_MODE says.
 */
class BordereauCheck extends Command
{
    protected $signature = 'intigo:bordereau-check
        {seller_order? : seller_orders.id (default: the latest real parcel)}
        {--header= : auth header name to try instead of config intigo.bordereau.auth_header}';

    protected $description = 'Read-only: fetch one real Intigo bordereau and report status / type / size / %PDF (nothing saved).';

    public function handle(HttpBordereauClient $live): int
    {
        $q = DB::table('seller_orders')->whereNotNull('intigo_nid')->where('intigo_nid', 'not like', 'FAKE-%');
        $row = $this->argument('seller_order')
            ? $q->where('id', (int) $this->argument('seller_order'))->first(['id', 'intigo_nid'])
            : $q->orderByDesc('intigo_sent_at')->orderByDesc('id')->first(['id', 'intigo_nid']);
        if (!$row) {
            $this->warn('No real Intigo parcel found in seller_orders: nothing to check.');
            return self::FAILURE;
        }

        $header = $this->option('header') ?: config('intigo.bordereau.auth_header', 'apikey');
        $this->line('Mode: ' . config('intigo.mode') . ' (this check always calls the real API, read-only)');
        $this->line("Seller order #{$row->id}, auth header: {$header}");
        try {
            $r = $live->fetch($row->intigo_nid, (int) $row->id, $header);
        } catch (IntigoException $e) {
            $this->error('✗ ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->line('HTTP status:  ' . $r['status']);
        $this->line('Content-Type: ' . ($r['content_type'] ?: '-'));
        $this->line('Size:         ' . $r['bytes'] . ' bytes');
        $this->line('Starts %PDF:  ' . ($r['is_pdf'] ? 'yes' : 'no'));
        if (!$r['is_pdf']) {
            $this->line('Intigo says:  ' . (IntigoException::readable($r['error_body']) ?: '-'));
        }
        $ok = $r['is_pdf'];
        $r  = null;   // the PDF stays in memory only

        $ok ? $this->info('✓ Intigo returns the bordereau PDF') : $this->error('✗ No PDF from Intigo');
        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
