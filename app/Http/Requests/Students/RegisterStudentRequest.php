<?php

namespace App\Http\Requests\Students;

use App\Enums\StudentStatus;
use App\Models\Student;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

/**
 * Same fields and rules as staff creation, but public, plus the password the
 * registrant will log in with after approval.
 */
class RegisterStudentRequest extends StoreStudentRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ];
    }

    /**
     * Only one pending request per login phone: the student phone, or the parent
     * phone when no student phone is sent.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $field = $this->filled('phone') ? 'phone' : 'parent_phone';
                $phone = $this->input($field);

                if (! is_string($phone) || $validator->errors()->has($field)) {
                    return;
                }

                $alreadyPending = Student::query()
                    ->status(StudentStatus::Pending)
                    ->where("registration_data->{$field}", $phone)
                    ->exists();

                if ($alreadyPending) {
                    $validator->errors()->add($field, 'يوجد طلب تسجيل بهذا الرقم قيد المراجعة بالفعل.');
                }
            },
        ];
    }
}
