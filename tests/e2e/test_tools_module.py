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

    page.locator("#tools-json-input").fill('{"ok":true}')
    page.locator("#tools-json-format").click()
    assert "JSON ist gültig" in page.locator("#tools-json-status").inner_text()

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
