(() => {
  const token = document.querySelector('input[name="_csrf"]')?.value || '';
  document.querySelectorAll('.js-example-note-toggle').forEach((button) => {
    button.addEventListener('click', async () => {
      const item = button.closest('[data-note-id]');
      const response = await fetch('/example-notes/toggle', {method: 'POST', headers: {'X-CSRF-Token': token, 'Accept': 'application/json', 'Content-Type': 'application/json'}, body: JSON.stringify({note_id: item?.dataset.noteId || ''})});
      if (!response.ok) return;
      item?.classList.toggle('is-inactive');
      button.textContent = item?.classList.contains('is-inactive') ? 'Aktivieren' : 'Deaktivieren';
    });
  });
})();
