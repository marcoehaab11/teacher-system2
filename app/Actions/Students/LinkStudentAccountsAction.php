<?php

namespace App\Actions\Students;

use App\Enums\ParentRelation;
use App\Enums\Role;
use App\Models\Student;
use App\Models\User;
use App\Support\Phone;
use App\Support\TempPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Creates/links the login accounts of a student. Must run inside a transaction.
 *
 * - Student account only when the student has their OWN phone.
 * - Parent: an existing parent with that phone is linked, otherwise a parent account is created.
 * - Student phone == parent phone: only the parent account is created/linked.
 * - A chosen password hash (self-registration) goes to the account the registrant
 *   logs in with: the new student account, otherwise a NEW parent account. An
 *   existing parent is only linked and keeps their password.
 */
class LinkStudentAccountsAction
{
    /**
     * Returns the credentials of NEW accounts only (existing parents are just linked).
     * An account that got the chosen password hash has "password" => null.
     *
     * @return list<array{role: string, name: string, phone: string, password: string|null, code?: string|null}>
     */
    public function handle(
        Student $student,
        ?string $studentPhone,
        ?string $parentPhone,
        ?string $parentName = null,
        ?ParentRelation $relation = null,
        ?string $chosenPasswordHash = null,
    ): array {
        $studentPhone = Phone::normalizeOrNull($studentPhone);
        $parentPhone = Phone::normalizeOrNull($parentPhone);
        $chosenPasswordHash = $this->usableHash($chosenPasswordHash);

        if ($studentPhone !== null && $studentPhone === $parentPhone) {
            $studentPhone = null;
        }

        $credentials = [];

        if ($studentPhone !== null) {
            $credentials[] = $this->createStudentAccount($student, $studentPhone, $chosenPasswordHash);
            $chosenPasswordHash = null;
        }

        if ($parentPhone !== null) {
            $credential = $this->linkParent($student, $parentPhone, $parentName, $relation, $chosenPasswordHash);

            if ($credential !== null) {
                $credentials[] = $credential;
            }
        }

        return $credentials;
    }

    /**
     * @return array{role: string, name: string, phone: string, password: string|null, code: string|null}
     */
    private function createStudentAccount(Student $student, string $phone, ?string $chosenPasswordHash): array
    {
        if (User::withTrashed()->where('phone', $phone)->exists()) {
            throw ValidationException::withMessages([
                'phone' => 'رقم موبايل الطالب مستخدم في حساب آخر.',
            ]);
        }

        $password = $chosenPasswordHash === null ? TempPassword::generate() : null;

        $user = User::create([
            'name' => $student->name,
            'phone' => $phone,
            'password' => $password ?? $chosenPasswordHash,
            'is_active' => true,
            'must_change_password' => $password !== null,
        ]);

        $user->assignRole(Role::Student);

        $student->user()->associate($user)->save();

        return [
            'role' => Role::Student->value,
            'name' => $user->name,
            'phone' => $phone,
            'password' => $password,
            'code' => $student->code,
        ];
    }

    /**
     * @return array{role: string, name: string, phone: string, password: string|null}|null
     */
    private function linkParent(
        Student $student,
        string $phone,
        ?string $name,
        ?ParentRelation $relation,
        ?string $chosenPasswordHash,
    ): ?array {
        $parent = User::withTrashed()->where('phone', $phone)->lockForUpdate()->first();
        $credential = null;

        if ($parent !== null && ($parent->trashed() || ! $parent->hasRole(Role::Parent))) {
            throw ValidationException::withMessages([
                'parent_phone' => 'رقم موبايل ولي الأمر مسجل لحساب آخر ليس ولي أمر.',
            ]);
        }

        if ($parent === null) {
            $password = $chosenPasswordHash === null ? TempPassword::generate() : null;

            $parent = User::create([
                'name' => $name ?: 'ولي أمر '.$student->name,
                'phone' => $phone,
                'password' => $password ?? $chosenPasswordHash,
                'is_active' => true,
                'must_change_password' => $password !== null,
            ]);

            $parent->assignRole(Role::Parent);

            $credential = [
                'role' => Role::Parent->value,
                'name' => $parent->name,
                'phone' => $phone,
                'password' => $password,
            ];
        }

        $student->parents()->syncWithoutDetaching([$parent->id => ['relation' => $relation?->value]]);

        activity('students')
            ->performedOn($student)
            ->event('parent_linked')
            ->withProperties(['parent_id' => $parent->id, 'new_account' => $credential !== null])
            ->log('parent_linked');

        return $credential;
    }

    /**
     * The User "hashed" cast stores an already-hashed value as is (no double
     * hashing) but throws when its configuration does not verify, e.g. if the
     * bcrypt rounds were lowered after the registration. Such a hash is dropped
     * and the account falls back to a temporary password.
     */
    private function usableHash(?string $hash): ?string
    {
        if ($hash === null || ! Hash::isHashed($hash) || ! Hash::verifyConfiguration($hash)) {
            return null;
        }

        return $hash;
    }
}
