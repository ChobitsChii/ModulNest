from __future__ import annotations

from urllib.parse import urljoin

import pytest


def _url(base_url: str, path: str) -> str:
    return urljoin(base_url.rstrip("/") + "/", path.lstrip("/"))


@pytest.mark.auth
def test_tools_public_page_and_client_tools(logged_in_page, base_url: str) -> None:
    page = logged_in_page
    response = page.goto(_url(base_url, "/tools"), wait_until="domcontentloaded")
    assert response is not None
    assert response.status < 500

    assert page.get_by_role("heading", name="Hilfs- und Entwickler-Tools").is_visible()
    assert page.get_by_role("heading", name="Textzähler").is_visible()
    text_input = page.locator("#tools-text-input")
    text_input.fill("Hallo Modulon\n\nzweite Zeile")
    assert page.locator("#tools-count-words").inner_text() == "4"

    scroll_before = page.evaluate("window.scrollY")
    page.locator("[data-tools-category-tab='text']").click()
    assert page.locator("[data-tools-category-tab='text']").evaluate("(node) => node.classList.contains('active')") is True
    assert page.locator("#text-cleaner").is_visible()
    assert page.locator("#hash-generator").is_visible() is False
    assert page.evaluate("window.scrollY") == scroll_before
    page.locator("[data-tools-category-tab='overview']").click()

    page.goto(_url(base_url, "/tools#tools-sicherheit"), wait_until="domcontentloaded")
    assert page.locator("[data-tools-category-tab='sicherheit']").evaluate("(node) => node.classList.contains('active')") is True
    assert page.locator("#password-generator").is_visible()
    assert page.locator("#hash-generator").is_visible()
    assert page.locator("#text-cleaner").is_visible() is False
    assert page.evaluate("window.scrollY") == 0

    page.goto(_url(base_url, "/tools#tools-text"), wait_until="domcontentloaded")
    assert page.locator("[data-tools-category-tab='text']").evaluate("(node) => node.classList.contains('active')") is True
    page.evaluate("window.location.hash = '#tools-sicherheit'")
    page.wait_for_function("document.querySelector('[data-tools-category-tab=\"sicherheit\"]').classList.contains('active')")
    assert page.locator("#hash-generator").is_visible()
    assert page.locator("#text-cleaner").is_visible() is False
    page.locator("[data-tools-category-tab='overview']").click()

    clean_input = page.locator("#tools-clean-input")
    clean_output = page.locator("#tools-clean-output")
    clean_input.fill("ICH BIN EINE ÜBERSCHRIFT. DAS IST EIN TEST.")
    page.get_by_role("button", name="Alles klein").click()
    assert clean_output.input_value() == "ich bin eine überschrift. das ist ein test."
    page.get_by_role("button", name="Jedes Wort groß").click()
    assert clean_output.input_value() == "Ich Bin Eine Überschrift. Das Ist Ein Test."
    page.get_by_role("button", name="Satzanfänge groß").click()
    assert clean_output.input_value() == "Ich bin eine überschrift. Das ist ein test."

    page.locator("#tools-json-input").fill('{"ok":true}')
    page.locator("#tools-json-format").click()
    assert "JSON ist gültig" in page.locator("#tools-json-status").inner_text()

    page.locator("#tools-hash-input").fill("abc")
    page.locator("#tools-hash-algorithm").select_option("SHA-256")
    page.locator("#tools-hash-generate").click()
    assert "ba7816bf8f01cfea414140de5dae2223" in page.locator("#tools-hash-output").inner_text()
    if page.evaluate("!window.crypto?.subtle"):
        page.locator("#tools-hash-algorithm").select_option("SHA-384")
        page.locator("#tools-hash-generate").click()
        assert "Web Crypto API" in page.locator("#tools-hash-output").inner_text()

    page.locator("#tools-password-length").fill("12")
    page.locator("#tools-password-count").fill("1")
    page.locator("#tools-password-generate").click()
    assert len(page.locator("#tools-password-output").input_value().strip()) == 12
    page.locator("#tools-password-count").fill("50")
    page.locator("#tools-password-lower").uncheck()
    page.locator("#tools-password-upper").uncheck()
    page.locator("#tools-password-symbols").uncheck()
    page.locator("#tools-password-numbers").check()
    page.locator("#tools-password-generate").click()
    passwords = page.locator("#tools-password-output").input_value().strip().splitlines()
    assert len(passwords) == 50
    assert all(password.isdigit() for password in passwords)
    page.locator("#tools-password-numbers").uncheck()
    page.locator("#tools-password-generate").click()
    assert "mindestens eine Zeichengruppe" in page.locator("#tools-password-status").inner_text()

    page.locator("#tools-qr-input").fill("https://modulon.local/tools")
    page.locator("#tools-qr-generate").click()
    assert "QR-Code erzeugt" in page.locator("#tools-qr-status").inner_text()
    qr_has_dark_pixels = page.locator("#tools-qr-canvas").evaluate(
        """(canvas) => {
            const data = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data;
            for (let i = 0; i < data.length; i += 4) {
                if (data[i] < 32 && data[i + 1] < 32 && data[i + 2] < 32 && data[i + 3] > 0) {
                    return true;
                }
            }
            return false;
        }"""
    )
    assert qr_has_dark_pixels is True

    for slug, heading in [
        ("text-cleaner", "Textbereinigung"),
        ("hash-generator", "Hash Generator"),
        ("password-generator", "Passwort Generator"),
    ]:
        response = page.goto(_url(base_url, f"/tools/{slug}"), wait_until="domcontentloaded")
        assert response is not None
        assert response.status < 500
        assert page.get_by_role("heading", name=heading).first.is_visible()
        assert page.get_by_role("link", name="Zur Tools-Übersicht").is_visible()
        assert page.locator(".tools-card-single").is_visible()
        assert page.locator(".tools-card-single h3", has_text=heading).count() == 0
        card_width = page.locator(".tools-card-single").evaluate("(node) => node.getBoundingClientRect().width")
        viewport_width = page.evaluate("window.innerWidth")
        assert card_width > viewport_width * 0.65


@pytest.mark.auth
def test_tools_admin_page_and_dns_tool(logged_in_page, base_url: str) -> None:
    page = logged_in_page
    response = page.goto(_url(base_url, "/admin/tools"), wait_until="domcontentloaded")
    assert response is not None
    assert response.status < 500

    assert page.get_by_role("heading", name="Diagnose- und Sicherheitswerkzeuge").is_visible()
    assert page.get_by_role("heading", name="DNS Lookup").is_visible()
    assert page.get_by_role("heading", name="Speech-to-Text", exact=True).is_visible()
    assert page.locator("input[name='audio_file']").is_visible()
    assert page.locator("select[name='language']").is_visible()
    assert page.get_by_text("ffmpeg:").is_visible()
    assert page.get_by_text("whisper.cpp:").is_visible()
    assert page.get_by_text("Modell:").first.is_visible()
    speech_status = page.evaluate("fetch('/admin/tools/speech/status').then((response) => response.json())")
    assert speech_status["ok"] is True
    assert "jobs" in speech_status
    if speech_status["jobs"]:
        assert page.locator("#tools-speech-jobs").get_by_text("Modell:").first.is_visible()
        assert page.locator("#tools-speech-jobs").get_by_role("button", name="Löschen").first.is_visible()

    form = page.locator("form", has=page.locator("input[name='tool'][value='dns']")).first
    form.locator("input[name='host']").fill("example.com")
    form.get_by_role("button", name="Prüfen").click()

    result = page.locator("#tools-admin-result")
    result.get_by_text("DNS Lookup").wait_for(state="visible", timeout=10000)
    assert "example.com" in result.inner_text()
