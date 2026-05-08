from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path
from typing import Dict
from urllib.parse import urljoin

import pytest


@dataclass(frozen=True)
class E2ESettings:
    base_url: str
    login_identifier: str
    login_password: str


def _read_simple_env_file(path: Path) -> Dict[str, str]:
    values: Dict[str, str] = {}
    if not path.is_file():
        return values

    for raw_line in path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip()

    return values


def _env_value(name: str, fallback: str = "") -> str:
    local_env = Path(".local/e2e/local.env")
    local_values = _read_simple_env_file(local_env)
    value = os.environ.get(name, local_values.get(name, fallback))
    return (value or fallback).strip()


def _abs_url(base_url: str, path: str) -> str:
    return urljoin(base_url.rstrip("/") + "/", path.lstrip("/"))


@pytest.fixture(scope="session")
def e2e_settings() -> E2ESettings:
    return E2ESettings(
        base_url=_env_value("MODULON_E2E_BASE_URL", "http://127.0.0.1:8080"),
        login_identifier=_env_value("MODULON_E2E_LOGIN", ""),
        login_password=_env_value("MODULON_E2E_PASSWORD", ""),
    )


@pytest.fixture(scope="session")
def base_url(e2e_settings: E2ESettings) -> str:
    return e2e_settings.base_url


@pytest.fixture(scope="session")
def auth_credentials(e2e_settings: E2ESettings) -> tuple[str, str]:
    if not e2e_settings.login_identifier or not e2e_settings.login_password:
        pytest.skip(
            "Keine Login-Credentials gesetzt. "
            "Setze MODULON_E2E_LOGIN und MODULON_E2E_PASSWORD "
            "(optional in .local/e2e/local.env)."
        )
    return e2e_settings.login_identifier, e2e_settings.login_password


@pytest.fixture()
def logged_in_page(page, e2e_settings: E2ESettings, auth_credentials: tuple[str, str]):
    identifier, password = auth_credentials
    page.goto(_abs_url(e2e_settings.base_url, "/login"), wait_until="domcontentloaded")

    page.locator("#email").fill(identifier)
    page.locator("#password").fill(password)
    page.get_by_role("button", name="Einloggen", exact=True).click()

    page.wait_for_load_state("domcontentloaded")

    if "/login/2fa" in page.url:
        pytest.skip("Login erfordert 2FA; dieser Smoke-Test erwartet ein nicht-2FA Testkonto.")

    assert "Login fehlgeschlagen" not in page.content()
    assert "/login" not in page.url.rstrip("/")
    return page
