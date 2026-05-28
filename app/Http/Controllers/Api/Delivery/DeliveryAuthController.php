<?php
// app/Http/Controllers/Api/Delivery/DeliveryAuthController.php

namespace App\Http\Controllers\Api\Delivery;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeliveryRegisterRequest;
use App\Models\DeliveryCompanyProfile;
use App\Models\DeliveryGuyProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class DeliveryAuthController extends Controller
{
    /**
     * POST /api/delivery/register
     *
     * Registers a new delivery_admin (company) or delivery_guy.
     * No approval flow — token is returned immediately.
     */
    public function register(DeliveryRegisterRequest $request): JsonResponse
    {
        $data  = $request->validated();
        $user  = null;
        $token = null;

        try {
            DB::transaction(function () use ($data, &$user, &$token) {
                // 1. Create base user record
                $user = User::create([
                    'name'      => $data['name'],
                    'email'     => $data['email'],
                    'password'  => Hash::make($data['password']),
                    'role'      => $data['role'],
                    'is_active' => true,
                    'email_verified_at' => now(), // ✅ delivery accounts skip email verification

                ]);

                // 2. Create role-specific profile
                if ($data['role'] === 'delivery_admin') {
                    DeliveryCompanyProfile::create([
                        'user_id'             => $user->id,
                        'company_name'        => $data['company_name'],
                        'phone'               => $data['phone'],
                        'address'             => $data['address'],
                        'wilaya'              => $data['wilaya']              ?? null,
                        'city'                => $data['city']                ?? null,
                        'website'             => $data['website']             ?? null,
                        'registration_number' => $data['registration_number'] ?? null,
                        'description'         => $data['description']         ?? null,
                    ]);
                } else {
                    DeliveryGuyProfile::create([
                        'user_id'        => $user->id,
                        'phone'          => $data['phone'],
                        'wilaya'         => $data['wilaya']         ?? null,
                        'city'           => $data['city']           ?? null,
                        'vehicle_type'   => $data['vehicle_type']   ?? 'moto',
                        'vehicle_plate'  => $data['vehicle_plate']  ?? null,
                        'id_card_number' => $data['id_card_number'] ?? null,
                        'is_available'   => true,
                    ]);
                }

                // 3. Issue Sanctum token
                $token = $user->createToken('delivery-app')->plainTextToken;
            });

        } catch (\Throwable $e) {
            Log::error('[DeliveryAuthController::register] ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Registration failed. Please try again.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Account created successfully.',
            'data'    => [
                'token' => $token,
                'user'  => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    'role'  => $user->role,
                ],
            ],
        ], 201);
    }
}