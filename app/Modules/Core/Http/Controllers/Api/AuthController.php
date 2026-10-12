<?php

namespace App\Modules\Core\Http\Controllers\Api;

use App\Modules\Core\Actions\AuthenticateUser;
use App\Modules\Core\Http\Requests\ApiLoginRequest;
use App\Modules\Core\Http\Resources\UserResource;
use App\Modules\Core\Models\MobileDevice;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\MfaService;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(ApiLoginRequest $request, AuthenticateUser $authenticate): JsonResponse
    {
        $user = $authenticate($request->validated('email'), $request->validated('password'), $request->validated('mfa_code'));

        $expiresAt = config('sanctum.expiration') ? now()->addMinutes((int) config('sanctum.expiration')) : null;
        $token = $user->createToken($request->validated('device_name'), ['*'], $expiresAt);

        return response()->json(['data' => [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt?->toIso8601String(),
            'user' => new UserResource($user),
        ]]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        MobileDevice::query()->where('user_id', $request->user()->id)->where('token_id', $token->id)->delete();      // signed out: no more push messages to this phone
        $token->delete();

        return response()->json(null, 204);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function mfaSetup(Request $request, MfaService $mfa): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasMfaEnabled()) {
            throw ValidationException::withMessages(['mfa' => [__('core::auth.mfa_already_enabled')]]);
        }

        $setup = $mfa->begin($user);

        return response()->json(['data' => ['secret' => $setup['secret'], 'otpauth_uri' => $setup['uri']]]);
    }

    public function mfaConfirm(Request $request, MfaService $mfa): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);

        $recoveryCodes = $mfa->confirm($request->user(), $data['code']);
        if ($recoveryCodes === null) {
            throw ValidationException::withMessages(['code' => [__('core::auth.mfa_code_invalid')]]);
        }

        return response()->json(['data' => ['recovery_codes' => $recoveryCodes]]);
    }
}
