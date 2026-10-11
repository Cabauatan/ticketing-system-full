<?php

namespace App\Http\Responses;

use App\Services\VerificationEmail;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Fortify;

class RegisterResponse implements RegisterResponseContract
{
    public function toResponse($request)
    {
        $verification = app(VerificationEmail::class)->result();

        return $request->wantsJson()
            ? response()->json([
                'message' => 'Your account has been created.',
                'verification' => $verification,
            ], 201)
            : redirect()->intended(Fortify::redirects('register'))->with('verification', $verification);
    }
}
