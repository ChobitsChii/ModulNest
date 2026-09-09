from __future__ import annotations

import os
from urllib.parse import urljoin

import pytest


def _url(base_url: str, path: str) -> str:
    return urljoin(base_url.rstrip("/") + "/", path.lstrip("/"))


@pytest.mark.auth
def test_tools_v2_candidate_without_adoption(logged_in_page, base_url: str) -> None:
    if os.environ.get("MODULON_E2E_TOOLS_V2_REVIEW") != "1":
        pytest.skip("Tools-v2-Review läuft nur gegen die bestehende Dev-Installation.")

    page = logged_in_page
    page.goto(_url(base_url, "/admin/module-catalog?bereich=installiert"), wait_until="domcontentloaded")
    card = page.locator('[data-module-id="modulnest.tools"]')
    assert card.count() == 1
    assert card.get_attribute("data-module-class") == "v1"
    assert "Modul-v2-Version 1.0.1 verfügbar" in card.inner_text()
    assert "Auf Modul v2 umstellen" in card.inner_text()

    page.goto(_url(base_url, "/admin/module-catalog/modulnest.tools"), wait_until="domcontentloaded")
    assert page.get_by_role("heading", name="Tools", exact=True).is_visible()
    action = page.get_by_role("button", name="Auf Modul v2 umstellen", exact=True)
    assert action.is_visible() and action.is_enabled()
    assert page.locator("[data-adoption-preflight-blocked]").count() == 0

    page.goto(_url(base_url, "/tools/text-cleaner"), wait_until="domcontentloaded")
    assert page.get_by_role("heading", name="Textbereinigung").first.is_visible()
    page.goto(_url(base_url, "/admin/tools"), wait_until="domcontentloaded")
    assert page.get_by_role("heading", name="Diagnose- und Sicherheitswerkzeuge").is_visible()
    assert page.get_by_role("heading", name="Speech-to-Text", exact=True).is_visible()
    status = page.evaluate("fetch('/admin/tools/speech/status').then(response => response.json())")
    assert status["ok"] is True
    assert len(status["jobs"]) == 17
