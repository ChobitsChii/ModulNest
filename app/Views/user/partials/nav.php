<?php
declare(strict_types=1);

$userTab = (string) ($user_tab ?? 'profile');
$fantasyCardsProfileAvailable = (bool) ($fantasy_cards_profile_available ?? false);
$dataPortabilityAvailable = (bool) ($data_portability_available ?? false);
?>
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link<?= $userTab === 'profile' ? ' active' : '' ?> d-inline-flex align-items-center gap-1" href="/profil">
            <i class="bi bi-person"></i> Profil
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link<?= $userTab === 'security' ? ' active' : '' ?> d-inline-flex align-items-center gap-1" href="/profil/security">
            <i class="bi bi-shield-lock"></i> Sicherheit
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link<?= $userTab === 'settings' ? ' active' : '' ?> d-inline-flex align-items-center gap-1" href="/profil/settings">
            <i class="bi bi-sliders"></i> Einstellungen
        </a>
    </li>
    <?php if ($dataPortabilityAvailable): ?>
        <li class="nav-item">
            <a class="nav-link<?= $userTab === 'data-portability' ? ' active' : '' ?> d-inline-flex align-items-center gap-1" href="/profil/data-portability">
                <i class="bi bi-cloud-arrow-down"></i> Meine Daten
            </a>
        </li>
    <?php endif; ?>
    <?php if ($fantasyCardsProfileAvailable): ?>
        <li class="nav-item">
            <a class="nav-link<?= $userTab === 'fantasy-cards' ? ' active' : '' ?> d-inline-flex align-items-center gap-1" href="/profil/fantasy-cards">
                <i class="bi bi-suit-spade"></i> Sammelkarten
            </a>
        </li>
    <?php endif; ?>
</ul>
