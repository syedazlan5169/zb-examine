<?php

namespace App\Http\Middleware;

use App\Services\LocaleService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function __construct(private readonly LocaleService $locales) {}

    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->locales->resolve($request));

        return $next($request);
    }
}
