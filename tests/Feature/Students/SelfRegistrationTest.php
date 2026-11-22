<?php

use App\Enums\Permission;
use App\Enums\StudentStatus;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

const REGISTER = '/api/student/register';
const CHOSEN_PASSWORD = 'my-chosen-secret';

beforeEach(function () {
    RateLimiter::clear('registration');
    RateLimiter::clear('login');
    config(['teacher.features.self_registration' => true]);
});

function registration(array $overrides = []): array
{
    return [
        'name' => 'Self Registered',
        'phone' => '01001111111',
        'parent_phone' => '01202222222',
        'parent_relation' => 'mother',
        'password' => CHOSEN_PASSWORD,
        'password_confirmation' => CHOSEN_PASSWORD,
        ...$overrides,
    ];
}

function studentLogin(string $identifier, string $password = CHOSEN_PASSWORD): array
{
    return ['identifier' => $identifier, 'password' => $password, 'device_name' => 'android'];
}

/*
|--------------------------------------------------------------------------
| Registration
|--------------------------------------------------------------------------
*/

it('is unavailable when the feature flag is off', function () {
    config(['teacher.features.self_registration' => false]);

    $this->postJson(REGISTER, registration())
        ->assertNotFound()
        ->assertJsonPath('code', 'FEATURE_DISABLED');

    expect(Student::count())->toBe(0);
});

it('creates a pending student without any accounts', function () {
    $response = $this->postJson(REGISTER, registration())
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonMissingPath('credentials');

    $student = Student::sole();

    $response->assertExactJson([
        'message' => 'تم استلام طلب التسجيل، وسيتم التواصل معك بعد مراجعته.',
        'data' => ['id' => $student->id, 'code' => $student->code, 'name' => 'Self Registered', 'status' => 'pending'],
    ]);

    expect($student->isPending())->toBeTrue()
        ->and($student->user_id)->toBeNull()
        ->and(User::count())->toBe(0)
        ->and($student->registration_data['parent_phone'])->toBe('01202222222');
});

it('stores the chosen password only as a hash', function () {
    $response = $this->postJson(REGISTER, registration())->assertCreated();

    $data = Student::sole()->registration_data;

    expect($data)->not->toHaveKeys(['password', 'password_confirmation'])
        ->and(Hash::check(CHOSEN_PASSWORD, $data['password_hash']))->toBeTrue()
        ->and($response->getContent())->not->toContain(CHOSEN_PASSWORD)
        ->and($response->getContent())->not->toContain('password');

    expect(DB::table('activity_log')->pluck('properties')->implode(' '))->not->toContain(CHOSEN_PASSWORD);
});

it('never shows the password hash to staff', function () {
    $this->postJson(REGISTER, registration())->assertCreated();
    $student = Student::sole();
    $headers = authHeaders(teacher());

    $show = $this->getJson("/api/students/{$student->id}", $headers)
        ->assertOk()
        ->assertJsonPath('data.registration_data.phone', '01001111111')
        ->assertJsonMissingPath('data.registration_data.password_hash');

    $index = $this->getJson('/api/students?status=pending', $headers)
        ->assertOk()
        ->assertJsonPath('data.0.registration_data.parent_phone', '01202222222')
        ->assertJsonMissingPath('data.0.registration_data.password_hash');

    $hash = $student->registration_data['password_hash'];

    foreach ([$show, $index] as $response) {
        expect($response->getContent())->not->toContain('password_hash')
            ->and($response->getContent())->not->toContain(json_encode($hash))
            ->and($response->getContent())->not->toContain(CHOSEN_PASSWORD);
    }
});

it('validates registration', function () {
    $this->postJson(REGISTER, ['name' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'phone', 'parent_phone', 'password']);
});

it('requires a confirmed password of at least 8 characters', function (array $payload) {
    $this->postJson(REGISTER, registration($payload))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonValidationErrors('password');

    expect(Student::count())->toBe(0);
})->with([
    'missing' => [['password' => null, 'password_confirmation' => null]],
    'no confirmation' => [['password_confirmation' => null]],
    'not confirmed' => [['password_confirmation' => 'something-else']],
    'too short' => [['password' => 'short', 'password_confirmation' => 'short']],
]);

it('rejects a second pending request with the same student phone', function () {
    $this->postJson(REGISTER, registration())->assertCreated();

    $this->postJson(REGISTER, registration(['name' => 'Again', 'phone' => '+20 100 111 1111', 'parent_phone' => '01203333333']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('phone');

    expect(Student::count())->toBe(1);
});

it('rejects a second pending request with the same parent phone when no student phone is sent', function () {
    $this->postJson(REGISTER, registration(['phone' => null]))->assertCreated();

    $this->postJson(REGISTER, registration(['name' => 'Sibling', 'phone' => null]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('parent_phone');

    // With their own phone, the sibling can register under the same parent.
    $this->postJson(REGISTER, registration(['name' => 'Sibling', 'phone' => '01005555555']))->assertCreated();
});

it('allows registering again after a rejected request', function () {
    $this->postJson(REGISTER, registration())->assertCreated();
    Student::sole()->delete();

    $this->postJson(REGISTER, registration())->assertCreated();
});

it('is rate limited', function () {
    foreach (range(1, 5) as $i) {
        $this->postJson(REGISTER, ['name' => ''])->assertUnprocessable();
    }

    $this->postJson(REGISTER, registration())->assertTooManyRequests();
});

it('removed the old registration route', function () {
    $this->postJson('/api/register', registration())->assertNotFound();

    expect(Student::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Logging in while pending
|--------------------------------------------------------------------------
*/

it('tells a pending registrant with the right password that the request is under review', function (array $overrides, Closure $identifier) {
    $this->postJson(REGISTER, registration($overrides))->assertCreated();

    $this->postJson('/api/student/auth/login', studentLogin($identifier(Student::sole())))
        ->assertForbidden()
        ->assertExactJson([
            'message' => 'طلب التسجيل قيد المراجعة، وهيتفعل حسابك بعد موافقة الإدارة.',
            'code' => 'REGISTRATION_PENDING',
            'errors' => [],
        ]);

    $this->assertDatabaseHas('activity_log', ['event' => 'login_pending', 'subject_id' => Student::sole()->id]);
})->with([
    'by code' => [[], fn (Student $student) => strtolower($student->code)],
    'by student phone' => [[], fn () => '+201001111111'],
    'by parent phone' => [['phone' => null], fn () => '01202222222'],
]);

it('treats a wrong password on a pending registration as invalid credentials', function () {
    $this->postJson(REGISTER, registration())->assertCreated();

    foreach ([Student::sole()->code, '01001111111'] as $identifier) {
        $this->postJson('/api/student/auth/login', studentLogin($identifier, 'wrong-password'))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }
});

it('treats a rejected registration as invalid credentials', function () {
    $this->postJson(REGISTER, registration())->assertCreated();
    $student = Student::sole();
    $student->delete();

    $this->postJson('/api/student/auth/login', studentLogin($student->code))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');
});

it('does not reveal a pending registration on the staff app', function () {
    $this->postJson(REGISTER, registration())->assertCreated();

    $this->postJson('/api/staff/auth/login', studentLogin('01001111111'))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');
});

/*
|--------------------------------------------------------------------------
| Approval
|--------------------------------------------------------------------------
*/

it('approves a pending student, creating accounts and linking an existing parent', function () {
    $parent = User::factory()->parent()->create(['phone' => '01202222222']);
    $parentPasswordHash = $parent->password;
    $sibling = Student::factory()->create();
    $parent->children()->attach($sibling);

    $this->postJson(REGISTER, registration())->assertCreated();
    $student = Student::status(StudentStatus::Pending)->sole();

    $staff = assistant([Permission::StudentsApprove]);

    $response = $this->postJson("/api/students/{$student->id}/approve", [], authHeaders($staff))
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonCount(1, 'credentials')
        ->assertJsonPath('credentials.0.role', 'student')
        ->assertJsonPath('credentials.0.password', null)
        ->assertJsonPath('data.parents.0.relation', 'mother');

    expect($response->getContent())->not->toContain('password_hash');

    $student->refresh();
    expect($student->registration_data)->toBeNull()
        ->and($student->user->phone)->toBe('01001111111')
        ->and($student->user->must_change_password)->toBeFalse()
        ->and($parent->children()->count())->toBe(2)
        ->and($parent->fresh()->password)->toBe($parentPasswordHash);

    $this->assertDatabaseHas('activity_log', ['subject_id' => $student->id, 'log_name' => 'students', 'causer_id' => $staff->id]);
});

it('lets the student log in with the chosen password after approval', function () {
    $this->postJson(REGISTER, registration())->assertCreated();
    $student = Student::sole();

    $response = $this->postJson("/api/students/{$student->id}/approve", [], authHeaders(teacher()))
        ->assertOk()
        ->assertJsonCount(2, 'credentials')
        ->assertJsonPath('credentials.0.role', 'student')
        ->assertJsonPath('credentials.0.code', $student->code)
        ->assertJsonPath('credentials.0.password', null)
        ->assertJsonPath('credentials.1.role', 'parent');

    // The new parent account (not the one the registrant uses) gets a temporary password.
    $parent = User::where('phone', '01202222222')->sole();
    expect($response->json('credentials.1.password'))->toBeString()
        ->and(Hash::check($response->json('credentials.1.password'), $parent->password))->toBeTrue()
        ->and($parent->must_change_password)->toBeTrue();

    forgetAuth();

    foreach ([$student->code, '01001111111'] as $identifier) {
        $this->postJson('/api/student/auth/login', studentLogin($identifier))
            ->assertOk()
            ->assertJsonPath('roles', ['student'])
            ->assertJsonPath('must_change_password', false)
            ->assertJsonPath('user.student.id', $student->id);
    }
});

it('gives the chosen password to a new parent account when the student has no phone', function (array $overrides) {
    $this->postJson(REGISTER, registration($overrides))->assertCreated();
    $student = Student::sole();

    $this->postJson("/api/students/{$student->id}/approve", [], authHeaders(teacher()))
        ->assertOk()
        ->assertJsonPath('data.account', null)
        ->assertJsonCount(1, 'credentials')
        ->assertJsonPath('credentials.0.role', 'parent')
        ->assertJsonPath('credentials.0.phone', '01202222222')
        ->assertJsonPath('credentials.0.password', null);

    forgetAuth();
    $this->postJson('/api/student/auth/login', studentLogin('01202222222'))
        ->assertOk()
        ->assertJsonPath('roles', ['parent'])
        ->assertJsonPath('must_change_password', false)
        ->assertJsonPath('user.children.0.id', $student->id);
})->with([
    'no student phone' => [['phone' => null]],
    'same phone as the parent' => [['phone' => '01202222222']],
]);

it('only links an existing parent and never changes their password', function () {
    $parent = User::factory()->parent()->create(['phone' => '01202222222']);
    $parentPasswordHash = $parent->password;

    $this->postJson(REGISTER, registration(['phone' => null]))->assertCreated();
    $student = Student::sole();

    $this->postJson("/api/students/{$student->id}/approve", [], authHeaders(teacher()))
        ->assertOk()
        ->assertJsonCount(0, 'credentials');

    expect($parent->fresh()->password)->toBe($parentPasswordHash)
        ->and($parent->children()->pluck('students.id')->all())->toBe([$student->id]);

    forgetAuth();
    $this->postJson('/api/student/auth/login', studentLogin('01202222222'))
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');
    $this->postJson('/api/student/auth/login', studentLogin('01202222222', 'password'))->assertOk();
});

it('gives temporary passwords to a legacy pending registration without a password', function () {
    $student = Student::factory()->pending()->create([
        'registration_data' => ['phone' => '01001111111', 'parent_phone' => '01202222222', 'parent_name' => null, 'parent_relation' => null],
    ]);

    $response = $this->postJson("/api/students/{$student->id}/approve", [], authHeaders(teacher()))
        ->assertOk()
        ->assertJsonCount(2, 'credentials');

    $studentUser = User::where('phone', '01001111111')->sole();

    expect($response->json('credentials.0.password'))->toBeString()
        ->and($response->json('credentials.1.password'))->toBeString()
        ->and(Hash::check($response->json('credentials.0.password'), $studentUser->password))->toBeTrue()
        ->and($studentUser->must_change_password)->toBeTrue();
});

it('rejects a pending student', function () {
    $student = Student::factory()->pending()->create();

    $this->postJson("/api/students/{$student->id}/reject", ['reason' => 'duplicate'], authHeaders(assistant([Permission::StudentsApprove])))
        ->assertOk();

    expect($student->fresh()->trashed())->toBeTrue();
    $this->assertDatabaseHas('activity_log', ['event' => 'rejected', 'subject_id' => $student->id]);
});

it('refuses to approve or reject a student that is not pending', function () {
    $student = Student::factory()->create();
    $headers = authHeaders(teacher());

    $this->postJson("/api/students/{$student->id}/approve", [], $headers)
        ->assertStatus(409)
        ->assertJsonPath('code', 'STUDENT_NOT_PENDING');

    $this->postJson("/api/students/{$student->id}/reject", [], $headers)->assertStatus(409);
});

it('requires students.approve', function () {
    $student = Student::factory()->pending()->create();
    $headers = authHeaders(assistant([Permission::StudentsCreate, Permission::StudentsEdit]));

    $this->postJson("/api/students/{$student->id}/approve", [], $headers)->assertForbidden();
    $this->postJson("/api/students/{$student->id}/reject", [], $headers)->assertForbidden();

    expect($student->fresh()->isPending())->toBeTrue();
});
