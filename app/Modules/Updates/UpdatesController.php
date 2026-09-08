<?php

declare(strict_types=1);

namespace Modulon\Modules\Updates;

use DateTimeZone;
use Modulon\Core\DateTimeFormatter;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Session;
use Modulon\Core\View;
use Modulon\Modules\Auth\AuthService;
use Modulon\Modules\Admin\AppSettingRepository;
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
        return new Response(View::render('updates/admin', [
            'title' => 'Updates',
            'current_path' => $request->path(),
            'admin_section' => 'updates',
            'message' => $this->session->pullFlash('updates_info'),
            'error' => $this->session->pullFlash('updates_error'),
            'status' => $this->viewStatus(),
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

        return Response::redirect('/admin/updates');
    }

    public function prepare(Request $request): Response
    {
        try {
            $result = $this->updates->prepare($this->installedVersion, $this->updateChannel());
            $this->session->flash('updates_info', 'Update ' . (string) ($result['version'] ?? '') . ' wurde heruntergeladen, geprüft und vorbereitet.');
        } catch (Throwable $exception) {
            $this->session->flash('updates_error', $exception->getMessage());
        }

        return Response::redirect('/admin/updates');
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

        return Response::redirect('/admin/updates');
    }

    public function updateChannelSetting(Request $request): Response
    {
        if ($this->settings === null) {
            $this->session->flash('updates_error', 'Updatekanal konnte nicht gespeichert werden.');
            return Response::redirect('/admin/updates');
        }

        $candidate = (string) $request->input('update_channel', '');
        if (!in_array($candidate, UpdateChannel::values(), true)) {
            $this->session->flash('updates_error', 'Ungültiger Updatekanal.');
            return Response::redirect('/admin/updates');
        }

        $this->settings->set(UpdateChannel::SETTING_KEY, $candidate);
        $this->updates->resetChannelSelection();
        $this->session->flash(
            'updates_info',
            $candidate === UpdateChannel::PREVIEW
                ? 'Stable + Vorabversionen wurde aktiviert.'
                : 'Der Stable-Updatekanal wurde aktiviert.'
        );
        return Response::redirect('/admin/updates');
    }

    /**
     * @return array<string, mixed>
     */
    private function viewStatus(): array
    {
        $timezone = $this->userTimezone();
        $status = $this->updates->status($this->installedVersion, $this->channel, $this->updateChannel());
        $status['timezone_name'] = $timezone->getName();

        if (!isset($status['state']) || !is_array($status['state'])) {
            return $status;
        }

        foreach (
            [
                ['last_check', 'checked_at'],
                ['prepared', 'prepared_at'],
                ['last_install', 'installed_at'],
            ] as [$section, $field]
        ) {
            if (!isset($status['state'][$section]) || !is_array($status['state'][$section])) {
                continue;
            }

            $status['state'][$section][$field . '_local'] = DateTimeFormatter::formatUserDateTime(
                $status['state'][$section][$field] ?? '',
                $timezone
            );
        }

        return $status;
    }

    private function updateChannel(): string
    {
        return UpdateChannel::normalize($this->settings?->get(UpdateChannel::SETTING_KEY));
    }

    private function userTimezone(): DateTimeZone
    {
        try {
            $user = $this->auth?->currentUser();
            $candidate = is_array($user) ? (string) ($user['timezone'] ?? '') : '';

            return DateTimeFormatter::resolveTimezone($candidate);
        } catch (Throwable) {
            return DateTimeFormatter::resolveTimezone();
        }
    }
}
