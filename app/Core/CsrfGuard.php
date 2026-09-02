<?php

declare(strict_types=1);

namespace Modulon\Core;

final class CsrfGuard
{
    public function __construct(private readonly CsrfTokenManager $tokenManager)
    {
    }

    /**
     * @param 'protect'|'exempt' $policy
     */
    public function handle(Request $request, string $policy): ?Response
    {
        if ($policy === 'exempt') {
            return null;
        }

        $token = $request->header('X-CSRF-Token') ?? $request->input('_csrf');
        if ($this->tokenManager->validate($token)) {
            return null;
        }

        return $this->invalidTokenResponse($request);
    }

    private function invalidTokenResponse(Request $request): Response
    {
        $message = 'Ungültiger Sicherheits-Token. Bitte Seite neu laden.';

        if ($request->expectsJson()) {
            $payload = json_encode([
                'error' => 'csrf_token_invalid',
                'message' => $message,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return new Response(
                is_string($payload) ? $payload : '{"error":"csrf_token_invalid"}',
                419,
                ['Content-Type' => 'application/json; charset=UTF-8'],
            );
        }

        return new Response(View::render('errors/419', [
            'title' => '419 Sicherheits-Token ungültig',
            'current_path' => $request->path(),
        ]), 419);
    }
}
