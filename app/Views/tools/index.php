<?php
declare(strict_types=1);

$toolGroups = is_array($tool_groups ?? null) ? $tool_groups : [];
$e = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
$slug = static fn (string $value): string => strtolower(strtr($value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', ' ' => '-']));
?>

<?php require __DIR__ . '/../partials/module-nav.php'; ?>

<section class="app-card p-4 mb-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
        <div>
            <p class="text-uppercase text-muted small fw-semibold mb-1">Modulon Tools</p>
            <h1 class="h3 mb-2">Hilfs- und Entwickler-Tools</h1>
            <p class="text-muted mb-0">Die User-Tools laufen lokal im Browser und erzeugen keine Serverlast.</p>
        </div>
        <div class="align-self-lg-end tools-search-wrap">
            <label class="form-label small text-muted" for="tools-search">Tools suchen</label>
            <input class="form-control" id="tools-search" type="search" placeholder="z. B. JSON, Hash, QR">
        </div>
    </div>
</section>

<div class="tools-grid">
    <?php foreach ($toolGroups as $category => $tools): ?>
        <?php $categoryId = 'tools-' . $slug((string) $category); ?>
        <section class="tools-category" id="<?= $e($categoryId) ?>">
            <h2 class="h5 mb-3"><?= $e($category) ?></h2>
            <div class="row g-3">
                <?php foreach ($tools as $tool): ?>
                    <?php
                    $key = (string) ($tool['key'] ?? '');
                    $label = (string) ($tool['label'] ?? '');
                    $description = (string) ($tool['description'] ?? '');
                    ?>
                    <div class="col-12 col-xl-6 tools-card-wrap" data-tool-card data-tool-search="<?= $e(strtolower($label . ' ' . $description . ' ' . $category)) ?>">
                        <article class="app-card tools-card h-100 p-3" id="<?= $e($key) ?>">
                            <div class="d-flex justify-content-between gap-3 mb-3">
                                <div>
                                    <h3 class="h5 mb-1"><?= $e($label) ?></h3>
                                    <p class="text-muted small mb-0"><?= $e($description) ?></p>
                                </div>
                                <span class="badge text-bg-secondary align-self-start">Browser</span>
                            </div>

                            <?php if ($key === 'text-counter'): ?>
                                <textarea class="form-control tools-textarea" id="tools-text-input" rows="6" placeholder="Text eingeben..."></textarea>
                                <div class="tools-metrics mt-3">
                                    <span>Zeichen <strong id="tools-count-chars">0</strong></span>
                                    <span>Ohne Leerzeichen <strong id="tools-count-no-space">0</strong></span>
                                    <span>Wörter <strong id="tools-count-words">0</strong></span>
                                    <span>Zeilen <strong id="tools-count-lines">0</strong></span>
                                    <span>Absätze <strong id="tools-count-paragraphs">0</strong></span>
                                </div>
                            <?php elseif ($key === 'text-cleaner'): ?>
                                <textarea class="form-control tools-textarea" id="tools-clean-input" rows="6" placeholder="Text bereinigen..."></textarea>
                                <div class="d-flex flex-wrap gap-2 mt-3">
                                    <button class="btn btn-outline-secondary btn-sm" type="button" data-clean-action="spaces">Doppelte Leerzeichen entfernen</button>
                                    <button class="btn btn-outline-secondary btn-sm" type="button" data-clean-action="blank-lines">Leerzeilen entfernen</button>
                                    <button class="btn btn-outline-secondary btn-sm" type="button" data-clean-action="trim">Trimmen</button>
                                </div>
                            <?php elseif ($key === 'base64'): ?>
                                <textarea class="form-control tools-textarea" id="tools-base64-input" rows="5" placeholder="Text oder Base64..."></textarea>
                                <div class="d-flex flex-wrap gap-2 mt-3">
                                    <button class="btn btn-outline-secondary btn-sm" type="button" id="tools-base64-encode">Encode</button>
                                    <button class="btn btn-outline-secondary btn-sm" type="button" id="tools-base64-decode">Decode</button>
                                </div>
                                <pre class="tools-output mt-3" id="tools-base64-output"></pre>
                            <?php elseif ($key === 'url-codec'): ?>
                                <textarea class="form-control tools-textarea" id="tools-url-input" rows="5" placeholder="Text oder URL-Komponente..."></textarea>
                                <div class="d-flex flex-wrap gap-2 mt-3">
                                    <button class="btn btn-outline-secondary btn-sm" type="button" id="tools-url-encode">Encode</button>
                                    <button class="btn btn-outline-secondary btn-sm" type="button" id="tools-url-decode">Decode</button>
                                </div>
                                <pre class="tools-output mt-3" id="tools-url-output"></pre>
                            <?php elseif ($key === 'json-formatter'): ?>
                                <textarea class="form-control tools-textarea" id="tools-json-input" rows="7" placeholder='{"status":"ok"}'></textarea>
                                <div class="d-flex flex-wrap gap-2 mt-3">
                                    <button class="btn btn-outline-secondary btn-sm" type="button" id="tools-json-format">Formatieren</button>
                                    <button class="btn btn-outline-secondary btn-sm" type="button" id="tools-json-minify">Minify</button>
                                </div>
                                <div class="small mt-2" id="tools-json-status"></div>
                            <?php elseif ($key === 'uuid-generator'): ?>
                                <button class="btn btn-primary btn-sm" type="button" id="tools-uuid-generate">UUID erzeugen</button>
                                <pre class="tools-output mt-3" id="tools-uuid-output"></pre>
                            <?php elseif ($key === 'password-generator'): ?>
                                <div class="row g-2 align-items-end">
                                    <div class="col-6 col-md-4">
                                        <label class="form-label small" for="tools-password-length">Länge</label>
                                        <input class="form-control" id="tools-password-length" type="number" min="8" max="128" value="24">
                                    </div>
                                    <div class="col-auto">
                                        <button class="btn btn-primary btn-sm" type="button" id="tools-password-generate">Passwort erzeugen</button>
                                    </div>
                                </div>
                                <pre class="tools-output mt-3" id="tools-password-output"></pre>
                            <?php elseif ($key === 'timestamp-converter'): ?>
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label small" for="tools-timestamp">Unix Timestamp</label>
                                        <input class="form-control" id="tools-timestamp" type="number" placeholder="1714399200">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small" for="tools-date">Datum/Zeit lokal</label>
                                        <input class="form-control" id="tools-date" type="datetime-local">
                                    </div>
                                </div>
                                <div class="d-flex flex-wrap gap-2 mt-3">
                                    <button class="btn btn-outline-secondary btn-sm" type="button" id="tools-timestamp-to-date">Timestamp → Datum</button>
                                    <button class="btn btn-outline-secondary btn-sm" type="button" id="tools-date-to-timestamp">Datum → Timestamp</button>
                                    <button class="btn btn-outline-secondary btn-sm" type="button" id="tools-timestamp-now">Jetzt</button>
                                </div>
                            <?php elseif ($key === 'hash-generator'): ?>
                                <textarea class="form-control tools-textarea" id="tools-hash-input" rows="5" placeholder="Text hashen..."></textarea>
                                <button class="btn btn-primary btn-sm mt-3" type="button" id="tools-hash-generate">Hashes erzeugen</button>
                                <pre class="tools-output mt-3" id="tools-hash-output"></pre>
                            <?php elseif ($key === 'qr-code'): ?>
                                <textarea class="form-control tools-textarea" id="tools-qr-input" rows="4" placeholder="Text oder URL..."></textarea>
                                <button class="btn btn-primary btn-sm mt-3" type="button" id="tools-qr-generate">QR-Code erzeugen</button>
                                <div class="tools-qr-output mt-3"><canvas id="tools-qr-canvas" width="220" height="220"></canvas></div>
                                <div class="small text-muted mt-2" id="tools-qr-status"></div>
                            <?php elseif ($key === 'markdown-preview'): ?>
                                <textarea class="form-control tools-textarea" id="tools-markdown-input" rows="7" placeholder="# Überschrift"></textarea>
                                <div class="tools-preview mt-3" id="tools-markdown-preview"></div>
                            <?php elseif ($key === 'regex-tester'): ?>
                                <div class="row g-2">
                                    <div class="col-md-8">
                                        <label class="form-label small" for="tools-regex-pattern">Pattern</label>
                                        <input class="form-control" id="tools-regex-pattern" placeholder="\\bModulon\\b">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small" for="tools-regex-flags">Flags</label>
                                        <input class="form-control" id="tools-regex-flags" value="gi">
                                    </div>
                                </div>
                                <textarea class="form-control tools-textarea mt-2" id="tools-regex-text" rows="5" placeholder="Testtext..."></textarea>
                                <pre class="tools-output mt-3" id="tools-regex-output"></pre>
                            <?php endif; ?>
                        </article>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<script src="/assets/vendor/qrcode-generator/qrcode.min.js"></script>
<script src="/assets/js/tools.js"></script>
