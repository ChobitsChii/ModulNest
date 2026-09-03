"""Run after copying ExampleNotes into an isolated ModulNest test installation."""
from urllib.parse import urljoin

import pytest
from playwright.sync_api import expect


@pytest.mark.auth
def test_example_notes_create_and_toggle(logged_in_page, base_url: str) -> None:
    page = logged_in_page
    page.goto(urljoin(base_url.rstrip('/') + '/', 'example-notes'))
    page.locator('input[name="title"]').fill('Example E2E note')
    page.get_by_role('button', name='Erstellen').click()
    page.wait_for_load_state('domcontentloaded')
    item = page.get_by_text('Example E2E note').first
    expect(item).to_be_visible()
    item.locator('..').get_by_role('button').click()
