<?php

namespace EvanSchleret\LaravelUserPresence\Http\Controllers;

use EvanSchleret\LaravelUserPresence\Services\PresenceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HeartbeatController
{
    public function __invoke(Request $request, PresenceService $presence): Response
    {
        $user = $request->user();

        if ($user instanceof Model && method_exists($user, 'updatePresenceStatus')) {
            $presence->recordRequestActivity($user, $request);
        }

        return response()->noContent();
    }
}
