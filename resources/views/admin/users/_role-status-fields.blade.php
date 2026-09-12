<div>
    <label for="role" class="mb-1 block text-sm font-medium text-gray-900">{{ __('users.role') }}</label>
    <select name="role" id="role" class="min-h-11 w-full rounded-md border-2 border-gray-300 px-4 py-3 text-base focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-gray-900">
        @foreach ($roles as $role)
            <option value="{{ $role->value }}" @selected(old('role', $user->role->value ?? 'agent') === $role->value)>{{ __('users.roles.'.$role->value) }}</option>
        @endforeach
    </select>
</div>
<label class="inline-flex min-h-11 items-center gap-2 text-sm font-medium text-gray-900">
    <input type="hidden" name="is_active" value="0">
    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active ?? true))>
    {{ __('users.active') }}
</label>