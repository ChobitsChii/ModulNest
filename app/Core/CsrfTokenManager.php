<?php

declare(strict_types=1);

namespace Modulon\Core;

final class CsrfTokenManager
{
    public const SESSION_KEY = '_csrf_token';

    public function __construct(private readonly Session $session)
    {
    }

    /**
     * Liefert den Token der aktuellen Session und erzeugt ihn bei Bedarf.
     */
    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);
        if (is_string($token) && $token !== '') {
            return $token;
        }

        $token = bin2hex(random_bytes(32));
        $this->session->set(self::SESSION_KEY, $token);

        return $token;
    }

    public function validate(?string $token): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }

        $storedToken = $this->session->get(self::SESSION_KEY);
        return is_string($storedToken)
            && $storedToken !== ''
            && hash_equals($storedToken, $token);
    }

    /**
     * Ersetzt den aktuellen Token und gibt den neuen Wert zurück.
     */
    public function rotate(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->session->set(self::SESSION_KEY, $token);

        return $token;
    }

    public function invalidate(): void
    {
        $this->session->remove(self::SESSION_KEY);
    }
}
