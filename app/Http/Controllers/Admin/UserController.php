<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        $this->authorize('admin');

        return Inertia::render('admin/Users', [
            'users' => User::orderBy('name')
                ->get()
                ->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'is_admin' => $u->is_admin,
                    'created_at' => $u->created_at?->toDateTimeString(),
                ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('admin');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', Password::defaults()],
            'is_admin' => ['boolean'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'is_admin' => $data['is_admin'] ?? false,
        ]);

        return response()->json(['message' => 'User berhasil dibuat.', 'user' => $user->only('id', 'name', 'email', 'is_admin', 'created_at')], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorize('admin');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', "unique:users,email,{$user->id}"],
            'password' => ['nullable', 'string', Password::defaults()],
            'is_admin' => ['boolean'],
        ]);

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->is_admin = $data['is_admin'] ?? $user->is_admin;
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        return response()->json(['message' => 'User diperbarui.']);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('admin');

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'Tidak bisa menghapus akun sendiri.'], 422);
        }

        $user->delete();

        return response()->json(['message' => 'User dihapus.']);
    }
}
