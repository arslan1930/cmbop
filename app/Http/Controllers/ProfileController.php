<?php

namespace App\Http\Controllers;

use App\Mail\PasswordChangedMail;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function update(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => ['nullable', 'string', 'max:50', 'regex:/^[0-9+\-\(\)\s]*$/'],
        ], [
            'name.required' => 'Please enter your full name.',
            'name.max' => 'Name cannot exceed 255 characters.',
            'phone.regex' => 'Phone number can only contain numbers, spaces, +, -, ().',
            'phone.max' => 'Phone number cannot exceed 50 characters.',
        ]);

        $user = auth()->user();

        try {
            $user->name = $request->name;
            $user->phone = $request->phone;
            $user->save();
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', UserFacingError::message($e, 'We could not update your profile. Please try again.'));
        }

        return back()->with('success', 'Profile updated successfully.');
    }

    public function password(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'current_password.required' => 'Please enter your current password.',
            'password.required' => 'Please enter a new password.',
            'password.confirmed' => 'Password confirmation does not match.',
        ]);

        $user = auth()->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return back()->with('error', 'Current password is incorrect.');
        }

        try {
            // Hashed cast hashes once — do not Hash::make here or login breaks.
            $user->password = $request->password;
            $user->save();
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', UserFacingError::message($e, 'We could not change your password. Please try again.'));
        }

        if (class_exists(PasswordChangedMail::class) && method_exists(PasswordChangedMail::class, 'notify')) {
            PasswordChangedMail::notify($user);
        }

        try {
            Auth::logoutOtherDevices($request->password);
            $request->session()->regenerate();
        } catch (\Throwable $e) {
            Log::warning('Could not sign out other devices after password change', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        return back()->with('success', 'Password changed successfully.');
    }

    public function social(Request $request)
    {
        $request->validate([
            'facebook' => 'nullable|url|max:255',
            'twitter' => 'nullable|url|max:255',
            'linkedin' => 'nullable|url|max:255',
        ], [
            'facebook.url' => 'Please enter a valid Facebook URL.',
            'twitter.url' => 'Please enter a valid Twitter URL.',
            'linkedin.url' => 'Please enter a valid LinkedIn URL.',
        ]);

        $user = auth()->user();

        try {
            $user->facebook = $request->facebook;
            $user->twitter = $request->twitter;
            $user->linkedin = $request->linkedin;
            $user->save();
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', UserFacingError::message($e, 'We could not update your social links. Please try again.'));
        }

        return back()->with('success', 'Social links updated successfully.');
    }

    public function billing(Request $request)
    {
        $request->validate([
            'billing_name' => 'nullable|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'postal_code' => ['nullable', 'regex:/^[0-9]+$/', 'max:20'],
            'vat_number' => 'nullable|string|max:100',
        ], [
            'billing_name.max' => 'Billing name cannot exceed 255 characters.',
            'company_name.max' => 'Company name cannot exceed 255 characters.',
            'country.max' => 'Country cannot exceed 255 characters.',
            'state.max' => 'State cannot exceed 255 characters.',
            'city.max' => 'City cannot exceed 255 characters.',
            'address.max' => 'Address cannot exceed 255 characters.',
            'postal_code.regex' => 'Postal code can only contain numbers.',
            'postal_code.max' => 'Postal code cannot exceed 20 characters.',
            'vat_number.max' => 'VAT number cannot exceed 100 characters.',
        ]);

        $user = auth()->user();

        if ($user->company_name && $request->company_name && $request->company_name !== $user->company_name) {
            return back()->with('error', 'Company name cannot be changed once set.');
        }

        if (! $user->company_name && $request->company_name) {
            $user->company_name = trim($request->company_name);
        }

        try {
            $user->billing_name = $request->billing_name;
            $user->country = $request->country;
            $user->state = $request->state;
            $user->city = $request->city;
            $user->address = $request->address;
            $user->postal_code = $request->postal_code;
            $user->vat_number = $request->vat_number;
            $user->save();
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', UserFacingError::message($e, 'We could not update your billing information. Please try again.'));
        }

        return back()->with('success', 'Billing information updated successfully.');
    }
}
