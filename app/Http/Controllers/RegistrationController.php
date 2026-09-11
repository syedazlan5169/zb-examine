<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegistrationRequest;
use App\Services\UserAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegistrationController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegistrationRequest $request, UserAccountService $service): RedirectResponse
    {
        $user = $service->register($request->safe()->only(['name', 'username', 'email', 'password']));

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('examinations.create'));
    }
}
