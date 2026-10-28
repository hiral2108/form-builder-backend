<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;

class TokenMiddleware
{
    /**
     * Handle an incoming request.
     *
     * Resolves the AdminUser owning this token once here and attaches it to
     * the request as the 'authUser' attribute — every controller behind this
     * middleware previously re-ran this exact same token-extraction + lookup
     * itself (duplicated ~15 times across 6 controllers). Use
     * Controller::authUser($request) to read it instead of re-querying.
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

        // 2. Then resolve the account that owns this token
        $authUser = AdminUser::select(DB::raw('admin_users.*,user_tokens.user_token AS unique_key'))
            ->leftJoin('user_tokens', 'user_tokens.shop_id', '=', 'admin_users.id')
            ->where('user_tokens.user_token', $token)
            ->first();

        if (! $authUser) {
            return response()->json(['error' => 'Invalid Token'], 401);
        }

        $request->attributes->set('authUser', $authUser);

        return $next($request);
    }
}
