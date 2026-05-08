from __future__ import annotations

from urllib.parse import urljoin

import pytest


def _url(base_url: str, path: str) -> str:
    return urljoin(base_url.rstrip("/") + "/", path.lstrip("/"))


@pytest.mark.auth
def test_dashboard_inline_settings_links(logged_in_page, base_url: str) -> None:
    page = logged_in_page
    page.set_viewport_size({"width": 1366, "height": 900})
    page.goto(_url(base_url, "/dashboard"), wait_until="domcontentloaded")

    timezone_row = page.locator("#dashboard-timezone-row")
    timezone_link = timezone_row.locator("a.inline-settings-link")
    timezone_label = timezone_row.locator("#dashboard-timezone-label")
    assert timezone_link.is_visible()
    assert timezone_link.get_attribute("href") == "/profil/settings"
    assert timezone_link.get_attribute("title") == "Einstellungen anpassen"
    assert timezone_link.get_attribute("aria-label") == "Einstellungen anpassen"
    assert timezone_link.locator("i.bi.bi-gear-fill").count() == 1

    auto_status = page.locator("#dashboard-auto-refresh-status")
    auto_link = auto_status.locator("a.inline-settings-link")
    auto_value = auto_status.locator("#dashboard-auto-refresh-status-value")
    assert auto_link.is_visible()
    assert auto_link.get_attribute("href") == "/profil/settings"
    assert auto_link.get_attribute("title") == "Einstellungen anpassen"
    assert auto_link.get_attribute("aria-label") == "Einstellungen anpassen"
    assert auto_link.locator("i.bi.bi-gear-fill").count() == 1

    # Desktop sanity: link and value should stay on one line (no forced wrap).
    tz_top_diff = page.evaluate(
        """([labelEl, linkEl]) => Math.abs(labelEl.getBoundingClientRect().top - linkEl.getBoundingClientRect().top)""",
        [timezone_label.element_handle(), timezone_link.element_handle()],
    )
    assert tz_top_diff <= 4

    auto_top_diff = page.evaluate(
        """([valueEl, linkEl]) => Math.abs(valueEl.getBoundingClientRect().top - linkEl.getBoundingClientRect().top)""",
        [auto_value.element_handle(), auto_link.element_handle()],
    )
    assert auto_top_diff <= 4
