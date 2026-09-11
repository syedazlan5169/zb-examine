<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Exceptions\UserAccountInvariantViolation;
use App\Http\Requests\AdminCreateUserRequest;
use App\Http\Requests\AdminResetPasswordRequest;
use App\Http\Requests\AdminUpdateUserRequest;
use App\Models\User;
use App\Services\UserAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminUserController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', User::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $search = trim((string) ($validated['search'] ?? ''));

        $users = User::query()
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(function ($query) use ($like): void {
                    $query->where('name', 'like', $like)
                        ->orWhere('username', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            })
            ->when(isset($validated['role']), fn ($query) => $query->where('role', UserRole::from($validated['role'])->value))
            ->when(isset($validated['is_active']), fn ($query) => $query->where('is_active', (bool) $validated['is_active']))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.users.index', compact('users', 'search'));
    }

    public function create(): View
    {
        Gate::authorize('create', User::class);

        return view('admin.users.create', ['roles' => UserRole::cases()]);
    }

    public function store(AdminCreateUserRequest $request, UserAccountService $service): RedirectResponse
    {
        try {
            $service->create($request->user(), [
                ...$request->safe()->only(['name', 'username', 'email', 'password']),
                'role' => UserRole::from($request->validated('role')),
                'is_active' => (bool) $request->validated('is_active'),
            ]);
        } catch (UserAccountInvariantViolation $exception) {
            return back()->withInput()->withErrors(['account' => __('users.errors.'.$exception->errorCode)]);
        }

        return redirect()->route('admin.users.index')->with('status', __('users.created'));
    }

    public function edit(User $user): View
    {
        Gate::authorize('view', $user);

        return view('admin.users.edit', ['user' => $user, 'roles' => UserRole::cases()]);
    }

    public function update(AdminUpdateUserRequest $request, User $user, UserAccountService $service): RedirectResponse
    {
        try {
            $service->update($request->user(), $user, [
                ...$request->safe()->only(['name', 'username', 'email']),
                'role' => UserRole::from($request->validated('role')),
                'is_active' => (bool) $request->validated('is_active'),
            ]);
        } catch (UserAccountInvariantViolation $exception) {
            return back()->withInput()->withErrors(['account' => __('users.errors.'.$exception->errorCode)]);
        }

        return redirect()->route('admin.users.edit', $user)->with('status', __('users.updated'));
    }

    public function password(User $user): View
    {
        Gate::authorize('resetPassword', $user);

        return view('admin.users.password', compact('user'));
    }

    public function resetPassword(AdminResetPasswordRequest $request, User $user, UserAccountService $service): RedirectResponse
    {
        try {
            $service->resetPassword($request->user(), $user, $request->validated('password'));
        } catch (UserAccountInvariantViolation $exception) {
            return back()->withInput()->withErrors(['account' => __('users.errors.'.$exception->errorCode)]);
        }

        return redirect()->route('admin.users.edit', $user)->with('status', __('users.password_reset'));
    }
}
