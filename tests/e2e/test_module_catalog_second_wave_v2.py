from __future__ import annotations

import os
import re
from pathlib import Path
from urllib.parse import urljoin

import pytest


MODULES = {
    "modulnest.pages": "Pages",
    "modulnest.homepage": "Startseite",
    "modulnest.data-portability": "Export / Import",
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


def _no_horizontal_overflow(page) -> None:
    assert page.locator("main").evaluate("node => node.scrollWidth <= node.clientWidth")


@pytest.mark.auth
def test_second_wave_catalog_routes_and_providers(page, base_url, auth_credentials):
    if os.environ.get("MODULON_E2E_SECOND_WAVE_REVIEW") != "1":
        pytest.skip("Der zweite v2-Block wird nur gegen die bestehende Dev-Installation geprüft.")

    artifact_root = Path(os.environ.get("MODULON_E2E_REVIEW_ARTIFACTS", ".local/e2e/review")) / "second-wave"
    artifact_root.mkdir(parents=True, exist_ok=True)
    _login(page, base_url, auth_credentials)

    page.goto(_url(base_url, "/admin/module-catalog?bereich=installiert"), wait_until="domcontentloaded")
    assert page.locator("[data-catalog-warning]").count() == 0
    for module_id in MODULES:
        card = page.locator(f'[data-module-id="{module_id}"]')
        assert card.count() == 1
        assert card.get_attribute("data-module-class") == "v1"
        assert "Modul-v2-Version 1.0.1 verfügbar" in card.inner_text()
        assert "Auf Modul v2 umstellen" in card.inner_text()
    _no_horizontal_overflow(page)
    page.screenshot(path=str(artifact_root / "catalog-desktop.png"), full_page=True)

    for module_id, label in MODULES.items():
        page.goto(_url(base_url, f"/admin/module-catalog/{module_id}"), wait_until="domcontentloaded")
        assert page.get_by_role("heading", name=label, exact=True).is_visible()
        action = page.get_by_role("button", name="Auf Modul v2 umstellen", exact=True)
        assert action.is_visible() and action.is_enabled()
        assert page.locator("[data-adoption-preflight-blocked]").count() == 0

    page.goto(_url(base_url, "/admin/module-catalog?bereich=entdecken"), wait_until="domcontentloaded")
    for module_id in MODULES:
        assert page.locator(f'[data-module-id="{module_id}"]').count() == 0

    routes = (
        ("/pages", "Seiten"),
        ("/", None),
        ("/admin/data-portability", "Export / Import"),
    )
    for path, heading in routes:
        page.goto(_url(base_url, path), wait_until="domcontentloaded")
        assert page.locator("main").is_visible()
        if heading is not None:
            assert page.get_by_role("heading", name=heading, exact=True).is_visible()
        _no_horizontal_overflow(page)

    provider_values = page.locator('input[name="providers[]"]').evaluate_all(
        "nodes => nodes.map(node => node.value)"
    )
    assert "modulnest.wiki" in provider_values
    assert "news" in provider_values
    portability_text = page.locator("main").inner_text()
    assert "Hinzufügen / Zusammenführen" in portability_text
    assert "Bestehende Moduldaten ersetzen" in portability_text
    page.screenshot(path=str(artifact_root / "data-portability-desktop.png"), full_page=True)

    page.set_viewport_size({"width": 390, "height": 844})
    for path, _heading in routes:
        page.goto(_url(base_url, path), wait_until="domcontentloaded")
        _no_horizontal_overflow(page)
    page.goto(_url(base_url, "/admin/module-catalog?bereich=installiert"), wait_until="domcontentloaded")
    _no_horizontal_overflow(page)
    page.screenshot(path=str(artifact_root / "catalog-mobile.png"), full_page=True)
