<?php

use App\Enums\Permission;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

const STUDENT_LOGIN = '/api/student/auth/login';
const STAFF_LOGIN = '/api/staff/auth/login';

beforeEach(fn () => RateLimiter::clear('login'));

function login(array $overrides = []): array
{
    return [
        'identifier' => '01001234567',
        'password' => 'password',
        'device_name' => 'android',
        ...$overrides,
    ];
}

/*
|--------------------------------------------------------------------------
| Shared behaviour
|--------------------------------------------------------------------------
*/

it('logs in by phone and returns token, user, roles and permissions', function () {
    $assistant = assistant([Permission::StudentsView], ['phone' => '01001234567']);

    $response = $this->postJson(STAFF_LOGIN, login())
        ->assertOk()
        ->assertJsonStructure(['token', 'user' => ['id', 'name', 'phone'], 'roles', 'permissions', 'must_change_password'])
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('roles', ['assistant'])
        ->assertJsonPath('permissions', ['students.view'])
        ->assertJsonPath('must_change_password', false);

    expect(array_keys($response->json()))->toBe(['token', 'token_type', 'user', 'roles', 'permissions', 'must_change_password'])
        ->and($assistant->tokens()->count())->toBe(1)
        ->and($assistant->fresh()->last_login_at)->not->toBeNull()
        ->and($response->json('token'))->toBeString();

    $this->assertDatabaseHas('activity_log', ['log_name' => 'auth', 'event' => 'login', 'causer_id' => $assistant->id]);
    expect(activity_properties('login'))->toMatchArray(['app' => 'staff', 'device_name' => 'android']);
});

it('accepts any phone format as identifier', function (string $identifier) {
    User::factory()->parent()->create(['phone' => '01001234567']);

    $this->postJson(STUDENT_LOGIN, login(['identifier' => $identifier]))->assertOk();
})->with(['+201001234567', '00201001234567', '1001234567', '010 0123 4567', '٠١٠٠١٢٣٤٥٦٧']);

it('rejects wrong credentials with the standard error shape', function (string $url) {
    User::factory()->parent()->create(['phone' => '01001234567']);

    $this->postJson($url, login(['password' => 'wrong-password']))
        ->assertUnprocessable()
        ->assertExactJsonStructure(['message', 'code', 'errors' => ['identifier']])
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');

    $this->postJson($url, login(['identifier' => 'ST-9999']))
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');
})->with([STUDENT_LOGIN, STAFF_LOGIN]);

it('validates the request', function (string $url) {
    $this->postJson($url, [])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonValidationErrors(['identifier', 'password', 'device_name']);
})->with([STUDENT_LOGIN, STAFF_LOGIN]);

it('rejects inactive users', function (string $url, Closure $makeUser) {
    $user = $makeUser();

    $this->postJson($url, login())
        ->assertForbidden()
        ->assertJsonPath('code', 'ACCOUNT_INACTIVE');

    expect($user->tokens()->count())->toBe(0);
    $this->assertDatabaseHas('activity_log', ['event' => 'login_blocked', 'causer_id' => $user->id]);
})->with([
    'student app' => [STUDENT_LOGIN, fn () => User::factory()->parent()->inactive()->create(['phone' => '01001234567'])],
    'staff app' => [STAFF_LOGIN, fn () => assistant([], ['phone' => '01001234567', 'is_active' => false])],
]);

it('does not reveal an inactive account of the other app', function () {
    User::factory()->parent()->inactive()->create(['phone' => '01001234567']);

    $this->postJson(STAFF_LOGIN, login())->assertJsonPath('code', 'INVALID_CREDENTIALS');
});

it('rate limits login to 5 per minute per identifier and ip', function (string $url, Closure $makeUser) {
    $makeUser('01001234567');

    foreach (range(1, 5) as $attempt) {
        $this->postJson($url, login(['password' => 'wrong']))->assertUnprocessable();
    }

    $this->postJson($url, login())
        ->assertTooManyRequests()
        ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');

    // Same phone written differently is the same identifier.
    $this->postJson($url, login(['identifier' => '+201001234567']))->assertTooManyRequests();

    // A different identifier from the same IP is not blocked.
    $makeUser('01101234567');
    $this->postJson($url, login(['identifier' => '01101234567']))->assertOk();
})->with([
    'student app' => [STUDENT_LOGIN, fn (string $phone) => User::factory()->parent()->create(['phone' => $phone])],
    'staff app' => [STAFF_LOGIN, fn (string $phone) => assistant([], ['phone' => $phone])],
]);

/*
|--------------------------------------------------------------------------
| Student app: students and parents
|--------------------------------------------------------------------------
*/

it('logs a student in by phone', function () {
    $user = User::factory()->student()->create(['phone' => '01001234567']);
    $student = Student::factory()->create(['user_id' => $user->id]);

    $this->postJson(STUDENT_LOGIN, login())
        ->assertOk()
        ->assertJsonPath('roles', ['student'])
        ->assertJsonPath('user.student.id', $student->id);

    expect(activity_properties('login'))->toMatchArray(['app' => 'student']);
});

it('logs a student in by student code (case insensitive)', function () {
    $student = Student::factory()->withAccount()->create();

    $this->postJson(STUDENT_LOGIN, login(['identifier' => strtolower($student->fresh()->code)]))
        ->assertOk()
        ->assertJsonPath('roles', ['student'])
        ->assertJsonPath('user.student.id', $student->id);
});

it('logs a parent in with their children', function () {
    $parent = User::factory()->parent()->create(['phone' => '01001234567']);
    $parent->children()->attach(Student::factory()->create(), ['relation' => 'father']);

    $this->postJson(STUDENT_LOGIN, login())
        ->assertOk()
        ->assertJsonPath('roles', ['parent'])
        ->assertJsonPath('permissions', [])
        ->assertJsonCount(1, 'user.children');
});

it('rejects staff accounts on the student app like a wrong password', function (Closure $makeStaff) {
    $staff = $makeStaff();

    $this->postJson(STUDENT_LOGIN, login())
        ->assertUnprocessable()
        ->assertExactJsonStructure(['message', 'code', 'errors' => ['identifier']])
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');

    expect($staff->tokens()->count())->toBe(0)
        ->and($staff->fresh()->last_login_at)->toBeNull()
        ->and(activity_properties('login_failed'))->toMatchArray(['app' => 'student', 'reason' => 'wrong_app']);
})->with([
    'teacher' => [fn () => teacher(['phone' => '01001234567'])],
    'assistant' => [fn () => assistant([Permission::StudentsView], ['phone' => '01001234567'])],
]);

/*
|--------------------------------------------------------------------------
| Staff app: teacher and assistants
|--------------------------------------------------------------------------
*/

it('logs the teacher in with every permission', function () {
    teacher(['phone' => '01001234567']);

    $this->postJson(STAFF_LOGIN, login())
        ->assertOk()
        ->assertJsonPath('roles', ['teacher'])
        ->assertJsonPath('permissions', Permission::values());
});

it('rejects students and parents on the staff app like a wrong password', function (Closure $makeUser) {
    $user = $makeUser();

    $this->postJson(STAFF_LOGIN, login())
        ->assertUnprocessable()
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');

    expect($user->tokens()->count())->toBe(0)
        ->and(activity_properties('login_failed'))->toMatchArray(['app' => 'staff', 'reason' => 'wrong_app']);
})->with([
    'student' => [fn () => User::factory()->student()->create(['phone' => '01001234567'])],
    'parent' => [fn () => User::factory()->parent()->create(['phone' => '01001234567'])],
]);

it('does not accept a student code on the staff app', function () {
    $student = Student::factory()->withAccount()->create();

    $this->postJson(STAFF_LOGIN, login(['identifier' => $student->fresh()->code]))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');

    expect($student->user->tokens()->count())->toBe(0);
});

it('removed the old shared login route', function () {
    $this->postJson('/api/auth/login', login())->assertNotFound();
});
