<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function edit()
    {
        return view('admin.profile', ['user' => auth()->user()]);
    }

    public function update(Request $request)
    {
        $user = auth()->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => ['nullable', 'required_with:password', 'current_password'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ], [
            'current_password.current_password' => 'Your current password is incorrect.',
        ]);

        $user->name = $data['name'];
        $user->email = $data['email'];
        if (filled($data['password'] ?? null)) {
            $user->password = $data['password']; // hashed by cast
        }
        $user->save();

        return back()->with('success', 'Profile updated.');
    }
}
