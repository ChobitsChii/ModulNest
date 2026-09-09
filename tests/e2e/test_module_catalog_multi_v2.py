from __future__ import annotations

import os
import re
import shutil
from pathlib import Path
from urllib.parse import urljoin

import pytest


MODULES = {
    "modulnest.wiki": "Wiki",
    "modulnest.logs": "Logs",
    "modulnest.systeminfo": "Systeminfo",
    "modulnest.news": "News",
}


def _url(base_url: str, path: str) -> str:
    return urljoin(base_url.rstrip("/") + "/", path.lstrip("/"))


def _catalog_target() -> Path:
    raw = os.environ.get("MODULON_E2E_CATALOG_FIXTURE_TARGET", "").strip()
    if not raw:
        pytest.skip("Multi-Modul-Katalog-E2E benötigt eine isolierte Installation.")
    target = Path(raw).resolve()
    if "modulnest-e2e" not in str(target):
        pytest.fail("Der Katalog muss in einem isolierten modulnest-e2e-Pfad liegen.")
    return target


def _publish(target: Path, fixture: str) -> None:
    source = Path(__file__).resolve().parents[1] / "Fixtures" / "catalog-v1" / fixture
    stage = target.with_name(target.name + ".stage")
    if stage.exists():
        shutil.rmtree(stage)
    shutil.copytree(source, stage)
    if target.exists():
        shutil.rmtree(target)
    stage.rename(target)


def _detail(page, base_url: str, module_id: str) -> None:
    page.goto(_url(base_url, f"/admin/module-catalog/{module_id}"), wait_until="domcontentloaded")


def _install_and_activate(page, base_url: str, module_id: str) -> None:
    _detail(page, base_url, module_id)
    install = page.get_by_role("button", name="Installieren", exact=True)
    if install.count():
        install.click()
        page.wait_for_load_state("domcontentloaded")
    activate = page.get_by_role("button", name="Aktivieren", exact=True)
    if activate.count():
        activate.click()
        page.wait_for_load_state("domcontentloaded")


@pytest.mark.auth
def test_real_multi_module_catalog_and_feature_flow(page, base_url, auth_credentials, tmp_path):
    target = _catalog_target()
    _publish(target, "source-multi-sequence-1")
    identifier, password = auth_credentials
    page.goto(_url(base_url, "/login"), wait_until="domcontentloaded")
    page.locator("#email").fill(identifier)
    page.locator("#password").fill(password)
    page.get_by_role("button", name="Einloggen", exact=True).click()
    page.wait_for_load_state("domcontentloaded")
    assert "/login" not in page.url.rstrip("/")

    browser_errors: list[str] = []
    page.on("pageerror", lambda error: browser_errors.append(str(error)))
    page.on("console", lambda message: browser_errors.append(message.text) if message.type == "error" else None)

    page.goto(_url(base_url, "/admin/module-catalog?bereich=entdecken"), wait_until="domcontentloaded")
    cards = page.locator("[data-module-id]")
    visible_ids = [cards.nth(index).get_attribute("data-module-id") for index in range(cards.count())]
    for module_id, name in MODULES.items():
        assert module_id in visible_ids
        assert name in page.locator(f'[data-module-id="{module_id}"]').inner_text()
    module_positions = [visible_ids.index(module_id) for module_id in MODULES]
    expected_positions = [visible_ids.index(module_id) for module_id in sorted(MODULES, key=lambda item: MODULES[item])]
    assert sorted(module_positions) == expected_positions

    for module_id in MODULES:
        _install_and_activate(page, base_url, module_id)

    page.goto(_url(base_url, "/admin/module-catalog?bereich=installiert"), wait_until="domcontentloaded")
    for module_id in MODULES:
        text = page.locator(f'[data-module-id="{module_id}"]').inner_text()
        expected_version = "1.0.1" if module_id == "modulnest.wiki" else "1.0.0"
        assert expected_version in text and "Aktiv" in text

    page.goto(_url(base_url, "/admin/logs"), wait_until="domcontentloaded")
    assert page.get_by_role("heading", name=re.compile("Logs")).is_visible()
    assert "core_e2e_log" in page.locator("body").inner_text()

    page.goto(_url(base_url, "/systeminfo"), wait_until="domcontentloaded")
    system_text = page.locator("body").inner_text()
    assert "Systeminfo" in system_text and "App Version" in system_text
    assert "Module" in system_text or "Module aktiv" in system_text

    page.goto(_url(base_url, "/wiki"), wait_until="domcontentloaded")
    assert "Wiki" in page.locator("body").inner_text()

    page.goto(_url(base_url, "/admin/news/create"), wait_until="domcontentloaded")
    page.locator("#news_title").fill("Browser Paket-News")
    page.locator("#news_slug").fill("browser-paket-news")
    page.locator("#news_excerpt").fill("Bleibt über Update, Export und Retain erhalten.")
    page.locator("#news_content").fill("## Markdown funktioniert\n\n**Paketinhalt**")
    page.locator("#news_status").select_option("published")
    page.get_by_role("button", name=re.compile("erstellen|speichern", re.I)).click()
    page.wait_for_load_state("domcontentloaded")
    assert "News-Eintrag erstellt" in page.locator("body").inner_text()
    page.goto(_url(base_url, "/news/browser-paket-news"), wait_until="domcontentloaded")
    assert page.get_by_role("heading", name="Browser Paket-News").is_visible()
    assert page.locator("strong", has_text="Paketinhalt").is_visible()

    _detail(page, base_url, "modulnest.news")
    assert page.get_by_role("link", name="Daten exportieren").is_visible()
    assert page.get_by_role("link", name="Daten importieren").is_visible()
    page.get_by_role("link", name="Daten exportieren").click()
    page.wait_for_load_state("domcontentloaded")
    assert "News" in page.locator("body").inner_text()
    page.locator('input[name="providers[]"]').evaluate_all(
        "nodes => nodes.forEach(node => node.checked = node.value === 'news')"
    )
    with page.expect_download() as download_info:
        page.get_by_role("button", name="ZIP-Export herunterladen").click()
    export_path = tmp_path / "news-export.zip"
    download_info.value.save_as(export_path)
    assert export_path.stat().st_size > 0

    page.goto(_url(base_url, "/admin/news"), wait_until="domcontentloaded")
    row = page.locator("tbody tr", has_text="Browser Paket-News")
    page.once("dialog", lambda dialog: dialog.accept())
    row.get_by_role("button", name="Löschen").click()
    page.wait_for_load_state("domcontentloaded")
    page.goto(_url(base_url, "/admin/data-portability?module=modulnest.news&mode=import"), wait_until="domcontentloaded")
    page.locator("#import_zip").set_input_files(export_path)
    page.get_by_role("button", name="Import prüfen").click()
    page.wait_for_load_state("domcontentloaded")
    assert "Import-Vorschau" in page.locator("body").inner_text()
    page.once("dialog", lambda dialog: dialog.accept())
    page.get_by_role("button", name="Import ausführen").first.click()
    page.wait_for_load_state("domcontentloaded")
    assert "Import abgeschlossen" in page.locator("body").inner_text()
    page.goto(_url(base_url, "/news/browser-paket-news"), wait_until="domcontentloaded")
    assert page.get_by_role("heading", name="Browser Paket-News").is_visible()

    _publish(target, "source-multi-sequence-2")
    page.goto(_url(base_url, "/admin/module-catalog?bereich=updates"), wait_until="domcontentloaded")
    update_modules = ("modulnest.logs", "modulnest.systeminfo", "modulnest.news")
    for module_id in update_modules:
        assert "1.0.0 → 1.0.1" in page.locator(f'[data-module-id="{module_id}"]').inner_text()
    for module_id in update_modules:
        _detail(page, base_url, module_id)
        page.get_by_role("button", name="Aktualisieren", exact=True).click()
        page.wait_for_load_state("domcontentloaded")
        assert "1.0.1" in page.locator("body").inner_text()
    page.goto(_url(base_url, "/news/browser-paket-news"), wait_until="domcontentloaded")
    assert page.get_by_role("heading", name="Browser Paket-News").is_visible()

    for theme in ("dark", "light"):
        page.goto(_url(base_url, "/admin/module-catalog"), wait_until="domcontentloaded")
        page.locator("html").evaluate("(node, value) => node.setAttribute('data-theme', value)", theme)
        assert page.locator("html").get_attribute("data-theme") == theme
        assert page.get_by_role("heading", name="Modul-Katalog").is_visible()
        page.goto(_url(base_url, "/news"), wait_until="domcontentloaded")
        page.locator("html").evaluate("(node, value) => node.setAttribute('data-theme', value)", theme)
        assert page.get_by_role("heading", name="News & Updates", exact=True).is_visible()

    page.set_viewport_size({"width": 390, "height": 844})
    page.goto(_url(base_url, "/admin/module-catalog"), wait_until="domcontentloaded")
    assert page.locator("main").evaluate("node => node.scrollWidth <= node.clientWidth")
    _detail(page, base_url, "modulnest.news")
    assert page.locator("main").evaluate("node => node.scrollWidth <= node.clientWidth")

    page.get_by_role("button", name="Deaktivieren", exact=True).click()
    page.wait_for_load_state("domcontentloaded")
    page.get_by_role("button", name="Modul entfernen, Daten behalten").click()
    page.wait_for_load_state("domcontentloaded")
    assert "Daten vorhanden" in page.locator("body").inner_text()
    page.get_by_role("button", name="Neu installieren").click()
    page.wait_for_load_state("domcontentloaded")
    page.get_by_role("button", name="Aktivieren", exact=True).click()
    page.wait_for_load_state("domcontentloaded")
    page.goto(_url(base_url, "/news/browser-paket-news"), wait_until="domcontentloaded")
    assert page.get_by_role("heading", name="Browser Paket-News").is_visible()

    _detail(page, base_url, "modulnest.news")
    page.get_by_role("button", name="Deaktivieren", exact=True).click()
    page.wait_for_load_state("domcontentloaded")
    page.get_by_role("button", name="Modul entfernen, Daten behalten").click()
    page.wait_for_load_state("domcontentloaded")
    page.locator("#confirm_module_id").fill("modulnest.news")
    page.get_by_role("button", name="Daten endgültig löschen").click()
    page.wait_for_load_state("domcontentloaded")
    assert "endgültig gelöscht" in page.locator("body").inner_text()
    page.get_by_role("button", name="Installieren", exact=True).click()
    page.wait_for_load_state("domcontentloaded")
    page.get_by_role("button", name="Aktivieren", exact=True).click()
    page.wait_for_load_state("domcontentloaded")
    browser_errors.clear()
    page.goto(_url(base_url, "/news/browser-paket-news"), wait_until="domcontentloaded")
    assert page.locator("body").inner_text().find("Browser Paket-News") == -1
    browser_errors.clear()  # The expected 404 is intentionally reported by Chromium.
    assert not browser_errors
