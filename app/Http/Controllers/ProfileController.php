<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\ProfileUpdateRequest;
use App\Services\UserAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(): View
    {
        $user = auth()->user();

        abort_unless($user, 403);

        return view('profile.edit', ['user' => $user]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = auth()->user();

        abort_unless($user, 403);

        $attributes = [
            'name' => $request->validated('name'),
        ];

        if ($request->has('email')) {
            $attributes['email'] = $request->validated('email');
        }

        if ($user->role === UserRole::Agent) {
            $attributes += [
                'phone' => $request->validated('phone'),
                'agent_code' => $request->validated('agent_code'),
                'company_name' => $request->validated('company_name'),
                'station_code' => $request->validated('station_code'),
            ];
        }

        $user->update($attributes);

        return redirect()->route('profile.edit')->with('status', __('profile.updated'));
    }

    public function password(): View
    {
        return view('profile.password');
    }

    public function updatePassword(ChangePasswordRequest $request, UserAccountService $service): RedirectResponse
    {
        $service->changePassword($request->user(), $request->validated('password'));
        $request->session()->regenerate(destroy: true);

        return redirect()->route('profile.password.edit')->with('status', __('profile.password_updated'));
    }
}
