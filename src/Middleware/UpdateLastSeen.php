<?php

namespace EvanSchleret\LaravelUserPresence\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class UpdateLastSeen
{
    /**
     * Handle an incoming request.
     *
     * @param \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response) $next
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        $guardName = config('user-presence.guard', null);
        $user = $guardName ? Auth::guard($guardName)->user() : Auth::user();

        if ($user && method_exists($user, 'updatePresenceStatus')) {
            $user->updatePresenceStatus();
        }

        return $response;
    }
}
