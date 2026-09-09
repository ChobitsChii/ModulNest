from __future__ import annotations

import os
import re
from pathlib import Path
from urllib.parse import urljoin

import pytest


PRODUCT_MODULES = {
    "modulnest.wiki": "Wiki",
    "modulnest.logs": "Logs",
    "modulnest.systeminfo": "Systeminfo",
    "modulnest.news": "News",
    "modulnest.pages": "Pages",
    "modulnest.homepage": "Startseite",
    "modulnest.data-portability": "Export / Import",
}


def _url(base_url: str, path: str) -> str:
    return urljoin(base_url.rstrip("/") + "/", path.lstrip("/"))


def _artifacts(scenario: str) -> Path:
    root = Path(os.environ.get("MODULON_E2E_REVIEW_ARTIFACTS", ".local/e2e/review"))
    target = root / scenario
    target.mkdir(parents=True, exist_ok=True)
    return target


def _login(page, base_url: str, auth_credentials: tuple[str, str]) -> None:
    identifier, password = auth_credentials
    page.goto(_url(base_url, "/login"), wait_until="domcontentloaded")
    page.locator("#email").fill(identifier)
    page.locator("#password").fill(password)
    page.get_by_role("button", name="Einloggen", exact=True).click()
    page.wait_for_load_state("domcontentloaded")
    assert "/login" not in page.url.rstrip("/")
    consent = page.get_by_role("button", name=re.compile("^Okay"))
    if consent.count() and consent.first.is_visible():
        consent.first.click()


def _assert_no_horizontal_overflow(page) -> None:
    assert page.locator("main").evaluate("node => node.scrollWidth <= node.clientWidth")


def _set_theme(page, theme: str) -> str:
    return page.locator("html").evaluate(
        """(node, value) => {
            node.setAttribute('data-theme', value);
            node.setAttribute('data-theme-mode', value);
            return getComputedStyle(node).getPropertyValue('--app-bg').trim();
        }""",
        theme,
    )


@pytest.mark.auth
def test_clean_install_uses_signed_product_dev_catalog(page, base_url, auth_credentials):
    if os.environ.get("MODULON_E2E_CLEAN_DEV_REVIEW") != "1":
        pytest.skip("Clean-Install-Review läuft nur gegen eine isolierte neue Installation.")

    artifacts = _artifacts("clean-install")
    _login(page, base_url, auth_credentials)
    page.goto(_url(base_url, "/admin/module-catalog?bereich=entdecken"), wait_until="domcontentloaded")
    assert page.locator("[data-catalog-warning]").count() == 0

    cards = page.locator("[data-module-id]")
    ids = [cards.nth(index).get_attribute("data-module-id") for index in range(cards.count())]
    assert set(ids) == set(PRODUCT_MODULES)
    assert "example.example-notes" not in ids
    assert page.locator('[data-module-class="v1"]').count() == 0
    for module_id in PRODUCT_MODULES:
        card = page.locator(f'[data-module-id="{module_id}"]')
        assert card.get_attribute("data-module-class") == "v2"
        assert "Modul v2" in card.inner_text()
        assert "Version 1.0.1" in card.inner_text()
    _set_theme(page, "dark")
    page.screenshot(path=str(artifacts / "discover-dark.png"), full_page=True)

    for module_id in ("modulnest.wiki", "modulnest.logs"):
        page.goto(_url(base_url, f"/admin/module-catalog/{module_id}"), wait_until="domcontentloaded")
        page.get_by_role("button", name="Installieren", exact=True).click()
        page.wait_for_load_state("domcontentloaded")
        page.get_by_role("button", name="Aktivieren", exact=True).click()
        page.wait_for_load_state("domcontentloaded")

    page.goto(_url(base_url, "/admin/module-catalog?bereich=installiert"), wait_until="domcontentloaded")
    assert page.locator("[data-catalog-warning]").count() == 0
    assert page.locator('[data-module-class="core"]').count() == 5
    assert page.locator('[data-module-class="v1"]').count() == 0
    for module_id in ("modulnest.wiki", "modulnest.logs"):
        text = page.locator(f'[data-module-id="{module_id}"]').inner_text()
        assert "Modul v2" in text and "Version 1.0.1" in text and "Aktiv" in text
    dark_background = _set_theme(page, "dark")
    page.screenshot(path=str(artifacts / "installed-dark.png"), full_page=True)

    page.goto(_url(base_url, "/admin/logs"), wait_until="domcontentloaded")
    assert page.get_by_role("heading", name=re.compile("Logs")).is_visible()
    page.goto(_url(base_url, "/wiki"), wait_until="domcontentloaded")
    assert "Wiki" in page.locator("body").inner_text()

    page.goto(_url(base_url, "/admin/module-catalog?bereich=installiert"), wait_until="domcontentloaded")
    light_background = _set_theme(page, "light")
    assert dark_background != light_background
    page.screenshot(path=str(artifacts / "installed-light.png"), full_page=True)
    page.set_viewport_size({"width": 390, "height": 844})
    _assert_no_horizontal_overflow(page)
    page.screenshot(path=str(artifacts / "installed-mobile.png"), full_page=True)
    page.goto(_url(base_url, "/admin/module-catalog/modulnest.wiki"), wait_until="domcontentloaded")
    _assert_no_horizontal_overflow(page)
    page.screenshot(path=str(artifacts / "detail-mobile.png"), full_page=True)


@pytest.mark.auth
def test_existing_dev_install_is_a_migration_overview(page, base_url, auth_credentials):
    if os.environ.get("MODULON_E2E_EXISTING_DEV_REVIEW") != "1":
        pytest.skip("Bestandsinstallations-Review läuft nur gegen die lokale Dev-Installation.")

    artifacts = _artifacts("existing-dev")
    _login(page, base_url, auth_credentials)
    page.goto(_url(base_url, "/admin/module-catalog?bereich=installiert"), wait_until="domcontentloaded")
    assert page.locator("[data-catalog-warning]").count() == 0
    assert page.locator('[data-module-class="core"]').count() == 5
    assert page.locator('[data-module-class="v1"]').count() > 4
    assert page.locator('[data-module-class="legacy"]').count() > 0
    assert page.get_by_role("link", name="Technische Ansicht").count() == 0
    assert page.get_by_role("link", name="Erweiterte Modulverwaltung").count() == 0
    assert page.get_by_role("link", name="Modulverwaltung", exact=True).is_visible()

    for module_id in PRODUCT_MODULES:
        card = page.locator(f'[data-module-id="{module_id}"]')
        text = card.inner_text()
        assert card.get_attribute("data-module-class") == "v1"
        assert "Modul-v2-Version 1.0.1 verfügbar" in text
        assert "Auf Modul v2 umstellen" in text
        assert page.locator(f'[data-module-id="{module_id}"]').count() == 1
    dark_background = _set_theme(page, "dark")
    page.screenshot(path=str(artifacts / "installed-dark.png"), full_page=True)

    light_background = _set_theme(page, "light")
    assert dark_background != light_background
    page.screenshot(path=str(artifacts / "installed-light.png"), full_page=True)
    page.set_viewport_size({"width": 390, "height": 844})
    _assert_no_horizontal_overflow(page)
    page.screenshot(path=str(artifacts / "installed-mobile.png"), full_page=True)

    page.goto(_url(base_url, "/admin/module-catalog/modulnest.wiki"), wait_until="domcontentloaded")
    assert page.get_by_role("button", name="Auf Modul v2 umstellen", exact=True).is_visible()
    assert page.get_by_role("heading", name="Modul deinstallieren").count() == 0
    _assert_no_horizontal_overflow(page)
    page.screenshot(path=str(artifacts / "wiki-v1-detail-mobile.png"), full_page=True)

    page.goto(_url(base_url, "/admin/module-catalog?bereich=entdecken"), wait_until="domcontentloaded")
    for module_id in PRODUCT_MODULES:
        assert page.locator(f'[data-module-id="{module_id}"]').count() == 0
    page.screenshot(path=str(artifacts / "discover.png"), full_page=True)

    page.goto(_url(base_url, "/admin/module-catalog?bereich=updates"), wait_until="domcontentloaded")
    for module_id in PRODUCT_MODULES:
        assert page.locator(f'[data-module-id="{module_id}"]').count() == 0
    page.screenshot(path=str(artifacts / "updates.png"), full_page=True)


@pytest.mark.auth
def test_existing_dev_wiki_manual_adoption(page, base_url, auth_credentials):
    if os.environ.get("MODULON_E2E_WIKI_ADOPTION_REVIEW") != "1":
        pytest.skip("Die echte Wiki-Adoption wird nur einmal und ausdrücklich freigeschaltet.")

    artifacts = _artifacts("wiki-adoption")
    _login(page, base_url, auth_credentials)
    page.goto(_url(base_url, "/admin/module-catalog/modulnest.wiki"), wait_until="domcontentloaded")
    action = page.get_by_role("button", name="Auf Modul v2 umstellen", exact=True)
    assert action.is_visible()
    action.click()
    page.wait_for_load_state("domcontentloaded")
    body_text = page.locator("body").inner_text()
    if "auf Modul v2 umgestellt" not in body_text:
        error = page.locator("[data-catalog-error]")
        detail = error.inner_text() if error.count() else "keine sichere Fehlermeldung in der Detailansicht"
        raise AssertionError(f"Wiki-Adoption fehlgeschlagen: {detail}")

    page.goto(_url(base_url, "/admin/module-catalog?bereich=installiert"), wait_until="domcontentloaded")
    wiki = page.locator('[data-module-id="modulnest.wiki"]')
    assert wiki.count() == 1
    assert wiki.get_attribute("data-module-class") == "v2"
    assert "Version 1.0.1" in wiki.inner_text()
    assert "Aktiv" in wiki.inner_text()
    for module_id in ("modulnest.logs", "modulnest.systeminfo", "modulnest.news"):
        card = page.locator(f'[data-module-id="{module_id}"]')
        assert card.get_attribute("data-module-class") == "v1"
        assert "Auf Modul v2 umstellen" in card.inner_text()
    page.screenshot(path=str(artifacts / "installed-after-adoption.png"), full_page=True)

    page.goto(_url(base_url, "/wiki"), wait_until="domcontentloaded")
    assert page.locator(".wiki-content").count() == 1
    assert "ModulNest Dokumentation" in page.locator("body").inner_text()

    page.goto(_url(base_url, "/admin/wiki"), wait_until="domcontentloaded")
    assert page.get_by_role("heading", name="Wiki verwalten").is_visible()
    assert "Aktuell" in page.locator("[data-wiki-search-status]").inner_text()
    assert page.locator("[data-wiki-source-type]").input_value() == "local"

    page.goto(_url(base_url, "/wiki/search?q=Konfiguration"), wait_until="domcontentloaded")
    assert page.locator(".wiki-search-page-results").count() == 1
    assert page.locator(".wiki-search-page-results mark").count() >= 1
    page.screenshot(path=str(artifacts / "search-after-adoption.png"), full_page=True)


@pytest.mark.auth
def test_existing_dev_after_wiki_adoption_review(page, base_url, auth_credentials):
    if os.environ.get("MODULON_E2E_WIKI_ADOPTED_REVIEW") != "1":
        pytest.skip("Der Nachtest läuft nur gegen die ausdrücklich adoptierte Dev-Installation.")

    _login(page, base_url, auth_credentials)
    page.goto(_url(base_url, "/admin/module-catalog?bereich=installiert"), wait_until="domcontentloaded")
    wiki = page.locator('[data-module-id="modulnest.wiki"]')
    assert wiki.count() == 1
    assert wiki.get_attribute("data-module-class") == "v2"
    assert "Version 1.0.1" in wiki.inner_text()
    for module_id in ("modulnest.logs", "modulnest.systeminfo", "modulnest.news"):
        card = page.locator(f'[data-module-id="{module_id}"]')
        assert card.count() == 1
        assert card.get_attribute("data-module-class") == "v1"
        assert "Modul-v2-Version 1.0.1 verfügbar" in card.inner_text()
        assert "Auf Modul v2 umstellen" in card.inner_text()
