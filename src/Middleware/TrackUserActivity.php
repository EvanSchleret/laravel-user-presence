<?php

namespace EvanSchleret\LaravelUserPresence\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackUserActivity
{
    /**
     * Handle an incoming request.
     *
     * @param \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $guardName = config('laravel-user-presence.guard');

        if (\App\Http\Middleware\auth()->guard($guardName)->check()) {
            $user = $request->user();

            $user->last_activity_at = now();
            $user->timestamps = false;
            $user->save();
        }

        return $next($request);
    }
}
