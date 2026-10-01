<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /** Usage: ->middleware('role:owner,supervisor') */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        if (! $user || ! $user->is_active) {
            abort(403, 'Inactive or unauthenticated.');
        }

        // owner implicitly satisfies supervisor-level requirements.
        $effective = $user->role === 'owner' ? ['owner', 'supervisor'] : [$user->role];

        if (! array_intersect($roles, $effective)) {
            abort(403, 'You do not have access to this area.');
        }

        return $next($request);
    }
}
