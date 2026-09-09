from __future__ import annotations

import os
import re
from pathlib import Path
from urllib.parse import urljoin

import pytest


MODULES = {
    "modulnest.dashboard": "Dashboard",
    "modulnest.sneak-preview": "Sneak Preview",
}


def _url(base_url: str, path: str) -> str:
    return urljoin(base_url.rstrip("/") + "/", path.lstrip("/"))


def _login(page, base_url: str, credentials: tuple[str, str]) -> None:
    identifier, password = credentials
    page.goto(_url(base_url, "/login"), wait_until="domcontentloaded")
    page.locator("#email").fill(identifier)
    page.locator("#password").fill(password)
    page.get_by_role("button", name="Einloggen", exact=True).click()
    page.wait_for_load_state("domcontentloaded")
    assert "/login" not in page.url.rstrip("/")
    consent = page.get_by_role("button", name=re.compile("^Okay"))
    if consent.count() and consent.first.is_visible():
        consent.first.click()


def _no_overflow(page) -> None:
    assert page.locator("main").evaluate("node => node.scrollWidth <= node.clientWidth")


@pytest.mark.auth
def test_dashboard_and_sneak_v2_candidates_without_adoption(page, base_url, auth_credentials):
    if os.environ.get("MODULON_E2E_DASHBOARD_SNEAK_REVIEW") != "1":
        pytest.skip("Dashboard/Sneak-Review läuft nur gegen die bestehende Dev-Installation.")

    artifacts = Path(os.environ.get("MODULON_E2E_REVIEW_ARTIFACTS", ".local/e2e/review")) / "dashboard-sneak"
    artifacts.mkdir(parents=True, exist_ok=True)
    _login(page, base_url, auth_credentials)

    page.goto(_url(base_url, "/admin/module-catalog?bereich=installiert"), wait_until="domcontentloaded")
    assert page.locator("[data-catalog-warning]").count() == 0
    for module_id in MODULES:
        card = page.locator(f'[data-module-id="{module_id}"]')
        assert card.count() == 1
        assert card.get_attribute("data-module-class") == "v1"
        assert "Modul-v2-Version 1.0.1 verfügbar" in card.inner_text()
        assert "Auf Modul v2 umstellen" in card.inner_text()
    _no_overflow(page)
    page.screenshot(path=str(artifacts / "catalog-desktop.png"), full_page=True)

    for module_id, label in MODULES.items():
        page.goto(_url(base_url, f"/admin/module-catalog/{module_id}"), wait_until="domcontentloaded")
        assert page.get_by_role("heading", name=label, exact=True).is_visible()
        action = page.get_by_role("button", name="Auf Modul v2 umstellen", exact=True)
        assert action.is_visible() and action.is_enabled()
        assert page.locator("[data-adoption-preflight-blocked]").count() == 0

    page.goto(_url(base_url, "/dashboard"), wait_until="domcontentloaded")
    assert page.get_by_role("heading", name="Dashboard", exact=True).is_visible()
    assert page.locator("#dashboard-widget-overview-body").is_visible()
    _no_overflow(page)

    page.goto(_url(base_url, "/sneak-preview"), wait_until="domcontentloaded")
    assert page.get_by_role("heading", name=re.compile("Sneak-Preview")).is_visible()
    _no_overflow(page)
    page.goto(_url(base_url, "/admin/sneak-preview"), wait_until="domcontentloaded")
    assert page.get_by_role("heading", name=re.compile("Sneak Preview")).is_visible()
    _no_overflow(page)

    page.goto(_url(base_url, "/admin/data-portability"), wait_until="domcontentloaded")
    providers = page.locator('input[name="providers[]"]').evaluate_all("nodes => nodes.map(node => node.value)")
    assert "dashboard" in providers
    assert "sneak" in providers

    page.set_viewport_size({"width": 390, "height": 844})
    for path in ("/admin/module-catalog?bereich=installiert", "/dashboard", "/sneak-preview"):
        page.goto(_url(base_url, path), wait_until="domcontentloaded")
        _no_overflow(page)
    page.screenshot(path=str(artifacts / "sneak-mobile.png"), full_page=True)


@pytest.mark.auth
def test_real_dashboard_and_sneak_adoption(page, base_url, auth_credentials):
    if os.environ.get("MODULON_E2E_DASHBOARD_SNEAK_ADOPT") != "1":
        pytest.skip("Die echte Dashboard-/Sneak-Adoption benötigt eine ausdrückliche Freigabe.")

    _login(page, base_url, auth_credentials)
    for module_id, label in MODULES.items():
        page.goto(_url(base_url, f"/admin/module-catalog/{module_id}"), wait_until="domcontentloaded")
        action = page.get_by_role("button", name="Auf Modul v2 umstellen", exact=True)
        assert action.is_visible() and action.is_enabled()
        action.click()
        page.wait_for_load_state("domcontentloaded")
        assert page.locator("[data-catalog-message]").is_visible()
        assert "Modul v2 · Version 1.0.1" in page.locator("main").inner_text()
        assert page.get_by_role("heading", name=label, exact=True).is_visible()

    page.goto(_url(base_url, "/dashboard"), wait_until="domcontentloaded")
    assert page.get_by_role("heading", name="Dashboard", exact=True).is_visible()
    assert page.locator("#dashboard-widget-overview-body").is_visible()

    page.goto(_url(base_url, "/sneak-preview"), wait_until="domcontentloaded")
    assert page.get_by_role("heading", name=re.compile("Sneak-Preview")).is_visible()
    page.goto(_url(base_url, "/admin/sneak-preview"), wait_until="domcontentloaded")
    assert page.get_by_role("heading", name=re.compile("Sneak Preview")).is_visible()

    page.goto(_url(base_url, "/admin/data-portability"), wait_until="domcontentloaded")
    providers = page.locator('input[name="providers[]"]').evaluate_all("nodes => nodes.map(node => node.value)")
    assert "dashboard" in providers
    assert "sneak" in providers


@pytest.mark.auth
def test_dashboard_and_sneak_after_real_adoption(page, base_url, auth_credentials):
    if os.environ.get("MODULON_E2E_DASHBOARD_SNEAK_POST_ADOPT") != "1":
        pytest.skip("Die Prüfung nach echter Adoption benötigt eine ausdrückliche Freigabe.")

    _login(page, base_url, auth_credentials)
    page.goto(_url(base_url, "/admin/module-catalog?bereich=installiert"), wait_until="domcontentloaded")
    for module_id in MODULES:
        card = page.locator(f'[data-module-id="{module_id}"]')
        assert card.count() == 1
        assert card.get_attribute("data-module-class") == "v2"
        assert "Version 1.0.1" in card.inner_text()

    page.goto(_url(base_url, "/dashboard"), wait_until="domcontentloaded")
    assert page.get_by_role("heading", name="Dashboard", exact=True).is_visible()
    dashboard_files = sorted(Path("storage/modules/modulnest.dashboard/favicons").glob("fav-*"))
    assert dashboard_files
    dashboard_response = page.request.get(_url(base_url, f"/dashboard/favicons/{dashboard_files[0].name}"))
    assert dashboard_response.ok and dashboard_response.body() == dashboard_files[0].read_bytes()

    page.goto(_url(base_url, "/sneak-preview"), wait_until="domcontentloaded")
    assert page.get_by_role("heading", name=re.compile("Sneak-Preview")).is_visible()
    poster_files = sorted(Path("storage/modules/modulnest.sneak-preview/posters").glob("tmdb_*"))
    assert poster_files
    poster_response = page.request.get(_url(base_url, f"/sneak-preview/posters/{poster_files[0].name}"))
    assert poster_response.ok and poster_response.body() == poster_files[0].read_bytes()

    page.goto(_url(base_url, "/admin/data-portability"), wait_until="domcontentloaded")
    providers = page.locator('input[name="providers[]"]').evaluate_all("nodes => nodes.map(node => node.value)")
    assert providers.count("dashboard") == 1
    assert providers.count("sneak") == 1
