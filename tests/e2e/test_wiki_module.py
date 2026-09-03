"""Run only against an isolated installation with Wiki activated and synchronised."""
import os
from urllib.parse import urljoin

import pytest


def test_wiki_page_reachable_after_sync(logged_in_page, base_url):
    if os.environ.get("MODULON_E2E_WIKI_READY") != "1":
        pytest.skip("Wiki-E2E benötigt eine isolierte Installation mit aktivierter, synchronisierter Wiki-Quelle.")
    page = logged_in_page
    page.goto(urljoin(base_url.rstrip('/') + '/', 'wiki'))
    assert page.url.endswith('/wiki')
    # The local fixture uses the public ModulNest docs tree.
    assert page.locator(".wiki-content").count() == 1
    assert page.get_by_role("link", name="Start", exact=True).get_attribute("href") == "/wiki"
    assert page.locator(".wiki-nav-group").count() >= 1
    assert page.locator(".wiki-nav-link.is-active").count() >= 1

    page.goto(urljoin(base_url.rstrip('/') + '/', 'wiki/technical/tech-architecture'))
    assert page.locator(".wiki-content").count() == 1
    assert page.locator(".wiki-nav-link.is-active").count() == 1
    page.goto(urljoin(base_url.rstrip('/') + '/', 'wiki/configuration'))
    assert page.locator(".wiki-content").count() == 1
    assert page.locator(".wiki-toc-desktop").count() == 1
    assert page.locator(".wiki-toc a[href='#modulnest-konfiguration']").count() == 2
    assert "Synchronisiert am" in page.locator(".wiki-source").inner_text()

    page.goto(urljoin(base_url.rstrip('/') + '/', 'wiki/release'))
    assert page.locator(".wiki-toc a[href='#modulnest-release-prozess']").count() == 2
    assert page.locator(".wiki-content h1#modulnest-release-prozess").count() == 1

    page.set_viewport_size({"width": 1280, "height": 900})
    assert page.locator(".wiki-sidebar").evaluate("node => getComputedStyle(node).position") == "sticky"
    assert page.locator(".wiki-toc-desktop").evaluate("node => getComputedStyle(node).position") == "sticky"
    toggle = page.locator("[data-wiki-nav-toggle]").first
    assert toggle.get_attribute("aria-expanded") == "false"
    toggle.click(position={"x": 12, "y": 10})
    assert toggle.get_attribute("aria-expanded") == "true"
    toggle.focus()
    page.keyboard.press("Space")
    assert toggle.get_attribute("aria-expanded") == "false"
    page.keyboard.press("Enter")
    assert toggle.get_attribute("aria-expanded") == "true"
    page.set_viewport_size({"width": 390, "height": 844})
    assert page.locator(".wiki-sidebar").evaluate("node => getComputedStyle(node).position") == "static"
    assert page.locator(".wiki-toc-mobile").count() == 1
    page.locator(".wiki-toc-mobile summary").click()
    assert page.locator(".wiki-toc-mobile a[href='#modulnest-release-prozess']").count() == 1
