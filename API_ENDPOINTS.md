# Teacher System API (for the Flutter apps)

Base URL: `https://<your-domain>/api`

There are two apps:

- **Student app**: students and parents. Logs in with `POST /student/auth/login`.
- **Staff app**: the teacher and assistants. Logs in with `POST /staff/auth/login`.

---

## 1. General rules

### Headers

| Header | Value | When |
|---|---|---|
| `Accept` | `application/json` | Always |
| `Content-Type` | `application/json` | Requests with a body |
| `Authorization` | `Bearer <token>` | Every endpoint marked **Auth** |

### Error shape (every error)

```json
{
  "message": "Human-readable message (Arabic for domain errors)",
  "code": "MACHINE_READABLE_CODE",
  "errors": {}
}
```

`errors` is always an object. For validation errors it holds `field: [messages]`. Otherwise it is empty `{}`.

Switch on `code`, not on `message`.

| HTTP | `code` | Meaning |
|---|---|---|
| 401 | `UNAUTHENTICATED` | Missing, invalid or revoked token. Send the user back to the login screen. |
| 403 | `ACCOUNT_INACTIVE` | Account deactivated by the teacher. All its tokens are revoked. |
| 403 | `PASSWORD_CHANGE_REQUIRED` | The user has a temporary password. Open the change-password screen. |
| 403 | `REGISTRATION_PENDING` | Self-registration is still waiting for approval. Student login only. |
| 403 | `FORBIDDEN` | Missing permission or not the owner. |
| 404 | `NOT_FOUND` | Unknown resource or route. |
| 404 | `FEATURE_DISABLED` | Self-registration is turned off for this teacher. |
| 409 | `STUDENT_NOT_PENDING` | Approve or reject on a student who is not pending. |
| 422 | `VALIDATION_FAILED` | See `errors` for each field. |
| 422 | `INVALID_CREDENTIALS` | Wrong login. `errors.identifier` is set. |
| 429 | `TOO_MANY_ATTEMPTS` | Rate limited. Wait and retry. |

### Phones

Any Egyptian mobile format is accepted, for example `01001234567`, `+201001234567`, `00201001234567`, `1001234567`, `010 0123 4567`, or Arabic digits `٠١٠٠١٢٣٤٥٦٧`. Responses always return the format `01XXXXXXXXX`.

### Temporary passwords and `must_change_password`

New accounts that staff create get a temporary password. Temporary passwords are shown once in `credentials`. While `must_change_password` is `true`, only these three endpoints work:

- `GET /auth/me`
- `POST /auth/logout`
- `POST /auth/change-password`

Everything else returns `403 PASSWORD_CHANGE_REQUIRED`.

After login, check `must_change_password` and open the change-password screen if it is `true`.

### Rate limits

| Endpoint | Limit |
|---|---|
| Both login endpoints | 5 per minute per **identifier + IP**. The two endpoints share this limit. The same phone written in different formats counts as one identifier. |
| `POST /student/register` | 5 per minute per IP |

---

## 2. Shared objects

### `LoginResponse` (login, `me`, change-password)

```json
{
  "token": "5|Pez6r9bi6Tby0JbcXcni82audWT0ZEHz8wDy81Zkca251aaa",
  "token_type": "Bearer",
  "user": { "...User" },
  "roles": ["assistant"],
  "permissions": ["students.approve", "students.view"],
  "must_change_password": false
}
```

`token` and `token_type` appear in the login responses only.

> Use the **top-level** `roles` and `permissions`. `user.permissions` is missing for the teacher, and the teacher implicitly has every permission.

### `User`

```json
{
  "id": 2,
  "name": "Omar Ali",
  "phone": "01001111111",
  "is_active": true,
  "must_change_password": false,
  "last_login_at": "2026-11-16T14:29:26+00:00",
  "roles": ["student"],
  "permissions": [],
  "student": { "...Student (only for a student account)" },
  "children": [ "...Student[] (only for a parent account)" ],
  "created_at": "2026-11-16T14:29:26+00:00"
}
```

- `student` is `null` for a non-student account.
- `children` is `[]` for a non-parent account. Each child also includes `relation`.

### `Student`

```json
{
  "id": 1,
  "code": "ST-1001",
  "name": "Omar Ali",
  "status": "active",
  "status_label": "نشط",
  "relation": "father",
  "notes": null,
  "registration_data": { "phone": "...", "parent_phone": "...", "parent_name": "...", "parent_relation": "..." },
  "account": { "id": 2, "phone": "01001111111", "is_active": true },
  "parents": [{ "id": 3, "name": "Ali Hassan", "phone": "01202222222", "relation": "father" }],
  "created_at": "2026-11-16T14:29:26+00:00"
}
```

Some fields appear only in certain cases:

| Field | When present |
|---|---|
| `relation` | In a parent's `children` list |
| `notes` | Staff only |
| `registration_data` | Staff only, and only while the student is pending. It never contains the password or its hash. |
| `account` | When loaded. `null` if the student has no own login (no own phone). |
| `parents` | When loaded |

Values:

- `status`: `pending` | `active` | `suspended`
- `relation` / `parent_relation`: `father` | `mother` | `guardian`

### Permission values

`attendance.scan`, `attendance.view`, `students.view`, `students.create`, `students.edit`, `students.approve`, `payments.collect`, `payments.reports`, `groups.manage`

---

## 3. Student app (students and parents)

### 3.1 `POST /student/register`: self-registration

**Public**, needs feature `self_registration` to be on. Rate limit: 5/min/IP.

Creates a **pending** student only. No login accounts are created until staff approve. The chosen password is stored only as a hash.

**Body**

| Field | Type | Rules |
|---|---|---|
| `name` | string | required, max 255 |
| `phone` | string | Student's own mobile. Required if `parent_phone` is missing. Must not already belong to an account (unless it equals `parent_phone`). |
| `parent_phone` | string | Required if `phone` is missing. Egyptian mobile. |
| `parent_name` | string | optional, max 255 |
| `parent_relation` | string | optional: `father` / `mother` / `guardian` |
| `notes` | string | optional, max 2000 |
| `password` | string | required, min 8, max 255 |
| `password_confirmation` | string | required, must match `password` |

```json
{
  "name": "Omar Ali",
  "phone": "01001111111",
  "parent_phone": "01202222222",
  "parent_name": "Ali Hassan",
  "parent_relation": "father",
  "password": "secret-123",
  "password_confirmation": "secret-123"
}
```

**201 Created**

```json
{
  "message": "تم استلام طلب التسجيل، وسيتم التواصل معك بعد مراجعته.",
  "data": { "id": 1, "code": "ST-1001", "name": "Omar Ali", "status": "pending" }
}
```

Show the `code` to the user. They can log in with it after approval if they have their own phone (see 3.3).

**Errors**

| HTTP / code | When |
|---|---|
| 422 `VALIDATION_FAILED` | Invalid fields. It also covers a duplicate request: another **pending** request already uses this `phone`, or uses this `parent_phone` when no student phone is sent. The error goes on that field: `"يوجد طلب تسجيل بهذا الرقم قيد المراجعة بالفعل."` |
| 404 `FEATURE_DISABLED` | Self-registration is off |
| 429 `TOO_MANY_ATTEMPTS` | Rate limited |

Example duplicate error:

```json
{
  "message": "يوجد طلب تسجيل بهذا الرقم قيد المراجعة بالفعل.",
  "code": "VALIDATION_FAILED",
  "errors": { "phone": ["يوجد طلب تسجيل بهذا الرقم قيد المراجعة بالفعل."] }
}
```

---

### 3.2 `POST /student/auth/login`: student or parent login

**Public**. Rate limit: 5/min per identifier + IP.

**Body**

| Field | Type | Rules |
|---|---|---|
| `identifier` | string | required, max 64. A phone (any format) **or** a student code (`ST-1001`, case-insensitive). |
| `password` | string | required, max 255 |
| `device_name` | string | required, max 100. Used as the token name, e.g. `"Samsung A54"`. |

```json
{ "identifier": "ST-1001", "password": "secret-123", "device_name": "android" }
```

**200 OK**: a `LoginResponse`.

```json
{
  "token": "2|HaPaikdjdZamwB6zA46X1EuHg0U9CJayE2z41kFu892f452b",
  "token_type": "Bearer",
  "user": {
    "id": 2, "name": "Omar Ali", "phone": "01001111111",
    "is_active": true, "must_change_password": false,
    "last_login_at": "2026-11-16T14:29:26+00:00",
    "roles": ["student"], "permissions": [],
    "student": { "id": 1, "code": "ST-1001", "name": "Omar Ali", "status": "active", "status_label": "نشط", "created_at": "2026-11-16T14:29:26+00:00" },
    "children": [],
    "created_at": "2026-11-16T14:29:26+00:00"
  },
  "roles": ["student"],
  "permissions": [],
  "must_change_password": false
}
```

For a parent, `roles` is `["parent"]`, `user.student` is `null`, and `user.children` lists the linked students, each with `relation`.

**Errors**

| HTTP / code | When |
|---|---|
| 422 `INVALID_CREDENTIALS` | Wrong password, unknown phone/code, a teacher/assistant account, a rejected registration, or a pending registration with the wrong password |
| 403 `REGISTRATION_PENDING` | The identifier (code, student phone or parent phone) matches a **pending** registration **and** the password is the one chosen at registration |
| 403 `ACCOUNT_INACTIVE` | Account deactivated |
| 422 `VALIDATION_FAILED` | Missing fields |
| 429 `TOO_MANY_ATTEMPTS` | Rate limited |

```json
{
  "message": "بيانات الدخول غير صحيحة.",
  "code": "INVALID_CREDENTIALS",
  "errors": { "identifier": ["بيانات الدخول غير صحيحة."] }
}
```

```json
{
  "message": "طلب التسجيل قيد المراجعة، وهيتفعل حسابك بعد موافقة الإدارة.",
  "code": "REGISTRATION_PENDING",
  "errors": {}
}
```

---

### 3.3 Which account and password does a self-registered user use after approval?

| Registration | After approval, log in with | Password | `must_change_password` |
|---|---|---|---|
| Student sent **own phone** (different from the parent phone) | Student phone **or** student code | The password chosen at registration | `false` |
| **No own phone** (or same as parent phone), parent phone is **new** | Parent phone (parent account) | The password chosen at registration | `false` |
| **No own phone**, parent phone **already has an account** | Parent phone | The parent's **existing** password. The chosen password is not used. | unchanged |

In the first case, a new parent account is also created with a temporary password. Staff see it in `credentials`.

A student without their own phone has **no student account**, so the student code cannot be used to log in. The family uses the parent account.

---

## 4. Staff app (teacher and assistants)

### 4.1 `POST /staff/auth/login`: teacher or assistant login

**Public**. Rate limit: 5/min per identifier + IP (shared with the student login).

**Body**

| Field | Type | Rules |
|---|---|---|
| `identifier` | string | required. **Phone only**. A student code is treated as wrong credentials. |
| `password` | string | required, max 255 |
| `device_name` | string | required, max 100 |

```json
{ "identifier": "01033334444", "password": "password", "device_name": "windows" }
```

**200 OK**: a `LoginResponse`.

```json
{
  "token": "5|Pez6r9bi6Tby0JbcXcni82audWT0ZEHz8wDy81Zkca251aaa",
  "token_type": "Bearer",
  "user": {
    "id": 4, "name": "Assistant Name", "phone": "01033334444",
    "is_active": true, "must_change_password": false,
    "last_login_at": "2026-11-16T14:29:26+00:00",
    "roles": ["assistant"], "permissions": ["students.approve", "students.view"],
    "student": null, "children": [],
    "created_at": "2026-11-16T14:29:26+00:00"
  },
  "roles": ["assistant"],
  "permissions": ["students.approve", "students.view"],
  "must_change_password": false
}
```

For the teacher, `roles` is `["teacher"]` and `permissions` lists every permission.

**Errors**

| HTTP / code | When |
|---|---|
| 422 `INVALID_CREDENTIALS` | Wrong password, unknown phone, a student code, or a **student/parent** account. The response is identical, so the app never learns that an account exists in the other app. |
| 403 `ACCOUNT_INACTIVE` | Assistant deactivated |
| 422 `VALIDATION_FAILED` | Missing fields |
| 429 `TOO_MANY_ATTEMPTS` | Rate limited |

---

## 5. Session endpoints (both apps)

All need **Auth**.

### 5.1 `GET /auth/me`

Returns the current profile, the same as `LoginResponse` without `token` and `token_type`. Works while `must_change_password` is `true`.

### 5.2 `POST /auth/logout`

Revokes the current token only. Other devices stay logged in.

**200**

```json
{ "message": "تم تسجيل الخروج." }
```

### 5.3 `POST /auth/change-password`

Works while `must_change_password` is `true`.

**Body**

| Field | Rules |
|---|---|
| `current_password` | required, must be the current password |
| `password` | required, min 8, max 255, different from `current_password` |
| `password_confirmation` | required, must match |

**200**: clears `must_change_password` and revokes the user's **other** tokens. The current token keeps working.

```json
{
  "message": "تم تغيير كلمة المرور.",
  "user": { "...User" },
  "roles": ["parent"],
  "permissions": [],
  "must_change_password": false
}
```

**Errors**: 422 `VALIDATION_FAILED` on `current_password` or `password`.

---

## 6. Students (staff app, plus read access for students and parents)

All need **Auth**.

### 6.1 `GET /students?status=&search=&page=`

Requires permission `students.view`.

| Query | Rules |
|---|---|
| `status` | optional: `pending` / `active` / `suspended` |
| `search` | optional, max 100. Matches name (contains), exact code, or a student/parent phone. |
| `page` | optional, 25 per page |

**200**: a paginated list.

```json
{
  "data": [ { "...Student with account and parents" } ],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": { "current_page": 1, "last_page": 3, "per_page": 25, "total": 61, "...": "..." }
}
```

Use `?status=pending` to list registration requests. Their `registration_data` shows the submitted phones.

### 6.2 `GET /students/{id}`

Allowed for staff with `students.view`, the student themselves, or a linked parent. Otherwise `403 FORBIDDEN`.

**200**: `{ "data": Student }`. Students and parents never get `notes` or `registration_data`.

Example for staff viewing a pending student:

```json
{
  "data": {
    "id": 1, "code": "ST-1001", "name": "Omar Ali",
    "status": "pending", "status_label": "في انتظار الموافقة",
    "notes": null,
    "registration_data": { "phone": "01001111111", "parent_phone": "01202222222", "parent_name": "Ali Hassan", "parent_relation": "father" },
    "account": null, "parents": [],
    "created_at": "2026-11-16T14:29:26+00:00"
  }
}
```

### 6.3 `POST /students`: staff creates a student

Requires `students.create`. The student is created as **active**, with accounts on temporary passwords.

**Body**: the same as registration **without** `password` and `password_confirmation`. That is `name`, `phone`, `parent_phone` (at least one phone), `parent_name`, `parent_relation`, `notes`.

**201**

```json
{
  "data": { "...Student" },
  "credentials": [
    { "role": "student", "name": "Omar Ali", "phone": "01001111111", "password": "t4y3rdyv9g", "code": "ST-1001" },
    { "role": "parent",  "name": "Ali Hassan", "phone": "01202222222", "password": "k8m2pq7xra" }
  ]
}
```

`credentials` lists **new** accounts only. An existing parent is just linked and does not appear. Show these passwords once, for example to print or send by WhatsApp.

**Errors**: 422 `VALIDATION_FAILED`, for example:

- `phone`: already used
- `parent_phone`: belongs to a non-parent account

### 6.4 `PATCH /students/{id}`

Requires `students.edit`.

| Field | Rules |
|---|---|
| `name` | sometimes, string, max 255 |
| `status` | sometimes: `active` / `suspended`. Pending students are activated through approve. |
| `notes` | sometimes, nullable, max 2000 |

**200**: `{ "data": Student }`

### 6.5 `POST /students/{id}/regenerate-qr`

Requires `students.edit`. The old QR card stops working.

**200**

```json
{ "data": { "qr_token": "48-char-token" } }
```

### 6.6 `POST /students/{id}/approve`

Requires `students.approve`. The student must be pending.

Activates the student, creates or links accounts, and clears `registration_data`.

**Body**: none.

**200**

```json
{
  "data": { "...Student (status: active, account, parents)" },
  "credentials": [
    { "role": "student", "name": "Omar Ali", "phone": "01001111111", "password": null, "code": "ST-1001" },
    { "role": "parent", "name": "Ali Hassan", "phone": "01202222222", "password": "t4y3rdyv9g" }
  ]
}
```

- `"password": null` means this account uses the password the registrant chose. There is nothing to hand over.
- A string is a temporary password. The user must change it on first login.
- Requests made before passwords were added to registration get temporary passwords for all new accounts.
- An existing parent is only linked. It does not appear in `credentials` and its password does not change.

**Errors**

| HTTP / code | When |
|---|---|
| 409 `STUDENT_NOT_PENDING` | The student is not pending |
| 422 `VALIDATION_FAILED` | The phone got taken since registering, or the parent phone belongs to a non-parent account. Nothing is changed. |
| 403 `FORBIDDEN` | Missing `students.approve` |

### 6.7 `POST /students/{id}/reject`

Requires `students.approve`. The student must be pending.

Soft-deletes the request. The registrant then gets `INVALID_CREDENTIALS` and can register again.

**Body**

| Field | Rules |
|---|---|
| `reason` | optional, max 500 |

**200**

```json
{ "message": "تم رفض طلب التسجيل." }
```

**Errors**: 409 `STUDENT_NOT_PENDING`, 403 `FORBIDDEN`.

---

## 7. Assistants (staff app, teacher only)

All need **Auth** plus the teacher role. Anyone else gets `403 FORBIDDEN`.

| Method | URL | Body | 200/201 response |
|---|---|---|---|
| GET | `/assistants` | — | `{ "data": User[] }`, sorted by name |
| GET | `/assistants/permissions` | — | `{ "data": [{ "value": "students.view", "label": "عرض الطلاب" }, ...] }`. Use it to build the permission checkboxes. |
| POST | `/assistants` | `name` (req), `phone` (req, not already used), `password` (optional, min 8; generated if missing), `permissions` (array of permission values, may be `[]`) | **201** `{ "data": User, "credentials": { "phone": "...", "password": "..." } }`. The assistant must change the password on first login. |
| PUT | `/assistants/{id}/permissions` | `permissions` (array, may be `[]`) | `{ "data": User }` |
| POST | `/assistants/{id}/reset-password` | `password` (optional, min 8) | `{ "data": User, "credentials": { "phone", "password" } }`. Signs the assistant out of every device and forces a password change. |
| POST | `/assistants/{id}/deactivate` | — | `{ "data": User }` with `is_active: false`. All their tokens are revoked immediately. |
| POST | `/assistants/{id}/activate` | — | `{ "data": User }` with `is_active: true` |

`{id}` that is not an assistant returns `404 NOT_FOUND`.

---

## 8. Suggested app flow

**Student app**

1. Register with `POST /student/register`, then show the returned `code` and a "waiting for approval" screen.
2. Log in with `POST /student/auth/login`.
   - `REGISTRATION_PENDING`: show the pending screen.
   - `INVALID_CREDENTIALS`: show the error under the identifier field.
   - `must_change_password: true`: open the change-password screen (5.3).
   - Otherwise go to the home screen. `roles` is `student` or `parent`, and a parent sees `user.children`.
3. On startup, call `GET /auth/me`. A 401 means go to login. `ACCOUNT_INACTIVE` means log out and show the message.

**Staff app**

1. Log in with `POST /staff/auth/login`.
2. If `must_change_password` is `true`, open the change-password screen.
3. Show menus based on the top-level `permissions`. The teacher gets every permission.
4. Handle registration requests with `GET /students?status=pending`, then approve or reject, and show `credentials` (temporary passwords only).
