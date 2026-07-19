# ShuleOS authentication API contract

This contract is served by the existing JWT endpoints under `/api/auth`. Authentication scope, roles, permissions, and the active school are always resolved by the server. Clients must never submit or override them.

## Login

`POST /api/auth/login`

```json
{
  "username": "operator",
  "password": "the-user-password"
}
```

A successful response contains the canonical contract under `data`. The top-level `token`, `token_type`, `expires_in`, and `user` fields remain temporarily for existing clients.

```json
{
  "success": true,
  "token": "<jwt>",
  "token_type": "bearer",
  "expires_in": 3600,
  "user": { "...": "same structure as data.user" },
  "data": {
    "token": "<jwt>",
    "token_type": "bearer",
    "expires_in": 3600,
    "user": { "...": "authenticated user structure below" }
  }
}
```

Invalid usernames, passwords, inactive accounts, deleted accounts, suspended accounts, and locked accounts all return the same `401` response. This prevents account enumeration.

## Authenticated user

`GET /api/auth/me` requires `Authorization: Bearer <jwt>`.

```json
{
  "success": true,
  "user": { "...": "authenticated user structure below" },
  "data": {
    "user": { "...": "authenticated user structure below" }
  }
}
```

The authenticated user structure is:

```json
{
  "id": "00000000-0000-0000-0000-000000000000",
  "name": "Amina Teacher",
  "first_name": "Amina",
  "last_name": "Teacher",
  "username": "amina",
  "email": "amina@example.test",
  "status": "active",
  "school_id": "00000000-0000-0000-0000-000000000001",
  "school": {
    "id": "00000000-0000-0000-0000-000000000001",
    "name": "Example School",
    "short_name": "Example",
    "status": "active",
    "timezone": "Africa/Nairobi",
    "locale": "en"
  },
  "roles": ["Teacher"],
  "permissions": ["access_teacher_portal"],
  "password_reset_required": false,
  "account": {
    "active": true,
    "locked": false,
    "requires_password_reset": false
  }
}
```

Roles and permissions are unique and sorted. Role IDs, permission IDs, pivot rows, password hashes, reset tokens, JWT claims, and teacher/parent/learner profile records are not included. Only the user's active school is returned.

## Refresh

`POST /api/auth/refresh` requires the current Bearer JWT. It invalidates that JWT and returns a new JWT with the same envelope as login. The user, roles, and permissions are recalculated from the database, so administrative changes are reflected immediately.

The configured JWT TTL determines `expires_in`. A revoked session or a token outside the library's accepted refresh behavior returns `401`.

## Logout

`POST /api/auth/logout` requires the current Bearer JWT and invalidates it.

```json
{
  "success": true,
  "message": "Successfully logged out"
}
```

## Errors

Validation (`422`):

```json
{
  "success": false,
  "message": "The given data was invalid.",
  "errors": { "username": ["The username field is required."] }
}
```

Unauthenticated (`401`):

```json
{ "success": false, "message": "Unauthenticated." }
```

Unavailable account school (`403`):

```json
{ "success": false, "message": "Access is unavailable." }
```

Authorization failures do not reveal the required role or permission.

## Browser session limitation

Refresh is Bearer-token based; ShuleOS does not currently issue an HttpOnly refresh cookie. A frontend that keeps JWTs in memory only cannot restore authentication after a full page reload and must ask the user to sign in again. Clients must not work around this limitation by storing JWTs in `localStorage` or `sessionStorage`. A cookie-based restoration contract requires a separate security design.

