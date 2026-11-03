<?php

namespace App\Services\Intigo;

use Illuminate\Support\Facades\Http;

/**
 * Live bordereau: GET {host}/secure-api/bordereaux/{nid}, which answers the PDF.
 * The API key goes in a header (config intigo.bordereau.auth_header), never in
 * the URL. Intigo's other bordereau endpoints are never used: /api/v3/parcels/bordereau
 * returns a link carrying the key, /api/v1/externalapi/bordereau needs no key at all.
 *
 * intigo_logs gets the status, type and size only, never the PDF (buyer data).
 */
class HttpBordereauClient implements BordereauClient
{
    public function __construct(private IntigoLogger $logger) {}

    public function mode(): string
    {
        return 'live';
    }

    public function pdf(string $nid, ?int $sellerOrderId = null): string
    {
        $r = $this->fetch($nid, $sellerOrderId);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            throw IntigoException::fromResponse($r['status'], $r['error_body']);
        }
        if (!$r['is_pdf']) {
            throw new IntigoException('Intigo did not return a PDF (' . ($r['content_type'] ?: 'no content type') . ')', $r['status'], false, 'server');
        }
        return $r['body'];
    }

    /**
     * One request, nothing kept on disk. Also used by intigo:bordereau-check.
     *
     * @return array{status:int, content_type:string, bytes:int, is_pdf:bool, body:string, error_body:mixed}
     * @throws IntigoException no key, or no answer (timeout, connection)
     */
    public function fetch(string $nid, ?int $sellerOrderId = null, ?string $authHeader = null): array
    {
        $path = '/secure-api/bordereaux/' . rawurlencode($nid);
        $key  = config('intigo.api_key');
        if (!is_string($key) || trim($key) === '') {
            $e = new IntigoException('INTIGO_API_KEY is not set', null, false, 'auth');
            $this->logger->log('live', 'GET', $path, null, null, null, 0, $sellerOrderId, $e->getMessage());
            throw $e;
        }

        $header  = $authHeader ?: (string) config('intigo.bordereau.auth_header', 'apikey');
        $started = microtime(true);
        try {
            $response = Http::withHeaders([$header => $key, 'Accept' => 'application/pdf'])
                ->timeout((int) config('intigo.bordereau.timeout', 30))
                ->withOptions(['connect_timeout' => config('intigo.http.connect_timeout', 8)])
                ->get(self::host() . $path);
        } catch (\Throwable $e) {
            $ex = IntigoException::connection($e->getMessage());
            $this->logger->log('live', 'GET', $path, null, null, null, $this->ms($started), $sellerOrderId, $ex->getMessage());
            throw $ex;
        }

        $body   = $response->body();
        $type   = (string) $response->header('Content-Type');
        $isPdf  = str_starts_with($body, '%PDF');
        $status = $response->status();
        // Error answers are short JSON/text messages; a PDF is never logged
        $errorBody = $isPdf ? null : ($response->json() ?? mb_substr(strip_tags($body), 0, 500));
        $error = null;
        if (!$response->successful()) {
            $error = IntigoException::fromResponse($status, $errorBody)->getMessage();
        } elseif (!$isPdf) {
            $error = 'Not a PDF';
        }
        $this->logger->log('live', 'GET', $path, null, $status,
            $isPdf ? ['content_type' => $type, 'bytes' => strlen($body)] : $errorBody,
            $this->ms($started), $sellerOrderId, $error);

        return ['status' => $status, 'content_type' => $type, 'bytes' => strlen($body), 'is_pdf' => $isPdf, 'body' => $body, 'error_body' => $errorBody];
    }

    /** https://api.intigo.net (the V3 base URL without /api/v3). */
    public static function host(): string
    {
        return preg_replace('#/api/v\d+$#', '', rtrim((string) config('intigo.base_url'), '/'));
    }

    private function ms(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
