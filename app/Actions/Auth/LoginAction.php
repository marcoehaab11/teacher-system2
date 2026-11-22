<?php

namespace App\Actions\Auth;

use App\Enums\Role;
use App\Enums\StudentStatus;
use App\Exceptions\ApiException;
use App\Models\Student;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LoginAction
{
    /**
     * Authenticate and issue a Sanctum token, for the app that allows $allowedRoles.
     *
     * The identifier is a phone, or a student code when the app allows students.
     * A wrong password and a role from the other app fail the same way, so the
     * endpoint never reveals that an account exists in the other app.
     *
     * @param  list<Role>  $allowedRoles
     * @return array{token: string, user: User}
     */
    public function handle(string $identifier, string $password, string $deviceName, array $allowedRoles, ?string $ip = null): array
    {
        $app = $this->appName($allowedRoles);
        $acceptsStudents = in_array(Role::Student, $allowedRoles, true);
        $user = $this->findUser($identifier, acceptsStudentCode: $acceptsStudents);

        if ($user === null && $acceptsStudents) {
            $this->ensureNotPendingRegistration($identifier, $password, $ip, $app);
        }

        if ($user === null || ! Hash::check($password, $user->password)) {
            $this->logFailure($identifier, $ip, $app, $user === null ? 'unknown_identifier' : 'wrong_password');

            throw ApiException::invalidCredentials();
        }

        if (! $user->hasAnyRole($allowedRoles)) {
            $this->logFailure($identifier, $ip, $app, 'wrong_app');

            throw ApiException::invalidCredentials();
        }

        if (! $user->is_active) {
            activity('auth')
                ->causedBy($user)
                ->performedOn($user)
                ->event('login_blocked')
                ->withProperties(['ip' => $ip, 'app' => $app, 'reason' => 'inactive'])
                ->log('login_blocked');

            throw ApiException::accountInactive();
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        $token = $user->createToken($deviceName)->plainTextToken;

        activity('auth')
            ->causedBy($user)
            ->performedOn($user)
            ->event('login')
            ->withProperties(['ip' => $ip, 'app' => $app, 'device_name' => $deviceName])
            ->log('login');

        return ['token' => $token, 'user' => $user];
    }

    private function findUser(string $identifier, bool $acceptsStudentCode): ?User
    {
        $phone = Phone::normalizeOrNull($identifier);

        if ($phone !== null) {
            return User::where('phone', $phone)->first();
        }

        if (! $acceptsStudentCode) {
            return null;
        }

        return $this->studentsByCode($identifier)->first()?->user;
    }

    /**
     * A self-registered student who is still pending has no account yet. When the
     * identifier and the password they chose match, tell them it is under review
     * instead of "invalid credentials". A wrong password reveals nothing.
     */
    private function ensureNotPendingRegistration(string $identifier, string $password, ?string $ip, string $app): void
    {
        $phone = Phone::normalizeOrNull($identifier);

        $query = $phone !== null
            ? Student::query()->where(fn ($query) => $query
                ->where('registration_data->phone', $phone)
                ->orWhere('registration_data->parent_phone', $phone))
            : $this->studentsByCode($identifier);

        $pending = $query
            ->status(StudentStatus::Pending)
            ->latest('id')
            ->limit(10)
            ->get()
            ->first(function (Student $student) use ($password): bool {
                $hash = $student->registration_data['password_hash'] ?? null;

                return is_string($hash) && Hash::check($password, $hash);
            });

        if ($pending === null) {
            return;
        }

        activity('auth')
            ->performedOn($pending)
            ->event('login_pending')
            ->withProperties(['ip' => $ip, 'app' => $app])
            ->log('login_pending');

        throw ApiException::registrationPending();
    }

    /**
     * @return Builder<Student>
     */
    private function studentsByCode(string $code): Builder
    {
        return Student::query()->whereRaw('LOWER(code) = ?', [Str::lower(trim($code))]);
    }

    private function logFailure(string $identifier, ?string $ip, string $app, string $reason): void
    {
        activity('auth')
            ->event('login_failed')
            ->withProperties(['identifier' => $identifier, 'ip' => $ip, 'app' => $app, 'reason' => $reason])
            ->log('login_failed');
    }

    /**
     * @param  list<Role>  $allowedRoles
     */
    private function appName(array $allowedRoles): string
    {
        foreach ($allowedRoles as $role) {
            if (! $role->isStaff()) {
                return 'student';
            }
        }

        return 'staff';
    }
}
