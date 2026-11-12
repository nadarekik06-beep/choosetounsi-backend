<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Proof of payment of a settlement batch (bank transfer receipt, D17 screenshot).
 *
 * Files contain bank details: they live on the private disk (storage/app/private/
 * settlement-proofs), under a random name, and are only served by authenticated
 * endpoints that check the admin role or the owning seller.
 */
class SettlementProof
{
    public const DISK    = 'private';
    public const DIR     = 'settlement-proofs';
    public const MAX_KB  = 5120;
    public const METHODS = ['bank_transfer', 'd17', 'cash'];

    /** real (content-detected) MIME type => stored extension */
    public const MIMES = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];

    /** Validation rules shared by create / confirm / payment-proof endpoints. */
    public static function rules(): array
    {
        return [
            'payment_method'    => 'nullable|string|in:' . implode(',', self::METHODS),
            'payment_reference' => 'nullable|string|max:120',
            'payment_date'      => 'nullable|date',
            'proof'             => [
                'nullable', 'file', 'max:' . self::MAX_KB,
                // The REAL type (read from the file content), never the client's name or header
                function ($attribute, $value, $fail) {
                    if ($value instanceof UploadedFile && !isset(self::MIMES[(string) $value->getMimeType()])) {
                        $fail('The proof must be a JPG, PNG, WEBP or PDF file.');
                    }
                },
            ],
        ];
    }

    /** Stores the file under a random name; returns the columns to save. */
    public static function store(UploadedFile $file, int $adminId): array
    {
        $mime = (string) $file->getMimeType();
        $name = Str::random(40) . '.' . self::MIMES[$mime];
        Storage::disk(self::DISK)->putFileAs(self::DIR, $file, $name);

        return [
            'proof_path'          => self::DIR . '/' . $name,
            'proof_original_name' => Str::limit(preg_replace('/[^\pL\pN._ -]/u', '_', $file->getClientOriginalName()), 120, ''),
            'proof_mime'          => $mime,
            'proof_size'          => (int) $file->getSize(),
            'proof_uploaded_by'   => $adminId,
            'proof_uploaded_at'   => now(),
        ];
    }

    public static function delete(?string $path): void
    {
        if ($path && str_starts_with($path, self::DIR . '/')) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /** Columns to null out when the proof is removed. */
    public static function emptyColumns(): array
    {
        return [
            'proof_path' => null, 'proof_original_name' => null, 'proof_mime' => null,
            'proof_size' => null, 'proof_uploaded_by' => null, 'proof_uploaded_at' => null,
        ];
    }

    /** Payment columns taken from the request (only the ones it sent). */
    public static function paymentFields(Request $request): array
    {
        $out = [];
        foreach (['payment_method', 'payment_reference', 'payment_date'] as $k) {
            if ($request->has($k)) {
                $out[$k] = $request->input($k) !== '' ? $request->input($k) : null;
            }
        }
        return $out;
    }

    /** Batch row as sent to the API: the storage path never leaves the server. */
    public static function present(object $batch, bool $forAdmin): object
    {
        $b = clone $batch;
        $b->has_proof = !empty($b->proof_path ?? null);
        unset($b->proof_path);
        if (!$forAdmin) {
            unset($b->proof_uploaded_by, $b->proof_original_name, $b->proof_size);
        }
        if (!$b->has_proof) {
            $b->proof_mime = null;
        }
        return $b;
    }

    /** Streams the file (inline), private, never cached, never sniffed. */
    public static function response(object $batch): StreamedResponse
    {
        $disk = Storage::disk(self::DISK);
        abort_unless($batch->proof_path && $disk->exists($batch->proof_path), 404, 'Proof not found.');

        $ext = self::MIMES[$batch->proof_mime] ?? 'bin';
        return $disk->response($batch->proof_path, 'receipt-' . $batch->batch_reference . '.' . $ext, [
            'Content-Type'           => $batch->proof_mime,
            'Content-Disposition'    => 'inline; filename="receipt-' . $batch->batch_reference . '.' . $ext . '"',
            'Cache-Control'          => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
