<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(): View
    {
        $user = auth()->user();

        abort_unless($user && $user->role === UserRole::Agent, 403);

        return view('profile.edit', ['user' => $user]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = auth()->user();

        abort_unless($user && $user->role === UserRole::Agent, 403);

        $user->update([
            'name' => $request->validated('name'),
            'phone' => $request->validated('phone'),
            'agent_code' => $request->validated('agent_code'),
            'company_name' => $request->validated('company_name'),
            'station_code' => $request->validated('station_code'),
        ]);

        return redirect()->route('profile.edit')->with('status', __('profile.updated'));
    }
}
