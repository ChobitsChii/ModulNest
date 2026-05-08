from __future__ import annotations

import time
import re
from urllib.parse import urljoin

import pytest
from playwright.sync_api import expect


def _url(base_url: str, path: str) -> str:
    return urljoin(base_url.rstrip("/") + "/", path.lstrip("/"))


@pytest.mark.auth
def test_dashboard_auto_refresh_pauses_while_task_dialog_is_open(logged_in_page, base_url: str) -> None:
    page = logged_in_page
    page.set_viewport_size({"width": 1366, "height": 900})
    page.goto(_url(base_url, "/dashboard"), wait_until="domcontentloaded")

    toggle = page.locator("#dashboard-auto-refresh-toggle")
    if toggle.is_visible() and not toggle.is_checked():
        toggle.click()
        expect(page.locator("#dashboard-auto-refresh-status-value")).to_contain_text("Aktiv")

    task_title = f"E2E Auto-Refresh Pause {int(time.time())}"
    page.get_by_role("button", name="+ Aufgabe anlegen").first.click()
    create_form = page.locator('form[action="/dashboard/tasks/create"]').first
    expect(create_form).to_be_visible()
    create_form.locator('input[name="title"]').fill(task_title)
    create_form.locator('input[name="link_url"]').fill("https://example.org/")
    create_form.get_by_role("button", name="Aufgabe speichern").click()
    page.wait_for_load_state("domcontentloaded")

    task_item = page.locator(".modulon-task-item").filter(has_text=task_title).first
    expect(task_item).to_be_visible()
    link_button = task_item.locator(".js-task-open-link")
    done_badge = task_item.locator(".js-task-done-badge")
    task_checkbox = task_item.locator(".js-task-toggle")

    task_checkbox.check()
    expect(task_item).to_have_class(re.compile(r"\bis-done\b"))
    expect(done_badge).to_be_visible()
    expect(done_badge).to_have_text("Erledigt")

    task_checkbox.uncheck()
    expect(task_item).not_to_have_class(re.compile(r"\bis-done\b"))
    expect(done_badge).to_have_count(0)

    with page.expect_popup() as popup_info:
        link_button.click()
    popup = popup_info.value
    popup.close()

    modal = page.locator("#task-complete-modal")
    expect(modal).to_be_visible()
    countdown = page.locator("#dashboard-auto-refresh-countdown")
    expect(countdown).to_contain_text("pausiert")

    page.wait_for_timeout(1200)
    expect(modal).to_be_visible()
    expect(countdown).to_contain_text("pausiert")

    page.get_by_role("button", name="Nein, offen lassen").click()
    expect(modal).to_be_hidden()
    expect(countdown).not_to_contain_text("pausiert")
    expect(countdown).to_contain_text("Nächster Refresh in")

    with page.expect_popup() as second_popup_info:
        link_button.click()
    second_popup = second_popup_info.value
    second_popup.close()

    expect(modal).to_be_visible()
    expect(countdown).to_contain_text("pausiert")
    page.get_by_role("button", name="Ja, als erledigt markieren").click()
    expect(modal).to_be_hidden()
    expect(countdown).not_to_contain_text("pausiert")
    expect(task_item).to_have_class(re.compile(r"\bis-done\b"))
    expect(done_badge).to_be_visible()
    expect(done_badge).to_have_text("Erledigt")


@pytest.mark.auth
def test_dashboard_widget_window_controls(logged_in_page, base_url: str) -> None:
    page = logged_in_page
    page.set_viewport_size({"width": 1366, "height": 900})
    page.goto(_url(base_url, "/dashboard"), wait_until="domcontentloaded")

    widget_title = f"E2E Widget {int(time.time())}"
    page.locator("#dashboard_new_widget_type").select_option("notes")
    page.locator("#dashboard_new_widget_title").fill(widget_title)
    page.locator("#dashboard_new_widget_width").select_option("6")
    page.get_by_role("button", name="Widget hinzufügen").click()
    page.wait_for_load_state("domcontentloaded")

    widget = page.locator(".dashboard-widget-shell").filter(has_text=widget_title).first
    expect(widget).to_be_visible()
    expect(widget).to_have_attribute("data-widget-width", "6")
    widget_id = widget.get_attribute("data-widget-id")
    assert widget_id is not None

    widget.locator(".js-widget-width").click()
    expect(widget).to_have_attribute("data-widget-width", "12")

    widget.locator(".js-widget-collapse").click()
    expect(widget).to_have_class(re.compile(r"\bis-widget-collapsed\b"))
    widget.locator(".js-widget-collapse").click()
    expect(widget).not_to_have_class(re.compile(r"\bis-widget-collapsed\b"))

    widget.locator(".js-widget-close").click()
    expect(widget).to_have_count(0)

    row = page.locator(f'tr[data-widget-row-id="{widget_id}"]')
    expect(row.get_by_text("geschlossen")).to_be_visible()
    row.get_by_role("button", name="Wiederherstellen").click()
    page.wait_for_load_state("domcontentloaded")

    widget = page.locator(".dashboard-widget-shell").filter(has_text=widget_title).first
    expect(widget).to_be_visible()

    renamed_title = f"{widget_title} renamed"
    row = page.locator(f'tr[data-widget-row-id="{widget_id}"]')
    row.locator('input[name="title"]').fill(renamed_title)
    row.get_by_role("button", name="Speichern").click()
    page.wait_for_load_state("domcontentloaded")
    widget = page.locator(".dashboard-widget-shell").filter(has_text=renamed_title).first
    expect(widget).to_be_visible()

    row = page.locator(f'tr[data-widget-row-id="{widget_id}"]')
    page.once("dialog", lambda dialog: dialog.accept())
    row.get_by_role("button", name="Löschen").click()
    page.wait_for_load_state("domcontentloaded")
    expect(page.locator(f'tr[data-widget-row-id="{widget_id}"]')).to_have_count(0)


@pytest.mark.auth
def test_dashboard_widget_drag_reorder_persists(logged_in_page, base_url: str) -> None:
    page = logged_in_page
    page.set_viewport_size({"width": 1366, "height": 900})
    page.goto(_url(base_url, "/dashboard"), wait_until="domcontentloaded")

    suffix = int(time.time())
    first_title = f"E2E Drag A {suffix}"
    second_title = f"E2E Drag B {suffix}"

    for title in (first_title, second_title):
        page.locator("#dashboard_new_widget_type").select_option("notes")
        page.locator("#dashboard_new_widget_title").fill(title)
        page.locator("#dashboard_new_widget_width").select_option("6")
        page.get_by_role("button", name="Widget hinzufügen").click()
        page.wait_for_load_state("domcontentloaded")

    first_widget = page.locator(".dashboard-widget-shell").filter(has_text=first_title).first
    second_widget = page.locator(".dashboard-widget-shell").filter(has_text=second_title).first
    expect(first_widget).to_be_visible()
    expect(second_widget).to_be_visible()
    first_widget_id = first_widget.get_attribute("data-widget-id")
    second_widget_id = second_widget.get_attribute("data-widget-id")
    assert first_widget_id is not None
    assert second_widget_id is not None
    first_widget.scroll_into_view_if_needed()
    second_widget.scroll_into_view_if_needed()

    first_box = first_widget.locator(".dashboard-widget-titlebar").bounding_box()
    second_box = second_widget.locator(".dashboard-widget-titlebar").bounding_box()
    assert first_box is not None
    assert second_box is not None

    page.mouse.move(second_box["x"] + 20, second_box["y"] + second_box["height"] / 2)
    page.mouse.down()
    page.mouse.move(first_box["x"] + 5, first_box["y"] + first_box["height"] / 2, steps=8)
    page.mouse.up()

    expect(page.locator("#dashboard-ajax-feedback")).to_contain_text("Widget-Reihenfolge gespeichert.")
    titles = page.locator("#dashboard-widget-grid .dashboard-widget-shell h2").all_text_contents()
    assert titles.index(second_title) < titles.index(first_title)
    overview_titles = page.locator("#dashboard-widget-overview-body tr[data-widget-row-id] input[name='title']").evaluate_all(
        "(inputs) => inputs.map((input) => input.value)"
    )
    assert overview_titles.index(second_title) < overview_titles.index(first_title)

    page.reload(wait_until="domcontentloaded")
    titles_after_reload = page.locator("#dashboard-widget-grid .dashboard-widget-shell h2").all_text_contents()
    assert titles_after_reload.index(second_title) < titles_after_reload.index(first_title)
    overview_titles_after_reload = page.locator("#dashboard-widget-overview-body tr[data-widget-row-id] input[name='title']").evaluate_all(
        "(inputs) => inputs.map((input) => input.value)"
    )
    assert overview_titles_after_reload.index(second_title) < overview_titles_after_reload.index(first_title)

    for widget_id in (first_widget_id, second_widget_id):
        row = page.locator(f'tr[data-widget-row-id="{widget_id}"]')
        page.once("dialog", lambda dialog: dialog.accept())
        row.get_by_role("button", name="Löschen").click()
        page.wait_for_load_state("domcontentloaded")
        expect(page.locator(f'tr[data-widget-row-id="{widget_id}"]')).to_have_count(0)
