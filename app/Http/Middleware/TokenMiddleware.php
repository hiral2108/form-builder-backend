<?php

namespace App\Http\Middleware;

use App\Models\UserToken;
use Closure;
use Exception;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;

class TokenMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->header('Authorization', '');

        if (! $bearer) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $token = preg_replace('/^Bearer\s+/i', '', $bearer);

        try {
            // 1. Validate the JWT signature and expiration
            JWTAuth::setToken($token)->checkOrFail();
        } catch (Exception $e) {
            return response()->json(['error' => 'Token is Invalid or Expired'], 401);
        }

        // 2. Then check if it exists in your database
        if (! UserToken::where('user_token', $token)->exists()) {
            return response()->json(['error' => 'Invalid Token'], 401);
        }

        return $next($request);
    }
}
