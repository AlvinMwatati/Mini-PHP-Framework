<?php

declare(strict_types=1);

namespace App\Controllers;

use Core\Http\Request;
use Core\Http\Response;

/**
 * UserController — handles all /users routes.
 *
 * Controllers are simple classes. Each public method is an "action"
 * that handles one route. Every action receives:
 *   - Request $request   — the incoming HTTP request
 *   - array   $params    — named URI parameters (['id' => '42'])
 *
 * And must return a Response.
 *
 * This is a "thin controller" — the goal is to:
 *   1. Read from the Request
 *   2. Call a service/model (not built yet)
 *   3. Return a Response
 *
 * Business logic does NOT live in controllers. Controllers are
 * just the bridge between HTTP and your application logic.
 */
class UserController
{
    /**
     * GET /users
     * Return a list of all users.
     */
    public function index(Request $request, array $params = []): Response
    {
        // In a real app: $users = UserRepository::all();
        $users = [
            ['id' => 1, 'name' => 'Alice',   'email' => 'alice@example.com'],
            ['id' => 2, 'name' => 'Bob',     'email' => 'bob@example.com'],
            ['id' => 3, 'name' => 'Charlie', 'email' => 'charlie@example.com'],
        ];

        // If the client wants JSON (API client), return JSON.
        // If it wants HTML (browser), return HTML.
        if ($request->expectsJson()) {
            return Response::json(['data' => $users]);
        }

        $rows = implode('', array_map(
            fn($u) => "<tr><td>{$u['id']}</td><td>{$u['name']}</td><td>{$u['email']}</td></tr>",
            $users
        ));

        return Response::html(<<<HTML
        <html><head><style>
            body { font-family: sans-serif; max-width: 640px; margin: 40px auto; }
            table { width: 100%; border-collapse: collapse; }
            th, td { padding: 8px 12px; border: 1px solid #ddd; text-align: left; }
            th { background: #f4f4f4; }
        </style></head><body>
            <h1>Users</h1>
            <table><tr><th>ID</th><th>Name</th><th>Email</th></tr>{$rows}</table>
            <p><a href="/">← Home</a></p>
        </body></html>
        HTML);
    }

    /**
     * GET /users/{id}
     * Return a single user by ID.
     */
    public function show(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);

        // Fake database lookup
        $users = [
            1 => ['id' => 1, 'name' => 'Alice',   'email' => 'alice@example.com'],
            2 => ['id' => 2, 'name' => 'Bob',     'email' => 'bob@example.com'],
            3 => ['id' => 3, 'name' => 'Charlie', 'email' => 'charlie@example.com'],
        ];

        if (!isset($users[$id])) {
            if ($request->expectsJson()) {
                return Response::json(['error' => 'User not found'], 404);
            }
            return Response::html('<h1>404 — User not found</h1>', 404);
        }

        $user = $users[$id];

        if ($request->expectsJson()) {
            return Response::json(['data' => $user]);
        }

        return Response::html(<<<HTML
        <html><body style="font-family:sans-serif;max-width:640px;margin:40px auto">
            <h1>User #{$user['id']}</h1>
            <p><strong>Name:</strong> {$user['name']}</p>
            <p><strong>Email:</strong> {$user['email']}</p>
            <p><a href="/users">← All users</a></p>
        </body></html>
        HTML);
    }

    /**
     * POST /users
     * Create a new user.
     */
    public function store(Request $request, array $params = []): Response
    {
        // Read from JSON body (API) or form body (browser form)
        $data = $request->isJson()
            ? $request->json()
            : $request->allInput();

        $name  = trim((string) ($data['name']  ?? ''));
        $email = trim((string) ($data['email'] ?? ''));

        // Basic validation
        if ($name === '' || $email === '') {
            return Response::json([
                'error'  => 'Validation failed',
                'fields' => ['name' => 'required', 'email' => 'required'],
            ], 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::json([
                'error'  => 'Validation failed',
                'fields' => ['email' => 'must be a valid email address'],
            ], 422);
        }

        // Simulate creating the user (in a real app: $user = User::create($data))
        $newUser = ['id' => 4, 'name' => $name, 'email' => $email];

        return Response::json(['data' => $newUser], 201); // 201 Created
    }

    /**
     * DELETE /users/{id}
     * Delete a user by ID.
     */
    public function destroy(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);

        // Simulate deletion (in a real app: User::delete($id))
        // Return 204 No Content — success with no body
        return Response::noContent();
    }
}