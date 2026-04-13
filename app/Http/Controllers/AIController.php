<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
     * Only accessible to sellers with active_plan = 'red' or 'black'.
     */
    public function proxy(Request $request)
    {
        $user = $request->user();

        // Plan gate — only Red and Black Pepper sellers
        if (!in_array($user->active_plan, ['red', 'black'])) {
            return response()->json([
                'error' => 'AI tools require Red Pepper or Black Pepper subscription.',
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
            $messages[] = [
                'role'    => 'system',
                'content' => $request->systemPrompt,
            ];
        }

        $messages[] = [
            'role'    => 'user',
            'content' => $request->userPrompt,
        ];

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
                return response()->json([
                    'error' => 'AI service temporarily unavailable.',
                ], 502);
            }

            $data   = $response->json();
            $result = $data['choices'][0]['message']['content'] ?? '';

            return response()->json(['result' => $result]);

        } catch (\Exception $e) {
            Log::error('[AIController] Exception: ' . $e->getMessage());
            return response()->json(['error' => 'AI request failed.'], 500);
        }
    }
}