<?php

declare(strict_types=1);

namespace Modulon\Modules\Updates;

use DateTimeZone;
use Modulon\Core\DateTimeFormatter;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Session;
use Modulon\Core\View;
use Modulon\Modules\Admin\AppSettingRepository;
use Modulon\Modules\Auth\AuthService;
use Throwable;

final class UpdatesController
{
    public function __construct(
        private readonly UpdatesService $updates,
        private readonly Session $session,
        private readonly string $installedVersion,
        private readonly string $channel,
        private readonly ?AuthService $auth = null,
        private readonly ?AppSettingRepository $settings = null,
    ) {
    }

    public function index(Request $request): Response
    {
        $tab = (string) $request->query('tab', 'updates');
        $activeTab = in_array($tab, ['updates', 'sources', 'mirror'], true) ? ($tab === 'mirror' ? 'sources' : $tab) : 'updates';

        return new Response(View::render('updates/admin', [
            'title' => 'Updates',
            'current_path' => $request->path(),
            'admin_section' => 'updates',
            'active_tab' => $activeTab,
            'message' => $this->session->pullFlash('updates_info'),
            'error' => $this->session->pullFlash('updates_error'),
            'status' => $this->viewStatus(),
            'sources' => $this->updates->getUpdateSources(),
            'active_source' => $this->updates->getActiveUpdateSource(),
            'mirror_status' => $this->updates->mirrorStatus(),
        ]));
    }

    public function check(Request $request): Response
    {
        try {
            $result = $this->updates->check($this->installedVersion, $this->updateChannel());
            $this->session->flash('updates_info', (string) ($result['message'] ?? 'Update-Prüfung abgeschlossen.'));
        } catch (Throwable $exception) {
            $this->session->flash('updates_error', $exception->getMessage());
        }

        return Response::redirect('/admin/updates?tab=updates');
    }

    public function prepare(Request $request): Response
    {
        try {
            $result = $this->updates->prepare($this->installedVersion, $this->updateChannel());
            $this->session->flash('updates_info', 'Update ' . (string) ($result['version'] ?? '') . ' wurde heruntergeladen, geprüft und vorbereitet.');
        } catch (Throwable $exception) {
            $this->session->flash('updates_error', $exception->getMessage());
        }

        return Response::redirect('/admin/updates?tab=updates');
    }

    public function install(Request $request): Response
    {
        try {
            $result = $this->updates->install();
            $this->session->flash(
                'updates_info',
                'Update auf ' . (string) ($result['version'] ?? '') . ' installiert. Backup: ' . (string) ($result['backup_path'] ?? '')
            );
        } catch (Throwable $exception) {
            $this->session->flash('updates_error', $exception->getMessage());
        }

        return Response::redirect('/admin/updates?tab=updates');
    }

    public function addSource(Request $request): Response
    {
        try {
            $name = (string) $request->input('name', '');
            $baseUrl = (string) $request->input('base_url', '');
            $this->updates->addUpdateSource($name, $baseUrl);
            $this->session->flash('updates_info', 'Update-Quelle "' . $name . '" erfolgreich hinzugefügt.');
        } catch (Throwable $exception) {
            $this->session->flash('updates_error', $exception->getMessage());
        }

        return Response::redirect('/admin/updates?tab=sources');
    }

    public function activateSource(Request $request): Response
    {
        try {
            $id = (string) $request->input('id', '');
            if ($this->updates->setActiveUpdateSource($id)) {
                $this->session->flash('updates_info', 'Aktive Update-Quelle gewechselt.');
            } else {
                $this->session->flash('updates_error', 'Update-Quelle nicht gefunden.');
            }
        } catch (Throwable $exception) {
            $this->session->flash('updates_error', $exception->getMessage());
        }

        return Response::redirect('/admin/updates?tab=sources');
    }

    public function deleteSource(Request $request): Response
    {
        try {
            $id = (string) $request->input('id', '');
            $this->updates->deleteUpdateSource($id);
            $this->session->flash('updates_info', 'Update-Quelle entfernt.');
        } catch (Throwable $exception) {
            $this->session->flash('updates_error', $exception->getMessage());
        }

        return Response::redirect('/admin/updates?tab=sources');
    }

    public function syncMirror(Request $request): Response
    {
        try {
            $result = $this->updates->syncMirror();
            $this->session->flash('updates_info', (string) ($result['message'] ?? 'Core-Update-Mirror Synchronisation gestartet.'));
        } catch (Throwable $exception) {
            $this->session->flash('updates_error', $exception->getMessage());
        }

        return Response::redirect('/admin/updates?tab=sources');
    }

    public function mirrorStatus(Request $request): Response
    {
        return new Response(
            json_encode(['status' => $this->updates->mirrorStatus()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            200,
            ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store']
        );
    }

    public function updateChannelSetting(Request $request): Response
    {
        if ($this->settings === null) {
            $this->session->flash('updates_error', 'Updatekanal konnte nicht gespeichert werden.');
            return Response::redirect('/admin/updates?tab=updates');
        }

        $candidate = (string) $request->input('update_channel', '');
        if (!in_array($candidate, UpdateChannel::values(), true)) {
            $this->session->flash('updates_error', 'Ungültiger Updatekanal.');
            return Response::redirect('/admin/updates?tab=updates');
        }

        $this->settings->set(UpdateChannel::SETTING_KEY, $candidate);
        $this->updates->resetChannelSelection();
        $this->session->flash(
            'updates_info',
            $candidate === UpdateChannel::PREVIEW
                ? 'Stable + Vorabversionen wurde aktiviert.'
                : 'Nur freigegebene Versionen (Stable) wurde aktiviert.'
        );

        return Response::redirect('/admin/updates?tab=updates');
    }

    /**
     * @return array<string, mixed>
     */
    private function viewStatus(): array
    {
        $status = $this->updates->status($this->installedVersion, $this->channel, $this->updateChannel());
        $lastCheck = $status['state']['last_check'] ?? null;
        if (is_array($lastCheck) && isset($lastCheck['checked_at'])) {
            $tz = $this->auth?->resolveUserTimezone() ?? new DateTimeZone('Europe/Berlin');
            $lastCheck['checked_at_formatted'] = DateTimeFormatter::formatUserDateTime(
                (string) $lastCheck['checked_at'],
                $tz,
                'Europe/Berlin'
            );
            $status['state']['last_check'] = $lastCheck;
        }

        return $status;
    }

    private function updateChannel(): string
    {
        if ($this->settings === null) {
            return UpdateChannel::STABLE;
        }

        return (string) $this->settings->get(
            UpdateChannel::SETTING_KEY,
            UpdateChannel::STABLE
        );
    }
}
