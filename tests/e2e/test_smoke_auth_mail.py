from __future__ import annotations

from datetime import datetime
from urllib.parse import urljoin

import pytest


def _url(base_url: str, path: str) -> str:
    return urljoin(base_url.rstrip("/") + "/", path.lstrip("/"))


@pytest.mark.auth
def test_banking_module_overview_reachable(logged_in_page, base_url: str) -> None:
    page = logged_in_page
    response = page.goto(_url(base_url, "/banking"), wait_until="commit", timeout=60000)
    assert response is not None
    assert response.status < 500
    page.wait_for_load_state("domcontentloaded")
    assert page.get_by_role("heading", name="Willkommen zurück!", exact=True).is_visible()
    if page.get_by_text("Für deinen Benutzer wurden noch keine Banking-Daten importiert.").count() == 0:
        assert page.get_by_text("Umsätze gesamt").first.is_visible()
        assert page.get_by_text("Gebuchte Umsätze").is_visible()
        assert page.get_by_text("Vorgemerkt").is_visible()
        assert page.get_by_text("Saldo gesamt").is_visible()
        assert page.get_by_text("Erwartete Einnahmen (Monat)").is_visible()
        assert page.get_by_text("Erwartete Ausgaben (Monat)").is_visible()
        assert page.get_by_text("Erwarteter Saldo (Monat)").is_visible()
        assert page.get_by_text("Offene wiederkehrende Beträge").is_visible()
        assert page.get_by_text("Gebuchte wiederkehrende Beträge").is_visible()
        assert page.get_by_text("Nicht fällige Regeln").is_visible()
        assert page.get_by_role("heading", name="Monatliche Übersicht (letzte 12 Monate)").is_visible()
        assert page.get_by_text("Ist Einnahmen").is_visible()
        assert page.get_by_text("Regel Einnahmen").is_visible()
        assert page.get_by_text("Übrig").is_visible()
        assert page.get_by_label("Info zur Berechnung von Übrig").is_visible()
        assert page.get_by_role("heading", name="Letzte Buchungen").is_visible()
        assert page.get_by_role("heading", name="Top Kategorien").is_visible()
    assert page.get_by_role("link", name="Umsätze", exact=True).is_visible()
    assert page.get_by_role("link", name="Monatsübersicht", exact=True).is_visible()
    assert page.get_by_role("link", name="Wiederkehrend", exact=True).is_visible()
    assert page.get_by_role("link", name="Fälligkeitsstatus", exact=True).is_visible()
    assert page.get_by_role("link", name="Import", exact=True).is_visible()

    response = page.goto(_url(base_url, "/banking/transactions"), wait_until="commit", timeout=60000)
    assert response is not None
    assert response.status < 500
    page.wait_for_load_state("domcontentloaded")
    assert page.get_by_role("heading", name="Alle Umsätze").is_visible()
    assert page.locator("#banking-year").input_value() == str(datetime.now().year)
    assert page.get_by_label("Buchungstext").is_visible()
    assert page.get_by_label("Status").is_visible()
    assert page.get_by_text("Gesamtsumme").count() > 0 or page.get_by_text("Keine Umsätze vorhanden.").is_visible()

    response = page.goto(_url(base_url, "/banking/overview"), wait_until="commit", timeout=60000)
    assert response is not None
    assert response.status < 500
    page.wait_for_load_state("domcontentloaded")
    assert page.get_by_role("heading", name="Entwicklung nach Monaten").is_visible()
    assert page.get_by_label("Jahr").is_visible()

    response = page.goto(_url(base_url, "/banking/recurring"), wait_until="commit", timeout=60000)
    assert response is not None
    assert response.status < 500
    page.wait_for_load_state("domcontentloaded")
    assert page.get_by_role("heading", name="Wiederkehrende Zahlungen").is_visible()

    response = page.goto(_url(base_url, "/banking/recurring/overview"), wait_until="commit", timeout=60000)
    assert response is not None
    assert response.status < 500
    page.wait_for_load_state("domcontentloaded")
    assert page.get_by_role("heading", name="Status wiederkehrender Zahlungen").is_visible()
    assert page.get_by_label("Zeitraum").is_visible()

    response = page.goto(_url(base_url, "/banking/import"), wait_until="commit", timeout=60000)
    assert response is not None
    assert response.status < 500
    page.wait_for_load_state("domcontentloaded")
    assert page.get_by_role("heading", name="CSV-Umsätze importieren", exact=True).is_visible()
    assert page.locator("input[name='csv_file']").is_visible()

    response = page.goto(_url(base_url, "/banking-old"), wait_until="commit", timeout=60000)
    assert response is not None
    assert response.status < 500
    page.wait_for_load_state("domcontentloaded")
    assert "/banking-old" in page.url
    assert page.get_by_role("heading", name="Login", exact=True).count() == 0


@pytest.mark.auth
def test_admin_navigation_dropdown(logged_in_page, base_url: str) -> None:
    page = logged_in_page
    response = page.goto(_url(base_url, "/admin/modules"), wait_until="commit", timeout=60000)
    assert response is not None
    assert response.status < 500
    page.wait_for_load_state("domcontentloaded")

    admin_toggle = page.locator("#admin-nav-dropdown")
    assert admin_toggle.is_visible()
    admin_toggle.click()

    admin_dropdown = page.locator("ul[aria-labelledby='admin-nav-dropdown']")
    assert admin_dropdown.get_by_role("link", name="Modulverwaltung").is_visible()
    assert admin_dropdown.get_by_role("link", name="Benutzerverwaltung").is_visible()
    assert admin_dropdown.get_by_role("link", name="News").is_visible()
    assert admin_dropdown.get_by_role("link", name="Sneak Preview").is_visible()

    admin_tabs = page.locator("main .nav-tabs")
    assert admin_tabs.get_by_role("link", name="Modulverwaltung").is_visible()
    assert admin_tabs.get_by_role("link", name="Benutzerverwaltung").is_visible()
    assert admin_tabs.get_by_role("link", name="News").is_visible()
    assert admin_tabs.get_by_role("link", name="Sneak Preview").is_visible()
    news_module_row = page.locator("tbody tr", has=page.locator("code", has_text="news")).first
    assert news_module_row.get_by_role("link", name="Admin").get_attribute("href") == "/admin/news"

    response = page.goto(_url(base_url, "/admin/news"), wait_until="commit", timeout=60000)
    assert response is not None
    assert response.status < 500
    page.wait_for_load_state("domcontentloaded")
    assert page.get_by_role("heading", name="Admin / News").is_visible()

    response = page.goto(_url(base_url, "/news"), wait_until="commit", timeout=60000)
    assert response is not None
    assert response.status < 500
    page.wait_for_load_state("domcontentloaded")
    assert page.get_by_role("heading", name="News & Updates").is_visible()

    response = page.goto(_url(base_url, "/admin/sneak-preview"), wait_until="commit", timeout=60000)
    assert response is not None
    assert response.status < 500
    page.wait_for_load_state("domcontentloaded")
    assert page.get_by_role("heading", name="Sneak Preview / Admin").is_visible()
    assert page.get_by_role("link", name="+ Neuer Eintrag").is_visible()
    assert page.get_by_role("link", name="Anzeige-Einstellungen").is_visible()


@pytest.mark.auth
def test_user_navigation_dropdown(logged_in_page, base_url: str) -> None:
    page = logged_in_page
    response = page.goto(_url(base_url, "/dashboard"), wait_until="commit", timeout=60000)
    assert response is not None
    assert response.status < 500
    page.wait_for_load_state("domcontentloaded")

    user_toggle = page.locator("#user-nav-dropdown")
    assert user_toggle.is_visible()
    user_toggle.click()

    user_dropdown = page.locator("ul[aria-labelledby='user-nav-dropdown']")
    assert user_dropdown.get_by_role("link", name="Profil").get_attribute("href") == "/profil"
    assert user_dropdown.get_by_role("link", name="Sicherheit").get_attribute("href") == "/profil/security"
    assert user_dropdown.get_by_role("link", name="Einstellungen").get_attribute("href") == "/profil/settings"
    assert page.get_by_role("button", name="Logout").is_visible()


@pytest.mark.auth
def test_mail_module_reachable(logged_in_page, base_url: str) -> None:
    page = logged_in_page
    response = page.goto(_url(base_url, "/mail"), wait_until="commit", timeout=60000)
    assert response is not None
    assert response.status < 500
    page.wait_for_load_state("domcontentloaded")
    assert page.get_by_role("heading", name="Arbeitsbereich").is_visible()
    assert page.get_by_role("heading", name="Nachrichtenliste").is_visible()


@pytest.mark.auth
def test_open_message_and_fullview(logged_in_page, base_url: str) -> None:
    page = logged_in_page
    page.goto(_url(base_url, "/mail"), wait_until="commit", timeout=60000)
    page.wait_for_load_state("domcontentloaded")

    open_links = page.locator(".js-mail-open")
    count = open_links.count()
    if count == 0:
        pytest.skip("Keine Nachrichten im aktuellen Konto/Ordner für den Nachrichtensmoke-Test vorhanden.")

    open_links.first.click()
    detail_panel = page.locator("#mail-detail-content")
    detail_panel.get_by_role("link", name="Vollansicht").wait_for(state="visible", timeout=5000)
    assert detail_panel.is_visible()
    assert "Bitte eine Nachricht auswählen." not in detail_panel.inner_text()

    fullview_link = detail_panel.get_by_role("link", name="Vollansicht")
    assert fullview_link.is_visible()
    fullview_link.click()

    page.wait_for_url("**/mail/message**")
    assert "/mail/message" in page.url
    assert "Nachricht konnte nicht geladen werden" not in page.content()

    html_iframe = page.locator("iframe.modulon-mail-html-frame")
    if html_iframe.count() > 0:
        assert html_iframe.first.is_visible()
