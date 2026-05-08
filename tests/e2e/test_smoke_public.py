from __future__ import annotations

from urllib.parse import urljoin


def _url(base_url: str, path: str) -> str:
    return urljoin(base_url.rstrip("/") + "/", path.lstrip("/"))


def test_homepage_reachable(page, base_url: str) -> None:
    response = page.goto(_url(base_url, "/"), wait_until="domcontentloaded")
    assert response is not None
    assert response.status < 500
    assert "404 Not Found" not in page.content()


def test_login_page_reachable(page, base_url: str) -> None:
    response = page.goto(_url(base_url, "/login"), wait_until="domcontentloaded")
    assert response is not None
    assert response.status < 500
    assert page.get_by_role("heading", name="Login").is_visible()


def test_sneak_preview_public_reachable(page, base_url: str) -> None:
    response = page.goto(_url(base_url, "/sneak-preview"), wait_until="domcontentloaded")
    assert response is not None
    assert response.status < 500
    assert page.get_by_role("heading", name="Sneak-Preview-Liste").is_visible()
    assert page.locator("#sneak-preview-public-table").is_visible() or page.get_by_text("Noch keine Sneak-Preview-Einträge vorhanden.").is_visible()
