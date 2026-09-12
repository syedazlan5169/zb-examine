<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Services\RoleHomeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request, RoleHomeResolver $roleHomeResolver): RedirectResponse
    {
        if (! Auth::attempt($request->credentials())) {
            return back()
                ->withErrors(['username' => __('auth.failed')])
                ->onlyInput('username');
        }

        $request->session()->regenerate();

        return redirect()->intended(route($roleHomeResolver->routeName($request->user())));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
