from __future__ import annotations

from urllib.parse import urljoin


def _url(base_url: str, path: str) -> str:
    return urljoin(base_url.rstrip('/') + '/', path.lstrip('/'))


def _choose_header_theme(page, mode: str):
    page.locator('.app-theme-button').click()
    page.locator(f'[data-theme-option="{mode}"]').click()


def _save_profile_theme(page, base_url: str, mode: str, switcher_visible: bool):
    page.goto(_url(base_url, '/profil/settings'), wait_until='domcontentloaded')
    page.locator(f'label[for="settings_theme_{mode}"]').click()
    page.locator('#settings_theme_switcher_visible').set_checked(switcher_visible)
    page.get_by_role('button', name='Einstellungen speichern', exact=True).click()
    page.wait_for_load_state('domcontentloaded')
    assert page.url.rstrip('/').endswith('/profil/settings')


def test_guest_theme_modes_persist_and_follow_system(page, base_url):
    page.goto(_url(base_url, '/'), wait_until='domcontentloaded')
    page.evaluate("localStorage.removeItem('modulon_guest_theme')")
    page.emulate_media(color_scheme='light')
    page.reload(wait_until='domcontentloaded')
    assert page.locator('html').get_attribute('data-theme-mode') == 'system'
    assert page.locator('html').get_attribute('data-theme') == 'light'
    assert page.locator('[data-theme-switcher]').is_visible()

    _choose_header_theme(page, 'dark')
    assert page.locator('html').get_attribute('data-theme') == 'dark'
    page.reload(wait_until='domcontentloaded')
    assert page.locator('html').get_attribute('data-theme-mode') == 'dark'
    assert page.locator('html').get_attribute('data-theme') == 'dark'

    _choose_header_theme(page, 'light')
    page.reload(wait_until='domcontentloaded')
    assert page.locator('html').get_attribute('data-theme') == 'light'

    _choose_header_theme(page, 'system')
    assert page.locator('html').get_attribute('data-theme') == 'light'
    page.emulate_media(color_scheme='dark')
    assert page.locator('html').get_attribute('data-theme-mode') == 'system'
    page.wait_for_function("document.documentElement.dataset.theme === 'dark'")
    page.emulate_media(color_scheme='light')
    page.wait_for_function("document.documentElement.dataset.theme === 'light'")


def test_user_theme_is_authoritative_persistent_and_hideable(logged_in_page, base_url, auth_credentials):
    page = logged_in_page
    identifier, password = auth_credentials
    page.goto(_url(base_url, '/profil/settings'), wait_until='domcontentloaded')
    original_mode = page.locator('input[name="theme_mode"]:checked').get_attribute('value') or 'system'
    original_visible = page.locator('#settings_theme_switcher_visible').is_checked()

    try:
        # Keep an independent guest preference. It must not override the user profile.
        page.evaluate("localStorage.setItem('modulon_guest_theme', 'dark')")
        _save_profile_theme(page, base_url, 'light', True)
        assert page.locator('html').get_attribute('data-theme-mode') == 'light'
        assert page.locator('html').get_attribute('data-theme') == 'light'

        # Missing CSRF is blocked; an invalid value is rejected even with a valid token.
        csrf_status = page.evaluate("""async () => (await fetch('/profil/theme', {
            method: 'POST', headers: {'Accept': 'application/json', 'Content-Type': 'application/json'},
            body: JSON.stringify({theme_mode: 'dark'})
        })).status""")
        assert csrf_status == 419
        token = page.locator('[data-theme-switcher]').get_attribute('data-csrf-token') or ''
        invalid_status = page.evaluate("""async ({token}) => (await fetch('/profil/theme', {
            method: 'POST', headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': token},
            body: JSON.stringify({theme_mode: 'neon'})
        })).status""", {'token': token})
        assert invalid_status == 422

        with page.expect_response(lambda response: response.url.endswith('/profil/theme')) as response_info:
            _choose_header_theme(page, 'dark')
        assert response_info.value.status == 200
        assert page.locator('html').get_attribute('data-theme-mode') == 'dark'
        page.reload(wait_until='domcontentloaded')
        assert page.locator('html').get_attribute('data-theme') == 'dark'

        # The server-side profile wins at login; logout restores the separate guest preference.
        _save_profile_theme(page, base_url, 'light', True)
        page.locator('form[action="/logout"] button').click()
        page.wait_for_load_state('domcontentloaded')
        assert page.locator('html').get_attribute('data-theme-mode') == 'dark'
        page.locator('#email').fill(identifier)
        page.locator('#password').fill(password)
        page.get_by_role('button', name='Einloggen', exact=True).click()
        page.wait_for_load_state('domcontentloaded')
        assert '/login' not in page.url.rstrip('/')
        assert page.locator('html').get_attribute('data-theme-mode') == 'light'
        assert page.locator('html').get_attribute('data-theme') == 'light'

        _save_profile_theme(page, base_url, 'system', False)
        assert page.locator('[data-theme-switcher]').count() == 0
        assert page.locator('input[name="theme_mode"][value="system"]').is_checked()

        # Profile controls remain available and can show the header switcher again.
        _save_profile_theme(page, base_url, 'light', True)
        assert page.locator('[data-theme-switcher]').is_visible()
    finally:
        _save_profile_theme(page, base_url, original_mode, original_visible)
