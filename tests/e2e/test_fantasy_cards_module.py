from __future__ import annotations

from urllib.parse import urljoin

import pytest
from playwright.sync_api import expect


def _url(base_url: str, path: str) -> str:
    return urljoin(base_url.rstrip("/") + "/", path.lstrip("/"))


@pytest.mark.auth
def test_fantasy_cards_public_smoke(logged_in_page, base_url: str) -> None:
    page = logged_in_page
    page.goto(_url(base_url, "/fantasy-cards"), wait_until="domcontentloaded")

    expect(page.get_by_role("heading", name="Sammelkarten-Sets")).to_be_visible()
    expect(page.get_by_text("Erste Ära")).to_be_visible()

    page.get_by_role("link").filter(has_text="Erste Ära").first.click()
    page.wait_for_load_state("domcontentloaded")
    expect(page.get_by_role("heading", name="Erste Ära")).to_be_visible()
    expect(page.get_by_text("Drachenorakel")).to_be_visible()

    page.goto(_url(base_url, "/fantasy-cards/boosters"), wait_until="domcontentloaded")
    expect(page.get_by_role("heading", name="Meine Booster")).to_be_visible()
    expect(page.get_by_text("Free-Pack-Claims", exact=True)).to_be_visible()

    page.goto(_url(base_url, "/fantasy-cards/collection"), wait_until="domcontentloaded")
    expect(page.get_by_role("heading", name="Meine Sammlung")).to_be_visible()
    expect(page.get_by_role("heading", name="Erste Ära")).to_be_visible()

    page.goto(_url(base_url, "/profil/fantasy-cards"), wait_until="domcontentloaded")
    expect(page.get_by_role("heading", name="Sammelkarten-Profil")).to_be_visible()
    expect(page.get_by_text("Karten-Showcase")).to_be_visible()


@pytest.mark.auth
def test_fantasy_cards_admin_smoke(logged_in_page, base_url: str) -> None:
    page = logged_in_page
    page.goto(_url(base_url, "/admin/fantasy-cards"), wait_until="domcontentloaded")

    expect(page.get_by_role("heading", name="Sets")).to_be_visible()
    expect(page.get_by_role("link", name="Set anlegen")).to_be_visible()
    expect(page.get_by_text("Erste Ära")).to_be_visible()

    page.goto(_url(base_url, "/admin/fantasy-cards/cards"), wait_until="domcontentloaded")
    expect(page.get_by_role("heading", name="Karten")).to_be_visible()
    expect(page.get_by_role("link", name="Karte anlegen")).to_be_visible()
    expect(page.locator('.fantasycards-inline-input[data-field="name"][value="Mondklinge"]')).to_be_visible()

    page.goto(_url(base_url, "/admin/fantasycards/upload"), wait_until="domcontentloaded")
    expect(page.get_by_role("heading", name="Massen-Upload")).to_be_visible()
    expect(page.locator('select[name="set_id"]')).to_be_visible()
    expect(page.locator('input[type="file"][name="cards[]"]')).to_be_visible()
