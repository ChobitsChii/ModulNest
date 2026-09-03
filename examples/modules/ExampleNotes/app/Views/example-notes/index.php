<link rel="stylesheet" href="/assets/css/example-notes.css">
<section class="example-notes" data-example-notes>
    <h1><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
    <p><?= htmlspecialchars($hint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    <?php if ($message !== ''): ?><p class="notice success"><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="notice error"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
    <form method="post" action="/example-notes/create">
        <?= \Modulon\Core\View::csrfField($csrf_token) ?>
        <label>Neue Notiz <input name="title" maxlength="160" required></label>
        <button type="submit">Erstellen</button>
    </form>
    <ul>
        <?php foreach ($notes as $note): ?>
            <li data-note-id="<?= (int) $note['id'] ?>" class="<?= !empty($note['is_active']) ? '' : 'is-inactive' ?>">
                <?= htmlspecialchars((string) $note['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                <button type="button" class="js-example-note-toggle"><?= !empty($note['is_active']) ? 'Deaktivieren' : 'Aktivieren' ?></button>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<script src="/assets/js/example-notes.js" defer></script>
