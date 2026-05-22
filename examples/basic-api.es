// ─── EScript Example: Basic REST API ─────────────────────────────────────────
// This example shows how to define a typical CRUD API with EScript.
// No framework-specific code — adapters handle the translation.

// ─── DTOs ────────────────────────────────────────────────────────────────────

dto UserDto {
    id: int;
    name: string;
    email: string;
    role: string = "user";
    created_at: string;
    avatar_url: string?;
}

dto CreateUserRequest {
    name: string;
    email: string;
    password: string;
    role: string = "user";
}

dto ApiError {
    code: string;
    message: string;
    details: string?;
}

// ─── Guard ───────────────────────────────────────────────────────────────────

guard RateLimitGuard {
    tier: @rust;
    input: RateLimitRequest;
    output: RateLimitDecision;
    fail_mode: closed;
}

// ─── Service ─────────────────────────────────────────────────────────────────

@tier(php)
@fail_closed
service UserService implements UserServiceInterface {
    inject db: DatabaseConnection;
    inject hasher: PasswordHasher;

    guard RateLimitGuard;

    pub fn create(request: CreateUserRequest) -> Result<UserDto, ApiError> {
        // Adapter emits typed method with proper DI
    }

    pub fn findById(id: int) -> UserDto? {
        // Returns null if not found
    }

    pub fn list(page: int = 1, perPage: int = 20) -> UserDto[] {
        // Paginated list
    }

    pub fn delete(id: int) -> Result<bool, ApiError>
        throws NotFoundError
    {
        // Soft delete
    }
}

// ─── Routes ──────────────────────────────────────────────────────────────────

@auth(none)
route GET "/api/v1/users"
    -> @php UserController@list
    {
        middleware: [RateLimitMiddleware];
        dto: UserDto;
    };

@auth(none)
route GET "/api/v1/users/{id}"
    -> @php UserController@show
    {
        middleware: [RateLimitMiddleware];
        dto: UserDto;
    };

@auth(bearer)
@rate_limit(strict)
route POST "/api/v1/users"
    -> @php UserController@store
    {
        middleware: [AuthMiddleware, RateLimitMiddleware];
        dto: CreateUserRequest;
    };

@auth(bearer)
@rate_limit(strict)
route DELETE "/api/v1/users/{id}"
    -> @php UserController@destroy
    {
        middleware: [AuthMiddleware, RateLimitMiddleware];
    };

// ─── This would NOT compile: ─────────────────────────────────────────────────
//
// route POST "/api/v1/users"
//     -> @php UserController@store
//     {
//         middleware: [];
//     };
//
// ERROR: Mutating method POST on '/api/v1/users' requires @auth annotation.
//        Add @auth(bearer) or explicitly acknowledge with @auth(none).
