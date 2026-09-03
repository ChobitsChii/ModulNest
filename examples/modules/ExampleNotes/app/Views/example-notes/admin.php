<section class="example-notes"><h1>Example Notes – Administration</h1>
<?php if ($message !== ''): ?><p class="notice success"><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
<form method="post" action="/admin/example-notes/settings">
<?= \Modulon\Core\View::csrfField($csrf_token) ?>
<label>Hinweistext <input name="hint" maxlength="240" value="<?= htmlspecialchars($hint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></label>
<button type="submit">Speichern</button>
</form></section>
