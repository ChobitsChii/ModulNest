<?php
declare(strict_types=1);

$cardsProfile = $fantasyCardsProfile;
$settings = is_array($cardsProfile['settings'] ?? null) ? $cardsProfile['settings'] : [];
$ownedCards = is_array($cardsProfile['owned_cards'] ?? null) ? $cardsProfile['owned_cards'] : [];
$favoriteCard = is_array($cardsProfile['favorite_card'] ?? null) ? $cardsProfile['favorite_card'] : null;
$showcaseCards = is_array($cardsProfile['showcase_cards'] ?? null) ? $cardsProfile['showcase_cards'] : [];
$manualShowcase = is_array($cardsProfile['manual_showcase'] ?? null) ? $cardsProfile['manual_showcase'] : [];
$rarestCards = is_array($cardsProfile['rarest_cards'] ?? null) ? $cardsProfile['rarest_cards'] : [];
$latestPulls = is_array($cardsProfile['latest_pulls'] ?? null) ? $cardsProfile['latest_pulls'] : [];
$completedSets = is_array($cardsProfile['completed_sets'] ?? null) ? $cardsProfile['completed_sets'] : [];
$progress = is_array($cardsProfile['progress'] ?? null) ? $cardsProfile['progress'] : [];
$selectedFavorite = (int) ($settings['favorite_card_id'] ?? 0);
$selectedShowcase = array_map(static fn (array $card): int => (int) ($card['id'] ?? 0), $manualShowcase);
$e = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
$renderMiniCard = static function (?array $card, string $empty = 'Noch keine Karte') use ($e): void {
    if ($card === null || $card === []) {
        echo '<div class="fantasycards-profile-empty">' . $e($empty) . '</div>';
        return;
    }
    $fullImage = trim((string) ($card['image_path'] ?? ''));
    $thumbImage = trim((string) ($card['thumbnail_path'] ?? ''));
    $image = $thumbImage !== '' ? $thumbImage : $fullImage;
    $rarity = Modulon\Modules\FantasyCards\FantasyCardsRarity::get((string) ($card['rarity'] ?? 'common'));
    $cardTitle = (string) ($card['name'] ?? '');
    $caption = trim((string) ($card['set_name'] ?? '') . ' · x' . (int) ($card['owned_quantity'] ?? 0));
    ?>
    <article class="fantasycards-profile-card <?= $e($rarity['class'] ?? '') ?>">
        <div class="fantasycards-profile-card-image">
            <?php if ($image !== ''): ?>
                <?php if ($fullImage !== ''): ?>
                    <button type="button" class="fantasycards-image-button" data-fantasycards-lightbox data-full-image="<?= $e($fullImage) ?>" data-title="<?= $e($cardTitle) ?>" data-caption="<?= $e($caption) ?>">
                        <img src="<?= $e($image) ?>" alt="<?= $e($cardTitle) ?>" loading="lazy">
                    </button>
                <?php else: ?>
                    <img src="<?= $e($image) ?>" alt="<?= $e($cardTitle) ?>" loading="lazy">
                <?php endif; ?>
            <?php else: ?>
                <div class="fantasycards-image-placeholder">Karte</div>
            <?php endif; ?>
        </div>
        <div class="p-3">
            <div class="d-flex justify-content-between gap-2 align-items-start mb-1">
                <h3 class="h6 mb-0"><?= $e($card['name'] ?? '') ?></h3>
                <span class="badge <?= $e($rarity['badge'] ?? 'text-bg-secondary') ?>"><?= $e($rarity['label'] ?? '') ?></span>
            </div>
            <div class="small text-body-secondary"><?= $e($card['set_name'] ?? '') ?> · x<?= (int) ($card['owned_quantity'] ?? 0) ?></div>
        </div>
    </article>
    <?php
};
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h1 class="h4 mb-0">Sammelkarten-Profil</h1>
    <a class="btn btn-outline-secondary btn-sm" href="/fantasy-cards/collection">Sammlung öffnen</a>
</div>

<?php if ($profileCardsMessage !== '' || $profileCardsError !== ''): ?>
    <div class="modulon-feedback-stack mb-4">
        <?php if ($profileCardsMessage !== ''): ?>
            <div class="alert alert-success mb-0" role="alert"><?= $e($profileCardsMessage) ?></div>
        <?php endif; ?>
        <?php if ($profileCardsError !== ''): ?>
            <div class="alert alert-danger mb-0" role="alert"><?= $e($profileCardsError) ?></div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<section class="app-card fantasycards-profile-panel p-4 mb-4">
    <div class="row g-4 align-items-stretch">
        <div class="col-12 col-lg-4">
            <h2 class="h6 text-uppercase text-body-secondary mb-3">Lieblingskarte</h2>
            <?php $renderMiniCard($favoriteCard, 'Noch keine Lieblingskarte gewählt.'); ?>
        </div>
        <div class="col-12 col-lg-8">
            <h2 class="h6 text-uppercase text-body-secondary mb-3">Karten-Showcase</h2>
            <div class="fantasycards-profile-showcase-grid">
                <?php if ($showcaseCards === []): ?>
                    <div class="fantasycards-profile-empty">Noch keine Showcase-Karten vorhanden.</div>
                <?php else: ?>
                    <?php foreach (array_slice($showcaseCards, 0, 5) as $card): ?>
                        <?php $renderMiniCard($card); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<script src="/assets/js/fantasycards-lightbox.js" defer></script>

<div class="row g-4 mb-4">
    <div class="col-12 col-xl-4">
        <section class="app-card p-4 h-100">
            <h2 class="h6 text-uppercase text-body-secondary mb-3">Seltene Karten</h2>
            <div class="vstack gap-3">
                <?php foreach ($rarestCards as $card): ?>
                    <?php $renderMiniCard($card); ?>
                <?php endforeach; ?>
                <?php if ($rarestCards === []): ?><p class="text-body-secondary mb-0">Noch keine Karten gefunden.</p><?php endif; ?>
            </div>
        </section>
    </div>
    <div class="col-12 col-xl-4">
        <section class="app-card p-4 h-100">
            <h2 class="h6 text-uppercase text-body-secondary mb-3">Letzte Pulls</h2>
            <div class="vstack gap-3">
                <?php foreach ($latestPulls as $card): ?>
                    <?php $renderMiniCard($card); ?>
                <?php endforeach; ?>
                <?php if ($latestPulls === []): ?><p class="text-body-secondary mb-0">Noch keine Pulls vorhanden.</p><?php endif; ?>
            </div>
        </section>
    </div>
    <div class="col-12 col-xl-4">
        <section class="app-card p-4 h-100">
            <h2 class="h6 text-uppercase text-body-secondary mb-3">Komplettierte Sets</h2>
            <?php if ($completedSets === []): ?>
                <p class="text-body-secondary mb-0">Noch kein Set komplettiert.</p>
            <?php else: ?>
                <div class="vstack gap-2">
                    <?php foreach ($completedSets as $set): ?>
                        <div class="d-flex justify-content-between gap-3">
                            <span><?= $e($set['name'] ?? '') ?></span>
                            <span class="badge text-bg-success"><?= (int) ($set['owned_cards'] ?? 0) ?>/<?= (int) ($set['total_cards'] ?? 0) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<section class="app-card p-4">
    <h2 class="h5 mb-3">Showcase und Sichtbarkeit</h2>
    <form method="post" action="/profil/fantasy-cards" class="row g-4">
        <div class="col-12 col-lg-6">
            <label class="form-label" for="favorite_card_id">Lieblingskarte</label>
            <select id="favorite_card_id" name="favorite_card_id" class="form-select">
                <option value="0">Keine Lieblingskarte</option>
                <?php foreach ($ownedCards as $card): ?>
                    <?php $id = (int) ($card['id'] ?? 0); ?>
                    <option value="<?= $id ?>" <?= $id === $selectedFavorite ? 'selected' : '' ?>>
                        <?= $e($card['name'] ?? '') ?> · <?= $e($card['set_name'] ?? '') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-lg-6">
            <label class="form-label" for="showcase_mode">Showcase-Modus</label>
            <select id="showcase_mode" name="showcase_mode" class="form-select">
                <?php foreach (['manual' => 'Manuell', 'rarest' => 'Top 5 seltenste Karten', 'latest' => 'Neueste Funde', 'completed' => 'Komplettierte Sammlung'] as $value => $label): ?>
                    <option value="<?= $e($value) ?>" <?= (string) ($settings['showcase_mode'] ?? 'manual') === $value ? 'selected' : '' ?>><?= $e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12">
            <label class="form-label">Manuelle Showcase-Karten</label>
            <div class="row g-2">
                <?php for ($slot = 0; $slot < 5; $slot++): ?>
                    <div class="col-12 col-md">
                        <select name="showcase_card_ids[]" class="form-select">
                            <option value="0">Slot <?= $slot + 1 ?></option>
                            <?php foreach ($ownedCards as $card): ?>
                                <?php $id = (int) ($card['id'] ?? 0); ?>
                                <option value="<?= $id ?>" <?= ($selectedShowcase[$slot] ?? 0) === $id ? 'selected' : '' ?>>
                                    <?= $e($card['name'] ?? '') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
        <div class="col-12">
            <div class="row g-3">
                <?php foreach ([
                    'is_favorites_public' => 'Lieblingskarte öffentlich anzeigen',
                    'is_collection_public' => 'Sammlung öffentlich anzeigen',
                    'is_progress_public' => 'Set-Fortschritte öffentlich anzeigen',
                ] as $name => $label): ?>
                    <div class="col-12 col-md-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="<?= $e($name) ?>" name="<?= $e($name) ?>" value="1" <?= (int) ($settings[$name] ?? 0) === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="<?= $e($name) ?>"><?= $e($label) ?></label>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="form-text">Öffentliche Profilseiten sind vorbereitet; diese Schalter bilden die Privacy-Grundlage dafür.</div>
        </div>
        <div class="col-12">
            <button type="submit" class="btn btn-primary btn-sm">Sammelkarten-Profil speichern</button>
        </div>
    </form>
</section>
