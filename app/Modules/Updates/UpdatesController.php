<?php

declare(strict_types=1);

namespace Modulon\Modules\Updates;

use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Session;
use Modulon\Core\View;
use Throwable;

final class UpdatesController
{
    private const TOKEN_KEY = 'updates_csrf_token';

    public function __construct(
        private readonly UpdatesService $updates,
        private readonly Session $session,
        private readonly string $installedVersion,
        private readonly string $channel,
    ) {
    }

    public function index(Request $request): Response
    {
        return new Response(View::render('updates/admin', [
            'title' => 'Updates',
            'current_path' => $request->path(),
            'admin_section' => 'updates',
            'csrf_token' => $this->token(),
            'message' => $this->session->pullFlash('updates_info'),
            'error' => $this->session->pullFlash('updates_error'),
            'status' => $this->updates->status($this->installedVersion, $this->channel),
        ]));
    }

    public function check(Request $request): Response
    {
        if (!$this->validToken((string) $request->input('csrf_token', ''))) {
            return $this->redirectError('Ungültiger Sicherheits-Token.');
        }

        try {
            $result = $this->updates->check($this->installedVersion);
            $this->session->flash('updates_info', (string) ($result['message'] ?? 'Update-Prüfung abgeschlossen.'));
        } catch (Throwable $exception) {
            $this->session->flash('updates_error', $exception->getMessage());
        }

        return Response::redirect('/admin/updates');
    }

    public function prepare(Request $request): Response
    {
        if (!$this->validToken((string) $request->input('csrf_token', ''))) {
            return $this->redirectError('Ungültiger Sicherheits-Token.');
        }

        try {
            $result = $this->updates->prepare($this->installedVersion);
            $this->session->flash('updates_info', 'Update ' . (string) ($result['version'] ?? '') . ' wurde heruntergeladen, geprüft und vorbereitet.');
        } catch (Throwable $exception) {
            $this->session->flash('updates_error', $exception->getMessage());
        }

        return Response::redirect('/admin/updates');
    }

    public function install(Request $request): Response
    {
        if (!$this->validToken((string) $request->input('csrf_token', ''))) {
            return $this->redirectError('Ungültiger Sicherheits-Token.');
        }

        try {
            $result = $this->updates->install();
            $this->session->flash(
                'updates_info',
                'Update auf ' . (string) ($result['version'] ?? '') . ' installiert. Backup: ' . (string) ($result['backup_path'] ?? '')
            );
        } catch (Throwable $exception) {
            $this->session->flash('updates_error', $exception->getMessage());
        }

        return Response::redirect('/admin/updates');
    }

    private function redirectError(string $message): Response
    {
        $this->session->flash('updates_error', $message);

        return Response::redirect('/admin/updates');
    }

    private function token(): string
    {
        $token = $this->session->get(self::TOKEN_KEY);
        if (is_string($token) && $token !== '') {
            return $token;
        }

        $token = bin2hex(random_bytes(32));
        $this->session->set(self::TOKEN_KEY, $token);

        return $token;
    }

    private function validToken(string $submitted): bool
    {
        $token = $this->session->get(self::TOKEN_KEY);

        return is_string($token) && $token !== '' && hash_equals($token, $submitted);
    }
}
