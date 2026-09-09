from __future__ import annotations

import os
from urllib.parse import urljoin

import pytest


def _url(base_url: str, path: str) -> str:
    return urljoin(base_url.rstrip("/") + "/", path.lstrip("/"))


@pytest.mark.auth
def test_banking_v2_candidate_route_and_provider(logged_in_page, base_url: str) -> None:
    if os.environ.get("MODULON_E2E_BANKING_V2_REVIEW") != "1":
        pytest.skip("Banking-v2-Review läuft nur gegen die bestehende Dev-Installation.")

    page = logged_in_page
    page.goto(_url(base_url, "/admin/module-catalog?bereich=installiert"), wait_until="domcontentloaded")
    card = page.locator('[data-module-id="modulnest.banking"]')
    assert card.count() == 1
    assert card.get_attribute("data-module-class") == "v1"
    assert "Modul-v2-Version 1.0.1 verfügbar" in card.inner_text()
    assert "Auf Modul v2 umstellen" in card.inner_text()

    page.goto(_url(base_url, "/admin/module-catalog?bereich=entdecken"), wait_until="domcontentloaded")
    assert page.locator('[data-module-id="modulnest.banking"]').count() == 0

    page.goto(_url(base_url, "/admin/module-catalog/modulnest.banking"), wait_until="domcontentloaded")
    assert page.get_by_role("heading", name="Banking", exact=True).is_visible()
    action = page.get_by_role("button", name="Auf Modul v2 umstellen", exact=True)
    assert action.is_visible() and action.is_enabled()
    assert page.locator("[data-adoption-preflight-blocked]").count() == 0

    page.goto(_url(base_url, "/banking"), wait_until="domcontentloaded")
    assert page.get_by_role("heading", name="Willkommen zurück!", exact=True).is_visible()
    assert page.get_by_role("link", name="Umsätze", exact=True).is_visible()
    assert page.locator("main").evaluate("node => node.scrollWidth <= node.clientWidth")

    page.goto(_url(base_url, "/admin/data-portability"), wait_until="domcontentloaded")
    providers = page.locator('input[name="providers[]"]').evaluate_all(
        "nodes => nodes.map(node => node.value)"
    )
    assert "banking" in providers
