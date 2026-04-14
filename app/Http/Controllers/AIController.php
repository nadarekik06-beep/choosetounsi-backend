<?php
// app/Http/Controllers/AIController.php  ← REPLACE EXISTING

namespace App\Http\Controllers;

use App\Models\SellerApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * General AI proxy controller.
 *
 * FIX: The original checked $user->active_plan which doesn't exist on the
 * User model. The active plan lives on seller_applications.plan.
 * This version queries SellerApplication correctly.
 */
class AIController extends Controller
{
    private string $groqApiKey;
    private string $groqModel;
    private string $groqApiUrl = 'https://api.groq.com/openai/v1/chat/completions';

    public function __construct()
    {
        $this->groqApiKey = config('services.groq.key', '');
        $this->groqModel  = config('services.groq.model', 'llama3-8b-8192');
    }

    /**
     * Proxy AI requests from the seller frontend to Groq.
     * Only accessible to sellers with plan = 'red' or 'black'.
     *
     * Route: POST /api/ai/groq  (already in api.php)
     */
    public function proxy(Request $request)
    {
        $user = $request->user();

        // ── Plan gate (FIXED) ──────────────────────────────────────────────
        // The active plan lives on seller_applications.plan, NOT on the User model.
        $application = SellerApplication::where('user_id', $user->id)
            ->where('status', 'approved')
            ->first();

        $plan = $application?->plan ?? 'free';

        if (!in_array($plan, ['red', 'black'])) {
            return response()->json([
                'success' => false,
                'error'   => 'AI tools require Red Pepper or Black Pepper subscription.',
                'code'    => 'PLAN_REQUIRED',
            ], 403);
        }

        $request->validate([
            'systemPrompt' => 'nullable|string|max:2000',
            'userPrompt'   => 'required|string|max:3000',
            'maxTokens'    => 'nullable|integer|min:100|max:1500',
        ]);

        if (empty($this->groqApiKey)) {
            Log::error('[AIController] GROQ_API_KEY is not configured.');
            return response()->json(['error' => 'AI service not configured.'], 500);
        }

        $messages = [];

        if ($request->filled('systemPrompt')) {
            $messages[] = ['role' => 'system', 'content' => $request->systemPrompt];
        }

        $messages[] = ['role' => 'user', 'content' => $request->userPrompt];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->groqApiKey,
                'Content-Type'  => 'application/json',
            ])->timeout(30)->post($this->groqApiUrl, [
                'model'       => $this->groqModel,
                'messages'    => $messages,
                'max_tokens'  => $request->input('maxTokens', 600),
                'temperature' => 0.3,
            ]);

            if (!$response->successful()) {
                Log::warning('[AIController] Groq API error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return response()->json(['error' => 'AI service temporarily unavailable.'], 502);
            }

            $result = $response->json('choices.0.message.content', '');
            return response()->json(['result' => $result]);

        } catch (\Exception $e) {
            Log::error('[AIController] Exception: ' . $e->getMessage());
            return response()->json(['error' => 'AI request failed.'], 500);
        }
    }
}