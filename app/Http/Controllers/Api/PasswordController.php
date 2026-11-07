<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\Auth\PasswordManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class PasswordController extends Controller
{
    public function change(ChangePasswordRequest $request, PasswordManagementService $service): JsonResponse
    {
        $user = $service->change($request->user(), $request->validated(), $request->session()->getId());
        $guard = Auth::guard('web');
        $guard->setUser($user);
        $request->session()->regenerate(true);
        $request->session()->put('password_hash_web', $guard->hashPasswordForCookie($user->getAuthPassword()));

        return response()->json(['message' => 'Password changed successfully.']);
    }

    public function forgot(ForgotPasswordRequest $request, PasswordManagementService $service): JsonResponse
    {
        $service->requestReset($request->validated('email'));

        return response()->json(['message' => 'If the email address is eligible, you will receive a password reset link.'], 202);
    }

    public function reset(ResetPasswordRequest $request, PasswordManagementService $service): JsonResponse
    {
        $user = $service->reset($request->validated());
        $guard = Auth::guard('web');
        if ($request->hasSession() && (string) $guard->id() === (string) $user->getKey()) {
            $guard->logoutCurrentDevice();
            Auth::guard('sanctum')->forgetUser();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Password reset successfully. Sign in with your new password.']);
    }
}
