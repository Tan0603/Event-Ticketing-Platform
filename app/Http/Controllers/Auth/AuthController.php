<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\LoginStaff;
use App\Actions\Auth\LogoutStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\AuthTokenResource;
use App\Models\Tenant\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function login(LoginRequest $request, LoginStaff $login): AuthTokenResource
    {
        return new AuthTokenResource($login->execute($request->validated('email'), $request->validated('password')));
    }

    public function logout(Request $request, LogoutStaff $logout): Response
    {
        /** @var User $user */
        $user = $request->user();
        $logout->execute($user);

        return response()->noContent();
    }
}
