<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\EmailVerificationNotificationRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function send(EmailVerificationNotificationRequest $request, EmailVerificationService $service): JsonResponse
    {
        $sent = $service->send($request->user());

        return response()->json(['message' => $sent
            ? 'Лист для підтвердження email-адреси надіслано.' : 'Email-адресу вже підтверджено.']);
    }

    public function confirm(Request $request, EmailVerificationService $service): UserResource
    {
        return (new UserResource($service->confirm($request->user(), $request)))
            ->additional(['message' => 'Email-адресу підтверджено.']);
    }
}
