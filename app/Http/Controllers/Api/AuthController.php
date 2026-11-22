<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\ChangePasswordAction;
use App\Actions\Auth\LoginAction;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /**
     * Student app: students and parents, by phone or student code.
     */
    public function studentLogin(LoginRequest $request, LoginAction $login): JsonResponse
    {
        return $this->login($request, $login, [Role::Student, Role::Parent]);
    }

    /**
     * Staff app: the teacher and assistants, by phone only.
     */
    public function staffLogin(LoginRequest $request, LoginAction $login): JsonResponse
    {
        return $this->login($request, $login, [Role::Teacher, Role::Assistant]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        activity('auth')
            ->causedBy($user)
            ->performedOn($user)
            ->event('logout')
            ->withProperties(['ip' => $request->ip()])
            ->log('logout');

        return response()->json(['message' => 'تم تسجيل الخروج.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->profile($request->user()));
    }

    public function changePassword(ChangePasswordRequest $request, ChangePasswordAction $changePassword): JsonResponse
    {
        $changePassword->handle($request->user(), $request->string('password')->toString());

        return response()->json([
            'message' => 'تم تغيير كلمة المرور.',
            ...$this->profile($request->user()->refresh()),
        ]);
    }

    /**
     * @param  list<Role>  $allowedRoles
     */
    private function login(LoginRequest $request, LoginAction $login, array $allowedRoles): JsonResponse
    {
        ['token' => $token, 'user' => $user] = $login->handle(
            $request->string('identifier')->toString(),
            $request->string('password')->toString(),
            $request->string('device_name')->toString(),
            $allowedRoles,
            $request->ip(),
        );

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            ...$this->profile($user),
        ]);
    }

    /**
     * @return array{user: UserResource, roles: list<string>, permissions: list<string>, must_change_password: bool}
     */
    private function profile(User $user): array
    {
        $user->loadMissing(['student', 'children']);

        return [
            'user' => UserResource::make($user),
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->permissionNames(),
            'must_change_password' => $user->must_change_password,
        ];
    }
}
