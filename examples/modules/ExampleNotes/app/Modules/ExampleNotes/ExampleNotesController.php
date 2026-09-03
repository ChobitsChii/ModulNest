<?php

declare(strict_types=1);

namespace Modulon\Modules\ExampleNotes;

use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Session;
use Modulon\Core\View;
use Modulon\Modules\Auth\AuthService;

final class ExampleNotesController
{
    public function __construct(private readonly ExampleNotesService $service, private readonly ?AuthService $auth, private readonly Session $session) {}

    public function index(Request $request): Response
    {
        return new Response(View::render('example-notes/index', $this->viewData($request, [
            'title' => $this->service->title(),
            'hint' => $this->service->hint(),
            'notes' => $this->service->notesForUser($this->userId()),
            'message' => $this->session->pullFlash('example_notes_info'),
            'error' => $this->session->pullFlash('example_notes_error'),
        ])));
    }

    public function create(Request $request): Response
    {
        try {
            $this->service->create($this->userId(), (string) $request->input('title', ''));
            $this->session->flash('example_notes_info', 'Notiz erstellt.');
        } catch (\InvalidArgumentException $exception) {
            $this->session->flash('example_notes_error', $exception->getMessage());
        }
        return Response::redirect('/example-notes');
    }

    public function toggle(Request $request): Response
    {
        $changed = $this->service->toggle($this->userId(), (int) $request->input('note_id', '0'));
        $body = json_encode(['ok' => $changed], JSON_UNESCAPED_SLASHES);
        return new Response(is_string($body) ? $body : '{"ok":false}', $changed ? 200 : 404, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    public function admin(Request $request): Response
    {
        return new Response(View::render('example-notes/admin', $this->viewData($request, [
            'title' => 'Example Notes – Administration',
            'hint' => $this->service->hint(),
            'message' => $this->session->pullFlash('example_notes_admin_info'),
        ])));
    }

    public function saveSettings(Request $request): Response
    {
        try {
            $this->service->saveHint((string) $request->input('hint', ''));
            $this->session->flash('example_notes_admin_info', 'Hinweis gespeichert.');
        } catch (\InvalidArgumentException $exception) {
            $this->session->flash('example_notes_admin_info', $exception->getMessage());
        }
        return Response::redirect('/admin/example-notes');
    }

    private function userId(): int { return (int) (($this->auth?->currentUser()['id'] ?? 0) ?: 0); }
    private function viewData(Request $request, array $data): array { return $data + ['current_path' => $request->path()]; }
}
