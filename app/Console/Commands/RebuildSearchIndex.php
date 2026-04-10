<?php
// app/Console/Commands/RebuildSearchIndex.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Artisan command to rebuild the AI search index.
 *
 * Usage:
 *   php artisan search:rebuild
 *
 * What it does:
 *   1. Calls POST http://localhost:8001/index/rebuild
 *   2. The Python service pulls all active products from MySQL
 *   3. Generates text + image embeddings
 *   4. Saves FAISS indexes to disk
 *   5. Reloads indexes into memory
 *
 * Run this:
 *   - After adding/approving many new products
 *   - After bulk imports
 *   - As a nightly scheduled job (optional)
 *
 * Time estimate:
 *   - 100  products:  ~15-30 seconds
 *   - 1000 products:  ~2-3 minutes
 *   - 10000 products: ~20-25 minutes
 */
class RebuildSearchIndex extends Command
{
    protected $signature   = 'search:rebuild';
    protected $description = 'Rebuild the AI search index (text + image embeddings)';

    public function handle(): int
    {
        $aiUrl = config('services.ai.url', 'http://localhost:8001');

        $this->info('');
        $this->info('ChooseTounsi AI Search Index Rebuild');
        $this->info('====================================');
        $this->info("AI Service: {$aiUrl}");
        $this->info('');

        // ── Step 1: Check if AI service is running ────────────────────────
        $this->info('Checking AI service health...');

        try {
            $health = Http::timeout(5)->get("{$aiUrl}/health");

            if (!$health->successful()) {
                $this->error("AI service returned status: " . $health->status());
                $this->error("Make sure the Python service is running:");
                $this->error("  cd C:\\xampp\\htdocs\\choosetounsi-ai");
                $this->error("  python main.py");
                return Command::FAILURE;
            }

            $this->line("  ✅ AI service is running.");

        } catch (\Exception $e) {
            $this->error("Cannot connect to AI service at {$aiUrl}");
            $this->error("Error: " . $e->getMessage());
            $this->newLine();
            $this->error("Make sure the Python service is running:");
            $this->error("  cd C:\\xampp\\htdocs\\choosetounsi-ai");
            $this->error("  python main.py");
            return Command::FAILURE;
        }

        // ── Step 2: Trigger rebuild ───────────────────────────────────────
        $this->info('Starting index rebuild (this may take several minutes)...');
        $this->info('Watch the Python service terminal for progress.');
        $this->newLine();

        try {
            // Long timeout — rebuilding 10k products can take 20+ minutes
            $response = Http::timeout(1800)->post("{$aiUrl}/index/rebuild");

            if ($response->successful()) {
                $data = $response->json();

                $this->info('✅ Index rebuild complete!');
                $this->newLine();
                $this->table(
                    ['Metric', 'Value'],
                    [
                        ['Total products',  $data['total_products'] ?? 'N/A'],
                        ['Text vectors',    $data['text_vectors']   ?? 'N/A'],
                        ['Image vectors',   $data['image_vectors']  ?? 'N/A'],
                        ['Time taken',      ($data['elapsed_sec']   ?? 'N/A') . 's'],
                    ]
                );

                return Command::SUCCESS;
            }

            $this->error('Rebuild request failed: ' . $response->status());
            $this->error($response->body());
            return Command::FAILURE;

        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'cURL error 28')) {
                $this->error('The rebuild timed out (took too long).');
                $this->error('This is normal for large datasets.');
                $this->error('The rebuild is still running in the background.');
                $this->info('Wait for the Python service to finish, then search will work.');
            } else {
                $this->error('Rebuild failed: ' . $e->getMessage());
            }
            return Command::FAILURE;
        }
    }
}