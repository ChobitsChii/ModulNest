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


def test_wiki_local_picker_tracks_source_type(logged_in_page, base_url):
    if os.environ.get("MODULON_E2E_WIKI_READY") != "1":
        pytest.skip("Wiki-E2E benötigt eine isolierte Installation mit aktivierter, synchronisierter Wiki-Quelle.")
    page = logged_in_page
    page.goto(urljoin(base_url.rstrip('/') + '/', 'admin/wiki'))
    source_type = page.locator("[data-wiki-source-type]")
    picker = page.locator("[data-wiki-directory-picker]")
    assert source_type.count() == 1
    assert picker.count() == 1

    source_type.select_option("github")
    assert not picker.is_visible()
    source_type.select_option("local")
    assert picker.is_visible()
    source_type.select_option("github")
    assert not picker.is_visible()


def test_wiki_local_search_and_admin_rebuild(logged_in_page, base_url):
    if os.environ.get("MODULON_E2E_WIKI_READY") != "1":
        pytest.skip("Wiki-E2E benötigt eine isolierte Installation mit aktivierter, synchronisierter Wiki-Quelle.")
    page = logged_in_page
    page.goto(urljoin(base_url.rstrip('/') + '/', 'admin/wiki'))
    status = page.locator("[data-wiki-search-status]")
    assert status.count() == 1
    rebuild = page.get_by_role("button", name="Suchindex neu aufbauen")
    assert rebuild.is_enabled()
    assert rebuild.bounding_box()["y"] >= status.bounding_box()["y"] + status.bounding_box()["height"]
    rebuild.click()
    page.wait_for_load_state("domcontentloaded")
    assert "Aktuell" in status.inner_text()

    page.goto(urljoin(base_url.rstrip('/') + '/', 'wiki'))
    search = page.locator("[data-wiki-search-input]")
    search.fill("Banking")
    page.wait_for_timeout(450)
    popover = page.locator(".wiki-search-popover")
    sidebar = page.locator(".wiki-sidebar")
    assert popover.bounding_box()["width"] > sidebar.bounding_box()["width"]
    assert popover.evaluate("node => node.scrollWidth <= node.clientWidth")
    assert popover.locator("mark").count() >= 1
    search.press("Enter")
    page.wait_for_load_state("domcontentloaded")
    assert "/wiki/search?q=Banking" in page.url
    assert page.locator(".wiki-search-page-results mark").count() >= 1

    page.goto(urljoin(base_url.rstrip('/') + '/', 'wiki'))
    search = page.locator("[data-wiki-search-input]")
    search.fill("Konfiguration")
    page.wait_for_timeout(450)
    results = page.locator("[data-wiki-search-results] .wiki-search-result")
    assert results.count() >= 1
    assert "Konfiguration" in results.first.inner_text()
    results.first.locator("a").click()
    page.wait_for_load_state("domcontentloaded")
    assert page.url.endswith("/wiki/configuration")
    search = page.locator("[data-wiki-search-input]")
    search.fill("konfig")
    page.wait_for_timeout(450)
    assert results.count() >= 1
    search.fill("Konfigration")
    page.wait_for_timeout(450)
    assert results.count() >= 1
    search.press("ArrowDown")
    assert page.locator("[data-wiki-search-results] a:focus").count() == 1
    search.press("Escape")
    assert search.get_attribute("aria-expanded") == "false"
    search.fill("x")
    page.wait_for_timeout(350)
    assert search.get_attribute("aria-expanded") == "false"
    search.fill("")
    assert search.get_attribute("aria-expanded") == "false"

    for theme in ("dark", "light"):
        page.locator("html").evaluate("(node, value) => node.setAttribute('data-theme', value)", theme)
        search.fill("CSRF")
        page.wait_for_timeout(450)
        assert results.count() >= 1
    page.set_viewport_size({"width": 390, "height": 844})
    assert search.is_visible()
    search.fill("WebAuthn")
    page.wait_for_timeout(450)
    assert popover.evaluate("node => { const r=node.getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth && node.scrollWidth <= node.clientWidth && document.documentElement.scrollWidth <= innerWidth; }")
