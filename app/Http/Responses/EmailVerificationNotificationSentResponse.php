<?php

namespace App\Http\Responses;

use App\Services\VerificationEmail;
use Laravel\Fortify\Contracts\EmailVerificationNotificationSentResponse as ResponseContract;
use Laravel\Fortify\Fortify;

class EmailVerificationNotificationSentResponse implements ResponseContract
{
    public function toResponse($request)
    {
        $verification = app(VerificationEmail::class)->result();
        $sent = $verification['status'] === 'sent';

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $verification['message'],
                'verification' => $verification,
            ], $sent ? 202 : 503);
        }

        return $sent
            ? back()->with('status', Fortify::VERIFICATION_LINK_SENT)
            : back()->withErrors(['verification' => $verification['message']]);
    }
}
