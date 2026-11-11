<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Email + password accounts with Sanctum bearer tokens (D7, D18, D33).
 *
 * A token is created per sign-in, so each browser or device has its own and
 * logging out on one leaves the others signed in. Sanctum stores only a
 * SHA-256 hash of it; the plain token ("{id}|{random}") is returned once here.
 */
class AuthController extends Controller
{
    /** Name stored with each token (personal_access_tokens.name). */
    private const TOKEN_NAME = 'spa';

    /** POST /api/auth/register: creates the account and signs it in. */
    public function register(RegisterRequest $request): JsonResponse
    {
        // The model casts password as 'hashed', so create() stores a bcrypt hash.
        $user = User::create($request->validated());

        return $this->tokenResponse($user, 201);
    }

    /** POST /api/auth/login */
    public function login(LoginRequest $request): JsonResponse
    {
        return $this->tokenResponse($request->authenticate(), 200);
    }

    /**
     * POST /api/auth/logout: deletes the token used for this request, so it
     * stops working immediately (D18). Other devices stay signed in.
     */
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    /** GET /api/auth/me: who the bearer token belongs to. */
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    private function tokenResponse(User $user, int $status): JsonResponse
    {
        return response()->json([
            'data' => [
                'token' => $user->createToken(self::TOKEN_NAME)->plainTextToken,
                'user' => new UserResource($user),
            ],
        ], $status);
    }
}
