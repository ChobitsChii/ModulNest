<?php

declare(strict_types=1);

namespace Modulon\Core;

use Closure;
use PDO;

final class Application
{
    public function __construct(
        private readonly Router $router,
        private readonly ?PDO $database = null,
        private readonly ?Closure $requestBootstrap = null,
    ) {
    }

    /**
     * Startet den Request-Lebenszyklus.
     */
    public function run(): void
    {
        $request = Request::fromGlobals();
        if ($this->requestBootstrap !== null) {
            ($this->requestBootstrap)($request);
        }

        $response = $this->router->dispatch($request);
        $response->send($request->method() !== 'HEAD');
    }

    public function database(): ?PDO
    {
        return $this->database;
    }
}
