<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $maxUsers = (int) env('MAX_USERS', 100);

        if (User::count() >= $maxUsers) {
            return response()->json([
                'success' => false,
                'message' => 'FoundIT registration is currently full.'
            ], 403);
        }

        $data = $request->validate([
            'name' => ['required','string','max:120'],
            'student_id' => ['nullable','string','max:50','unique:users,student_id'],
            'email' => ['required','email','max:120','unique:users,email'],
            'password' => ['required','string','min:8','confirmed'],
        ]);

        $email = strtolower(trim($data['email']));
        $domain = substr(strrchr($email, "@"), 1);
        if ($domain === 'bpsu.edu.ph') {
            $data['role'] = 'student';
            if (empty($data['student_id'])) {
                throw ValidationException::withMessages([
                    'student_id' => ['The student id field is required for BPSU student accounts.']
                ]);
            }
        } else {
            $data['role'] = 'guest';
            if (empty($data['student_id'])) {
                do {
                    $studentId = 'GUEST-' . strtoupper(\Illuminate\Support\Str::random(10));
                } while (User::where('student_id', $studentId)->exists());
                $data['student_id'] = $studentId;
            }
        }

        $user = User::create($data);
        $token = $user->createToken('FoundIT Android')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Registration successful.',
            'token' => $token,
            'user' => $user
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required','email'],
            'password' => ['required'],
            'device_name' => ['required','string','max:100'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.']
            ]);
        }

        $token = $user->createToken($data['device_name'])->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'token' => $token,
            'user' => $user
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        if ($request->filled('token')) {
            \App\Models\DeviceToken::where('user_id', $request->user()->id)
                ->where('token', $request->input('token'))
                ->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out.'
        ]);
    }

    public function registerDeviceToken(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();
        \App\Models\DeviceToken::updateOrCreate(
            ['token' => $data['token']],
            ['user_id' => $user->id]
        );

        return response()->json([
            'success' => true,
            'message' => 'Device token registered.'
        ]);
    }

    public function me(Request $request)
    {
        return $request->user();
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        if ($request->has('password') && empty($request->input('password'))) {
            $request->request->remove('password');
            $request->request->remove('password_confirmation');
        }

        $data = $request->validate([
            'name' => ['required','string','max:120'],
            'student_id' => ['required','string','max:50','unique:users,student_id,'.$user->id],
            'email' => ['required','email','max:120','unique:users,email,'.$user->id],
            'password' => ['nullable','string','min:8','confirmed'],
            'profile_image' => ['nullable','image','mimes:jpg,jpeg,png,webp','max:5120'],
            'delete_profile_image' => ['nullable','boolean'],
        ]);

        if ($request->filled('password')) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        if ($request->input('delete_profile_image') == '1' || $request->input('delete_profile_image') === true) {
            if ($user->profile_image) {
                Storage::disk('public')->delete($user->profile_image);
            }
            $data['profile_image'] = null;
        }

        if ($request->hasFile('profile_image')) {
            if ($user->profile_image) {
                Storage::disk('public')->delete($user->profile_image);
            }
            $data['profile_image'] = $request->file('profile_image')->store('profiles', 'public');
        }

        $user->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated.',
            'user' => $user->fresh()
        ]);
    }
}

