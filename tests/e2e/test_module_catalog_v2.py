from __future__ import annotations

import os
import re
import json
import shutil
from pathlib import Path
from urllib.parse import urljoin

import pytest


MODULE_ID = "example.example-notes"


def _url(base_url: str, path: str) -> str:
    return urljoin(base_url.rstrip("/") + "/", path.lstrip("/"))


def _catalog_target() -> Path:
    value = os.environ.get("MODULON_E2E_CATALOG_FIXTURE_TARGET", "").strip()
    if not value:
        pytest.skip("Katalog-E2E benötigt eine isolierte Installation und MODULON_E2E_CATALOG_FIXTURE_TARGET.")
    target = Path(value).resolve()
    if "modulnest-e2e" not in str(target):
        pytest.fail("Der veränderbare Katalog muss innerhalb eines isolierten modulnest-e2e-Testpfads liegen.")
    return target


def _publish_fixture(target: Path, name: str) -> None:
    source = Path(__file__).resolve().parents[1] / "Fixtures" / "catalog-v1" / name
    stage = target.with_name(target.name + ".stage")
    if stage.exists():
        shutil.rmtree(stage)
    shutil.copytree(source, stage)
    if target.exists():
        shutil.rmtree(target)
    stage.rename(target)


def _detail(page, base_url: str):
    page.goto(_url(base_url, f"/admin/module-catalog/{MODULE_ID}"), wait_until="domcontentloaded")


@pytest.mark.auth
def test_catalog_full_example_notes_lifecycle(page, base_url, auth_credentials):
    target = _catalog_target()
    _publish_fixture(target, "source-sequence-1")
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

    page.goto(_url(base_url, "/admin/module-catalog"), wait_until="domcontentloaded")
    assert page.get_by_role("heading", name="Modul-Katalog").is_visible()
    for tab in ("Entdecken", "Installiert", "Updates"):
        assert page.get_by_role("link", name=re.compile(rf"^{tab}")).is_visible()
    card = page.locator(f'[data-module-id="{MODULE_ID}"]')
    assert "Example Notes" in card.inner_text()
    card.get_by_role("link", name="Details").click()
    page.wait_for_load_state("domcontentloaded")
    assert "0.1.0" in page.locator("body").inner_text()

    missing_csrf = page.evaluate("""async id => (await fetch('/admin/module-catalog/action', {
        method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({module_id: id, action: 'install'})
    })).status""", MODULE_ID)
    assert missing_csrf == 419
    # Chromium reports the intentionally rejected fetch as a console error;
    # subsequent unexpected browser errors still fail the acceptance test.
    browser_errors.clear()

    signature_path = target / "catalog/v1/root.json.sig"
    signature = json.loads(signature_path.read_text(encoding="utf-8"))
    signature["signature"] = "A" * 88
    signature_path.write_text(json.dumps(signature), encoding="utf-8")
    page.reload(wait_until="domcontentloaded")
    assert "letzter gültiger Stand" in page.locator("body").inner_text()
    _publish_fixture(target, "source-sequence-1")
    _detail(page, base_url)

    install_form = page.locator('form:has(input[value="install"])')
    install_form.evaluate("form => form.addEventListener('submit', event => event.preventDefault(), {capture: true})")
    install_form.evaluate("form => { form.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true})); form.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true})); }")
    assert install_form.get_by_role("button").is_disabled()
    _detail(page, base_url)

    package = target / "packages/example.example-notes-0.1.0.zip"
    missing = package.with_suffix(".missing")
    package.rename(missing)
    page.get_by_role("button", name="Installieren", exact=True).click()
    page.locator("[data-catalog-error]").wait_for()
    assert "Katalogdatei fehlt" in page.locator("[data-catalog-error]").inner_text()
    missing.rename(package)
    original_package = package.read_bytes()
    tampered_package = bytearray(original_package)
    tampered_package[min(100, len(tampered_package) - 1)] ^= 1
    package.write_bytes(tampered_package)
    page.get_by_role("button", name="Installieren", exact=True).click()
    page.locator("[data-catalog-error]").wait_for()
    assert "SHA-256" in page.locator("[data-catalog-error]").inner_text()
    package.write_bytes(original_package)

    page.get_by_role("button", name="Installieren", exact=True).click()
    page.wait_for_load_state("domcontentloaded")
    assert "zunächst deaktiviert" in page.locator("body").inner_text()
    page.get_by_role("button", name="Aktivieren", exact=True).click()
    page.wait_for_load_state("domcontentloaded")
    page.goto(_url(base_url, "/example-notes"), wait_until="domcontentloaded")
    assert page.get_by_role("heading", name="Example Notes 0.1").is_visible()
    asset_href = page.locator('link[href*="/assets/modules/example.example-notes/"]').get_attribute("href")
    assert asset_href and page.request.get(_url(base_url, asset_href)).status == 200
    page.locator('input[name="title"]').fill("Browser bleibt")
    page.locator('textarea[name="body"]').fill("Diese Notiz überlebt Update und Retain-Reinstall.")
    page.get_by_role("button", name="Testnotiz speichern").click()
    page.wait_for_load_state("domcontentloaded")
    assert "Browser bleibt" in page.locator("body").inner_text()

    offline = target.with_name(target.name + ".offline")
    target.rename(offline)
    try:
        page.reload(wait_until="domcontentloaded")
        assert "Browser bleibt" in page.locator("body").inner_text()
        page.goto(_url(base_url, "/admin/module-catalog"), wait_until="domcontentloaded")
        assert "letzter gültiger Stand" in page.locator("body").inner_text()
    finally:
        offline.rename(target)

    _publish_fixture(target, "source-sequence-2")
    page.goto(_url(base_url, "/admin/module-catalog?bereich=updates"), wait_until="domcontentloaded")
    assert "0.1.0 → 0.2.0" in page.locator(f'[data-module-id="{MODULE_ID}"]').inner_text()
    page.locator(f'[data-module-id="{MODULE_ID}"]').get_by_role("link", name="Details").click()
    page.wait_for_load_state("domcontentloaded")
    assert "Datenbankmigrationen: 1" in page.locator("body").inner_text()
    page.get_by_role("button", name="Aktualisieren", exact=True).click()
    page.wait_for_load_state("domcontentloaded")
    page.goto(_url(base_url, "/example-notes"), wait_until="domcontentloaded")
    assert page.get_by_role("heading", name="Example Notes 0.2").is_visible()
    assert "Browser bleibt" in page.locator("body").inner_text()

    _detail(page, base_url)
    page.get_by_role("button", name="Deaktivieren", exact=True).click()
    page.wait_for_load_state("domcontentloaded")
    page.get_by_role("button", name="Aktivieren", exact=True).click()
    page.wait_for_load_state("domcontentloaded")
    page.get_by_role("button", name="Modul entfernen, Daten behalten").click()
    page.wait_for_load_state("domcontentloaded")
    assert "Daten vorhanden" in page.locator("body").inner_text()
    page.get_by_role("button", name="Neu installieren").click()
    page.wait_for_load_state("domcontentloaded")
    page.get_by_role("button", name="Aktivieren", exact=True).click()
    page.wait_for_load_state("domcontentloaded")
    page.goto(_url(base_url, "/example-notes"), wait_until="domcontentloaded")
    assert "Browser bleibt" in page.locator("body").inner_text()

    _detail(page, base_url)
    page.get_by_role("button", name="Modul entfernen, Daten behalten").click()
    page.wait_for_load_state("domcontentloaded")
    page.locator("#confirm_module_id").fill(MODULE_ID)
    page.get_by_role("button", name="Daten endgültig löschen").click()
    page.locator("[data-catalog-message]").wait_for()

    # Signed negative catalogs prove that failures remain understandable and
    # never replace the active/retained release with a broken intermediate one.
    _publish_fixture(target, "source-expired")
    _detail(page, base_url)
    assert "abgelaufen" in page.locator("[data-catalog-warning]").inner_text()

    _publish_fixture(target, "source-incompatible")
    _detail(page, base_url)
    assert "Benötigt ModulNest" in page.locator("body").inner_text()
    assert page.get_by_role("button", name="Installieren", exact=True).is_disabled()

    _publish_fixture(target, "source-dependency-missing")
    _detail(page, base_url)
    assert "missing.required-module" in page.locator("body").inner_text()
    assert page.get_by_role("button", name="Installieren", exact=True).is_disabled()

    _publish_fixture(target, "source-package-signature-invalid")
    _detail(page, base_url)
    page.get_by_role("button", name="Installieren", exact=True).click()
    page.locator("[data-catalog-error]").wait_for()
    assert "Signatur" in page.locator("[data-catalog-error]").inner_text()

    _publish_fixture(target, "source-installable")
    _detail(page, base_url)
    page.get_by_role("button", name="Installieren", exact=True).click()
    page.locator("[data-catalog-message]").wait_for()
    page.get_by_role("button", name="Aktivieren", exact=True).click()
    page.wait_for_load_state("domcontentloaded")
    page.goto(_url(base_url, "/example-notes"), wait_until="domcontentloaded")
    page.locator('input[name="title"]').fill("Fehlerpfad bleibt")
    page.locator('textarea[name="body"]').fill("Rollback schützt diesen Datensatz.")
    page.get_by_role("button", name="Testnotiz speichern").click()
    page.wait_for_load_state("domcontentloaded")

    _publish_fixture(target, "source-conflict")
    _detail(page, base_url)
    page.get_by_role("button", name="Aktualisieren", exact=True).click()
    page.locator("[data-catalog-error]").wait_for()
    assert "Konflikt" in page.locator("[data-catalog-error]").inner_text()

    _publish_fixture(target, "source-health-fail")
    _detail(page, base_url)
    backup_root = target.parent / "app/storage/backups/modules"
    if backup_root.exists():
        shutil.rmtree(backup_root)
    backup_root.write_text("TEST ONLY backup failure", encoding="utf-8")
    page.get_by_role("button", name="Aktualisieren", exact=True).click()
    page.locator("[data-catalog-error]").wait_for()
    assert "Backupordner" in page.locator("[data-catalog-error]").inner_text()
    backup_root.unlink()
    backup_root.mkdir(parents=True)
    page.get_by_role("button", name="Aktualisieren", exact=True).click()
    page.locator("[data-catalog-error]").wait_for()
    assert "Health-Check" in page.locator("[data-catalog-error]").inner_text()

    _publish_fixture(target, "source-migration-fail")
    _detail(page, base_url)
    page.get_by_role("button", name="Aktualisieren", exact=True).click()
    page.locator("[data-catalog-error]").wait_for()
    assert "migration failed" in page.locator("[data-catalog-error]").inner_text()
    page.goto(_url(base_url, "/example-notes"), wait_until="domcontentloaded")
    assert page.get_by_role("heading", name="Example Notes 0.2").is_visible()
    assert "Fehlerpfad bleibt" in page.locator("body").inner_text()

    _detail(page, base_url)
    page.get_by_role("button", name="Deaktivieren", exact=True).click()
    page.wait_for_load_state("domcontentloaded")
    page.get_by_role("button", name="Modul entfernen, Daten behalten").click()
    page.wait_for_load_state("domcontentloaded")
    page.locator("#confirm_module_id").fill(MODULE_ID)
    page.get_by_role("button", name="Daten endgültig löschen").click()
    page.locator("[data-catalog-message]").wait_for()
    page.wait_for_load_state("domcontentloaded")
    assert "endgültig gelöscht" in page.locator("body").inner_text()
    _publish_fixture(target, "source-installable-final")
    _detail(page, base_url)
    page.get_by_role("button", name="Installieren", exact=True).click()
    page.wait_for_load_state("domcontentloaded")
    page.get_by_role("button", name="Aktivieren", exact=True).click()
    page.wait_for_load_state("domcontentloaded")
    page.goto(_url(base_url, "/example-notes"), wait_until="domcontentloaded")
    assert "Browser bleibt" not in page.locator("body").inner_text()

    for theme in ("light", "dark"):
        page.locator("html").evaluate("(node, value) => node.setAttribute('data-theme', value)", theme)
        _detail(page, base_url)
        assert page.locator(".catalog-details").is_visible()
    page.set_viewport_size({"width": 390, "height": 844})
    assert page.locator(".catalog-details").evaluate("node => node.scrollWidth <= node.clientWidth")
    assert not browser_errors

    # Leave the isolated acceptance database clean.
    page.get_by_role("button", name="Deaktivieren", exact=True).click()
    page.wait_for_load_state("domcontentloaded")
    page.get_by_role("button", name="Modul entfernen, Daten behalten").click()
    page.wait_for_load_state("domcontentloaded")
    page.locator("#confirm_module_id").fill(MODULE_ID)
    page.get_by_role("button", name="Daten endgültig löschen").click()
    page.locator("[data-catalog-message]").wait_for()


def test_catalog_is_admin_only(page, base_url):
    response = page.goto(_url(base_url, "/admin/module-catalog"), wait_until="domcontentloaded")
    assert response is not None
    assert response.status in (302, 403) or "/login" in page.url
