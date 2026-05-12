<?php

declare(strict_types=1);

namespace Modulon\Modules\SneakPreview;

use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Session;
use Modulon\Core\View;
use Modulon\Modules\Auth\AuthService;

final class SneakPreviewController
{
    public function __construct(
        private readonly SneakPreviewRepository $repository,
        private readonly SneakPreviewTmdbService $tmdb,
        private readonly Session $session,
        private readonly ?AuthService $auth = null,
    ) {
    }

    public function index(Request $request): Response
    {
        return new Response(View::render('sneak-preview/index', [
            'title' => 'Sneak Preview',
            'current_path' => $request->path(),
            'movies' => $this->repository->allMovies(),
            'fields' => $this->repository->displayFields(),
        ]));
    }

    public function adminIndex(Request $request): Response
    {
        return new Response(View::render('sneak-preview/admin', $this->adminViewData($request, [
            'movies' => $this->repository->allMovies(),
            'fields' => $this->repository->displayFields(),
            'delete_token' => $this->token('sneak_preview_delete_token'),
            'message' => $this->session->pullFlash('sneak_preview_info'),
            'error' => $this->session->pullFlash('sneak_preview_error'),
        ])));
    }

    public function adminSubRoute(Request $request): Response
    {
        $path = trim($request->path(), '/');
        if ($path === 'admin/sneak-preview/new') {
            return $this->form($request, null);
        }
        if (preg_match('~^admin/sneak-preview/([0-9]+)/edit$~', $path, $matches) === 1) {
            return $this->form($request, (int) $matches[1]);
        }
        if ($path === 'admin/sneak-preview/settings') {
            return $this->settings($request);
        }
        if ($path === 'admin/sneak-preview/tmdb') {
            return $this->tmdb($request);
        }

        return new Response(View::render('errors/404', [
            'title' => '404 Not Found',
            'current_path' => $request->path(),
        ]), 404);
    }

    public function save(Request $request): Response
    {
        if (!$this->checkToken('sneak_preview_form_token', (string) $request->input('csrf_token', ''))) {
            $this->session->flash('sneak_preview_error', 'Der Formular-Token ist ungültig. Bitte Seite neu laden.');
            return Response::redirect('/admin/sneak-preview');
        }

        $movies = $request->inputRaw('movies', []);
        if (!is_array($movies)) {
            $movies = [];
        }

        $saved = 0;
        $adminId = $this->currentUserId();
        foreach ($movies as $movie) {
            if (!is_array($movie)) {
                continue;
            }
            $normalized = $this->normalizeMovieInput($movie);
            if ($normalized['title'] === '' || $normalized['sneak_date'] === '') {
                continue;
            }

            if (!empty($movie['save_poster_local']) && $this->repository->savePostersLocally()) {
                $tmdbId = is_numeric($normalized['tmdb_id']) ? (int) $normalized['tmdb_id'] : null;
                $poster = $this->tmdb->downloadPoster($normalized['poster_tmdb_path'], $tmdbId);
                if ($poster !== null) {
                    $normalized['poster_path'] = $poster;
                }
            }

            $this->repository->saveMovie($normalized, $adminId);
            $saved++;
        }

        $this->session->flash(
            $saved > 0 ? 'sneak_preview_info' : 'sneak_preview_error',
            $saved > 0 ? $saved . ' Eintrag/Einträge gespeichert.' : 'Es wurde kein gültiger Eintrag gespeichert.'
        );

        return Response::redirect('/admin/sneak-preview');
    }

    public function delete(Request $request): Response
    {
        if (!$this->checkToken('sneak_preview_delete_token', (string) $request->input('csrf_token', ''))) {
            $this->session->flash('sneak_preview_error', 'Der Lösch-Token ist ungültig. Bitte Seite neu laden.');
            return Response::redirect('/admin/sneak-preview');
        }

        $id = (int) $request->input('id', '0');
        $deleted = $id > 0 ? $this->repository->deleteMovie($id) : 0;
        $this->session->flash(
            $deleted > 0 ? 'sneak_preview_info' : 'sneak_preview_error',
            $deleted > 0 ? 'Eintrag gelöscht.' : 'Eintrag wurde nicht gefunden.'
        );

        return Response::redirect('/admin/sneak-preview');
    }

    public function saveSettings(Request $request): Response
    {
        if (!$this->checkToken('sneak_preview_settings_token', (string) $request->input('csrf_token', ''))) {
            $this->session->flash('sneak_preview_error', 'Der Einstellungs-Token ist ungültig. Bitte Seite neu laden.');
            return Response::redirect('/admin/sneak-preview/settings');
        }

        $catalog = $this->repository->displayCatalog();
        $input = $request->inputRaw('fields', []);
        $fields = [];
        foreach ($catalog as $key => $_label) {
            $row = is_array($input[$key] ?? null) ? $input[$key] : [];
            $fields[$key] = [
                'table' => !empty($row['table']),
                'lightbox' => !empty($row['lightbox']),
                'admin' => !empty($row['admin']),
            ];
        }
        $this->repository->saveDisplayFields($fields);
        $this->repository->setSetting('save_posters_locally', $request->input('save_posters_locally', '') === '1' ? '1' : '0');

        $apiKey = trim((string) $request->input('tmdb_api_key', ''));
        if ($apiKey !== '') {
            $this->repository->setSetting('tmdb_api_key', $apiKey);
        }

        $this->session->flash('sneak_preview_info', 'Einstellungen gespeichert.');
        return Response::redirect('/admin/sneak-preview/settings');
    }

    private function form(Request $request, ?int $id): Response
    {
        $movie = $id !== null ? $this->repository->findMovie($id) : null;
        if ($id !== null && $movie === null) {
            $this->session->flash('sneak_preview_error', 'Eintrag wurde nicht gefunden.');
            return Response::redirect('/admin/sneak-preview');
        }

        return new Response(View::render('sneak-preview/form', $this->adminViewData($request, [
            'movie' => $movie,
            'locations' => $this->repository->locations(),
            'token' => $this->token('sneak_preview_form_token'),
            'has_tmdb_api_key' => $this->repository->hasTmdbApiKey(),
        ])));
    }

    private function settings(Request $request): Response
    {
        return new Response(View::render('sneak-preview/settings', $this->adminViewData($request, [
            'fields' => $this->repository->displayFields(),
            'catalog' => $this->repository->displayCatalog(),
            'save_posters_locally' => $this->repository->savePostersLocally(),
            'has_tmdb_api_key' => $this->repository->hasTmdbApiKey(),
            'masked_tmdb_api_key' => $this->repository->maskedTmdbApiKey(),
            'token' => $this->token('sneak_preview_settings_token'),
            'message' => $this->session->pullFlash('sneak_preview_info'),
            'error' => $this->session->pullFlash('sneak_preview_error'),
        ])));
    }

    private function tmdb(Request $request): Response
    {
        $tmdbId = $request->query('tmdb_id');
        $payload = $tmdbId !== null && ctype_digit($tmdbId)
            ? $this->tmdb->details((int) $tmdbId)
            : $this->tmdb->search((string) $request->query('q', ''));

        return new Response(json_encode($payload, JSON_THROW_ON_ERROR), 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    /**
     * @param array<string, mixed> $movie
     * @return array<string, mixed>
     */
    private function normalizeMovieInput(array $movie): array
    {
        return [
            'id' => (int) ($movie['id'] ?? 0),
            'sneak_date' => trim((string) ($movie['sneak_date'] ?? '')),
            'title' => trim((string) ($movie['title'] ?? '')),
            'location' => trim((string) ($movie['location'] ?? '')),
            'release_date_de' => trim((string) ($movie['release_date_de'] ?? '')),
            'poster_path' => trim((string) ($movie['poster_path'] ?? '')),
            'poster_tmdb_path' => trim((string) ($movie['poster_tmdb_path'] ?? '')),
            'tmdb_id' => trim((string) ($movie['tmdb_id'] ?? '')),
            'overview' => trim((string) ($movie['overview'] ?? '')),
            'genres' => trim((string) ($movie['genres'] ?? '')),
            'runtime' => trim((string) ($movie['runtime'] ?? '')),
            'certification' => trim((string) ($movie['certification'] ?? '')),
            'original_language' => trim((string) ($movie['original_language'] ?? '')),
            'production_countries' => trim((string) ($movie['production_countries'] ?? '')),
            'vote_average' => trim((string) ($movie['vote_average'] ?? '')),
            'trailer_key' => trim((string) ($movie['trailer_key'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function adminViewData(Request $request, array $extra): array
    {
        return array_merge([
            'title' => 'Sneak Preview',
            'current_path' => $request->path(),
            'admin_section' => 'sneak-preview',
        ], $extra);
    }

    private function currentUserId(): int
    {
        $user = $this->auth?->currentUser();
        return is_array($user) ? (int) ($user['id'] ?? 0) : 0;
    }

    private function token(string $key): string
    {
        $token = (string) $this->session->get($key, '');
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
            $this->session->set($key, $token);
        }

        return $token;
    }

    private function checkToken(string $key, string $submitted): bool
    {
        $expected = (string) $this->session->get($key, '');
        return $expected !== '' && hash_equals($expected, $submitted);
    }
}
