<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->validated());
        $user->refresh();

        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');
        $guard->login($user);
        $request->session()->regenerate();
        $request->session()->put('password_hash_web', $guard->hashPasswordForCookie($guard->user()->getAuthPassword()));

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function login(LoginRequest $request): UserResource
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');
        if (! $guard->attempt($request->validated())) {
            $exception = ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
            $exception->response = response()->json([
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
                'code' => 'invalid_credentials',
            ], $exception->status);

            throw $exception;
        }

        $request->session()->regenerate();
        $request->session()->put('password_hash_web', $guard->hashPasswordForCookie($guard->user()->getAuthPassword()));

        return new UserResource($guard->user());
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
