<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;

final class LocaleService
{
    private const SUPPORTED_LOCALES = ['ms', 'en'];

    public function isSupported(?string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    public function resolve(Request $request): string
    {
        if ($request->user() && $this->isSupported($request->user()->preferred_locale)) {
            return $request->user()->preferred_locale;
        }

        if ($this->isSupported(session('locale'))) {
            return (string) session('locale');
        }

        $default = (string) config('app.locale');

        return $this->isSupported($default) ? $default : self::SUPPORTED_LOCALES[0];
    }

    public function persist(User $user, string $locale): void
    {
        abort_unless($this->isSupported($locale), 404);

        $user->preferred_locale = $locale;
        $user->save();
    }
}
