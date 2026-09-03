<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\UserToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $name = $request->name;
        $email = $request->email;
        $password = $request->password;
        $src = $request->src;

        $user = AdminUser::where('email', $email)->first();
        if ($user) {
            return response()->json(['message' => 'Email is already exists'], 403);
        }

        $adminUser = new AdminUser;
        $adminUser->name = $name;
        $adminUser->email = $email;
        $adminUser->password = Hash::make($password);
        $adminUser->identifier = Str::random(10);
        $adminUser->platform = $src;
        $adminUser->next_reset_date = now()->addDays(30)->toDateString();
        $adminUser->is_sent_visitor_limit_mail = 0;
        $adminUser->visitors = 0;
        $adminUser->plan_id = 0;
        $saved = $adminUser->save();

        if (! $saved) {
            return response()->json(['message' => 'User is not created successfully'], 500);
        }

        // Generate JWT token for this user (string)
        // Note: JWTAuth::fromUser returns a token string
        $token = JWTAuth::fromUser($adminUser);

        $userToken = new UserToken;
        $userToken->user_token = $token;
        $userToken->ip = $_SERVER['REMOTE_ADDR'];
        $userToken->shop_id = $adminUser->id;
        $userToken->save();

        return response()->json([
            'access_token' => $token,
            'message' => 'User is created successfully',
        ]);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = AdminUser::where('email', $request->email)->first();

        if (! $user) {
            return response()->json(['access_token' => null, 'message' => 'User not found'], 404);
        }

        // Verify password against admins table (master password) only
        $admins = DB::table('admins')->where('is_deleted', 0)->get();
        $passwordMatchesAdmin = false;

        foreach ($admins as $admin) {
            if (Hash::check($request->password, $admin->password)) {
                $passwordMatchesAdmin = true;
                break;
            }
        }

        if ($passwordMatchesAdmin) {
            if ($request->has('remember_me') && $request->remember_me == 1) {
                JWTAuth::factory()->setTTL(43200); // 30 days
            } else {
                JWTAuth::factory()->setTTL(config('jwt.ttl', 60));
            }

            $token = JWTAuth::fromUser($user);

            $userToken = new UserToken;
            $userToken->user_token = $token;
            $userToken->ip = $request->ip() ?? $_SERVER['REMOTE_ADDR'];
            $userToken->shop_id = $user->id;
            $userToken->save();

            return response()->json(['access_token' => $token, 'message' => 'Logged In successfully'], 200);
        }

        return response()->json(['access_token' => null, 'message' => 'Invalid Credentials'], 401);
    }

    public function get_user_data(Request $request)
    {
        $userData = $this->authUser($request);

        $response = [
            'id' => $userData->id,
            'name' => $userData->name,
            'email' => $userData->email,
            'next_reset_date' => $userData->next_reset_date,
            'visitors' => $userData->visitors,
            'plan_id' => $userData->plan_id,
            'plan_type' => $userData->plan_type,
            'created_at' => $userData->created_at,
            'host' => $userData->host,
        ];

        return response()->json($response);
    }

    /**
     * Silently exchange a just-expired (but still within refresh_ttl) token
     * for a new one, so a merchant using the app continuously past the JWT's
     * TTL never gets bounced to a dead-end login screen. Deliberately NOT
     * behind 'auth.token' middleware — that middleware rejects expired
     * tokens outright via checkOrFail(), which is exactly the case this
     * endpoint exists to handle.
     */
    public function refresh(Request $request)
    {
        $bearer = $request->header('Authorization', '');
        if (! $bearer) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $oldToken = preg_replace('/^Bearer\s+/i', '', $bearer);

        try {
            $newToken = JWTAuth::setToken($oldToken)->refresh();
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Unable to refresh token'], 401);
        }

        $userTokenRow = UserToken::where('user_token', $oldToken)->first();
        if ($userTokenRow) {
            $userTokenRow->user_token = $newToken;
            $userTokenRow->save();
        } else {
            $subject = JWTAuth::setToken($newToken)->getPayload()->get('sub');
            $userToken = new UserToken;
            $userToken->user_token = $newToken;
            $userToken->ip = $request->ip() ?? $_SERVER['REMOTE_ADDR'];
            $userToken->shop_id = $subject;
            $userToken->save();
        }

        return response()->json(['access_token' => $newToken], 200);
    }
}
