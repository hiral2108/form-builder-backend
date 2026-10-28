<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * The AdminUser resolved by TokenMiddleware for this request. Routes
     * behind the 'auth.token' middleware are guaranteed to have this set —
     * the 401 here only fires if a route uses this helper without actually
     * being behind that middleware.
     */
    protected function authUser(Request $request): AdminUser
    {
        $user = $request->attributes->get('authUser');

        abort_unless($user instanceof AdminUser, 401, 'Unauthorized');

        return $user;
    }
}
