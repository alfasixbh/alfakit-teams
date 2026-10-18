<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Http\Middleware;

use AlfaSix\AlfakitTeams\Teams;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guests opening an invitation link sign in (existing accounts) or register (new
 * addresses, when the panel has registration), then come back to the accept page.
 */
class RedirectGuestsToInvitationAuth
{
    public const SESSION_EMAIL = 'alfakit-teams.invitation_email';

    public function handle(Request $request, Closure $next, string $panelId): Response
    {
        $panel = alfakit()->getPanel($panelId);

        if ($panel->auth()->check()) {
            return $next($request);
        }

        $invitation = Teams::invitationModel()::query()->find($request->route('invitation'));

        abort_if($invitation === null, 404);

        $request->session()->put('url.intended', $request->fullUrl());
        $request->session()->put(self::SESSION_EMAIL, $invitation->email);

        $hasAccount = Teams::userModel()::query()->whereRaw('lower(email) = ?', [mb_strtolower($invitation->email)])->exists();
        $url = ! $hasAccount && $panel->hasRegistration() ? $panel->getRegistrationUrl() : $panel->getLoginUrl();

        abort_if($url === null, 403);

        return redirect()->to($url);
    }
}
