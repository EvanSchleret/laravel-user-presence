<?php

namespace EvanSchleret\LaravelUserPresence\Middleware;

use Closure;
use EvanSchleret\LaravelUserPresence\Services\PresenceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class UpdateLastSeen
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('user-presence.middleware.enabled', true)) {
            return $response;
        }

        $guardName = config('user-presence.guard', null);
        $user = $guardName ? Auth::guard($guardName)->user() : Auth::user();

        if ($user instanceof Model && method_exists($user, 'updatePresenceStatus')) {
            app(PresenceService::class)->recordRequestActivity($user, $request);
        }

        return $response;
    }
}
