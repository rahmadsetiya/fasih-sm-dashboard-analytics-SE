<?php

namespace App\Http\Controllers;

use App\Models\PetugasName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PetugasNameController extends Controller
{
    public function upsert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            'display_name' => ['required', 'string', 'max:255'],
        ]);

        PetugasName::updateOrCreate(
            ['username' => $data['username']],
            ['display_name' => $data['display_name']],
        );

        return response()->json(['message' => 'Nama petugas disimpan.']);
    }

    public function destroy(string $username): JsonResponse
    {
        PetugasName::where('username', $username)->delete();

        return response()->json(['message' => 'Override nama dihapus.']);
    }

    public function index(): JsonResponse
    {
        return response()->json(PetugasName::pluck('display_name', 'username'));
    }
}
