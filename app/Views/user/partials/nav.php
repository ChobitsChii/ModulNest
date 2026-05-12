<?php
declare(strict_types=1);

$userTab = (string) ($user_tab ?? 'profile');
$fantasyCardsProfileAvailable = (bool) ($fantasy_cards_profile_available ?? false);
$dataPortabilityAvailable = (bool) ($data_portability_available ?? false);
?>
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link<?= $userTab === 'profile' ? ' active' : '' ?>" href="/profil">Profil</a>
    </li>
    <li class="nav-item">
        <a class="nav-link<?= $userTab === 'security' ? ' active' : '' ?>" href="/profil/security">Sicherheit</a>
    </li>
    <li class="nav-item">
        <a class="nav-link<?= $userTab === 'settings' ? ' active' : '' ?>" href="/profil/settings">Einstellungen</a>
    </li>
    <?php if ($dataPortabilityAvailable): ?>
        <li class="nav-item">
            <a class="nav-link<?= $userTab === 'data-portability' ? ' active' : '' ?>" href="/profil/data-portability">Meine Daten</a>
        </li>
    <?php endif; ?>
    <?php if ($fantasyCardsProfileAvailable): ?>
        <li class="nav-item">
            <a class="nav-link<?= $userTab === 'fantasy-cards' ? ' active' : '' ?>" href="/profil/fantasy-cards">Sammelkarten</a>
        </li>
    <?php endif; ?>
</ul>
