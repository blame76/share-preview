<?php

declare(strict_types=1);
require __DIR__ . '/inc/app.php';
sp_send_common_headers();

$selfUrl = sp_self_url();
$error = null;
$result = null;
$inputUrl = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inputUrl = trim((string)($_POST['url'] ?? ''));

    if (!sp_environment_ready()) {
        $error = 'Auf diesem Server fehlt eine notwendige PHP-Funktion. Öffne zuerst den Selfcheck.';
    } else {
        try {
            $result = sp_analyze_url($inputUrl);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

function preview_image_src(?array $result): string
{
    return $result['image']['data_uri'] ?? '';
}

function display_host(string $url): string
{
    return (string)(parse_url($url, PHP_URL_HOST) ?: $url);
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Share Preview</title>
    <meta name="description" content="Social-Share-Metadaten einer URL prüfen. Keine Accounts, keine Cookies, kein Tracking, keine Speicherung.">
    <meta property="og:title" content="Share Preview">
    <meta property="og:description" content="Social-Share-Metadaten einer URL prüfen. Keine Accounts, keine Cookies, kein Tracking, keine Speicherung.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= sp_h($selfUrl) ?>">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Share Preview">
    <meta name="twitter:description" content="Social-Share-Metadaten einer URL prüfen. Keine Accounts, keine Cookies, kein Tracking, keine Speicherung.">
    <link rel="canonical" href="<?= sp_h($selfUrl) ?>">
    <link rel="stylesheet" href="assets/app.css?v=<?= sp_h(sp_asset_version('assets/app.css')) ?>">
    <script src="assets/app.js?v=<?= sp_h(sp_asset_version('assets/app.js')) ?>" defer></script>
</head>
<body>
<header class="site-header">
    <a class="brand" href="./" aria-label="Share Preview Startseite">share-preview</a>
    <nav class="header-actions" aria-label="Werkzeugaktionen">
        <a href="selfcheck.php">Selfcheck</a>
        <button class="link-button" type="button" data-share-url="<?= sp_h($selfUrl) ?>">Share</button>
    </nav>
</header>

<main>
    <section class="hero" aria-labelledby="page-title">
        <p class="eyebrow">Paste URL → fetch fresh → show metadata</p>
        <h1 id="page-title">Wie sieht deine Seite beim Teilen aus?</h1>
        <p class="intro">Keine Accounts. Keine Cookies. Kein Tracking. Keine Speicherung. Jede Prüfung lädt die Zielseite frisch.</p>

        <form method="post" class="url-form" novalidate>
            <label for="url">URL</label>
            <div class="input-row">
                <input id="url" name="url" type="url" inputmode="url" autocomplete="url" placeholder="https://example.com/artikel" value="<?= sp_h($inputUrl) ?>" required>
                <button type="submit">Prüfen</button>
            </div>
        </form>

        <?php if ($error !== null): ?>
            <div class="notice notice-error" role="alert">
                <strong>Die Seite konnte nicht geprüft werden.</strong>
                <span><?= sp_h($error) ?></span>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($result !== null):
        $meta = $result['metadata'];
        $imageSrc = preview_image_src($result);
    ?>
        <section class="result-head" aria-labelledby="result-title">
            <div>
                <p class="eyebrow">HTTP <?= (int)$result['http_status'] ?></p>
                <h2 id="result-title">Ergebnis</h2>
                <p class="muted break-all"><?= sp_h($result['final_url']) ?></p>
            </div>
            <p class="fresh-note">Nicht gecacht · nicht gespeichert</p>
        </section>

        <section class="preview-grid" aria-label="Social-Media-Vorschauen">
            <article class="preview-card linkedin">
                <div class="preview-label">LinkedIn / Open Graph</div>
                <?php if ($imageSrc !== ''): ?>
                    <img src="<?= sp_h($imageSrc) ?>" alt="Vorschaubild der geprüften Seite">
                <?php else: ?>
                    <div class="image-placeholder">Kein Vorschaubild geladen</div>
                <?php endif; ?>
                <div class="preview-copy">
                    <span class="preview-host"><?= sp_h(display_host($meta['preview']['url'])) ?></span>
                    <strong><?= sp_h($meta['preview']['title'] !== '' ? $meta['preview']['title'] : 'Kein Titel gefunden') ?></strong>
                    <p><?= sp_h($meta['preview']['description'] !== '' ? $meta['preview']['description'] : 'Keine Beschreibung gefunden.') ?></p>
                </div>
            </article>

            <article class="preview-card facebook">
                <div class="preview-label">Facebook / Open Graph</div>
                <?php if ($imageSrc !== ''): ?>
                    <img src="<?= sp_h($imageSrc) ?>" alt="Vorschaubild der geprüften Seite">
                <?php else: ?>
                    <div class="image-placeholder">Kein Vorschaubild geladen</div>
                <?php endif; ?>
                <div class="preview-copy">
                    <span class="preview-host"><?= sp_h(display_host($meta['preview']['url'])) ?></span>
                    <strong><?= sp_h($meta['preview']['title'] !== '' ? $meta['preview']['title'] : 'Kein Titel gefunden') ?></strong>
                    <p><?= sp_h($meta['preview']['description'] !== '' ? $meta['preview']['description'] : 'Keine Beschreibung gefunden.') ?></p>
                </div>
            </article>

            <article class="preview-card twitter">
                <div class="preview-label">X / Twitter</div>
                <?php if ($imageSrc !== ''): ?>
                    <img src="<?= sp_h($imageSrc) ?>" alt="Vorschaubild der geprüften Seite">
                <?php else: ?>
                    <div class="image-placeholder">Kein Vorschaubild geladen</div>
                <?php endif; ?>
                <div class="preview-copy">
                    <span class="preview-host"><?= sp_h(display_host($result['final_url'])) ?></span>
                    <strong><?= sp_h($meta['twitter_preview']['title'] !== '' ? $meta['twitter_preview']['title'] : 'Kein Titel gefunden') ?></strong>
                    <p><?= sp_h($meta['twitter_preview']['description'] !== '' ? $meta['twitter_preview']['description'] : 'Keine Beschreibung gefunden.') ?></p>
                </div>
            </article>
        </section>

        <section class="metadata" aria-labelledby="metadata-title">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Quelle statt Score</p>
                    <h2 id="metadata-title">Gefundene Metadaten</h2>
                </div>
                <p class="muted">Fallbacks werden sichtbar ausgewiesen.</p>
            </div>

            <div class="meta-table-wrap">
                <table class="meta-table">
                    <thead>
                    <tr>
                        <th>Bereich</th>
                        <th>Tag</th>
                        <th>Status</th>
                        <th>Verwendeter Wert</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (sp_meta_status_rows($result) as [$group, $tag, $raw, $used, $status]): ?>
                        <tr>
                            <td><?= sp_h($group) ?></td>
                            <td><code><?= sp_h($tag) ?></code></td>
                            <td>
                                <?php if ($status === 'direct'): ?>
                                    <span class="status ok">✓ vorhanden</span>
                                <?php elseif ($status === 'fallback'): ?>
                                    <span class="status fallback">↪ Fallback</span>
                                <?php else: ?>
                                    <span class="status missing">– fehlt</span>
                                <?php endif; ?>
                            </td>
                            <td class="break-all"><?= sp_h($used !== '' ? $used : '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($meta['preview']['image'] !== ''): ?>
                <p class="image-source muted break-all">Bildquelle: <?= sp_h($meta['preview']['image']) ?></p>
            <?php endif; ?>
            <?php if ($meta['preview']['image'] !== '' && $result['image'] === null): ?>
                <div class="notice notice-neutral">Das Bild ist in den Metadaten vorhanden, konnte aber nicht sicher geladen werden. Die URL wird trotzdem angezeigt.</div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="privacy-note" aria-labelledby="privacy-title">
        <h2 id="privacy-title">Was passiert mit der URL?</h2>
        <p>Der Server ruft die eingegebene Seite einmal ab, liest die Social-Meta-Tags und verwirft den Inhalt nach dieser Anfrage wieder. Es gibt keine Datenbank, keine Sessions, keine Analytics und keine serverseitige Ergebnisablage.</p>
        <p>Vorschaubilder werden – wenn möglich – ebenfalls serverseitig geladen und direkt in die Antwort eingebettet. Der Browser muss dafür nicht selbst die Zielseite kontaktieren.</p>
    </section>
</main>

<footer>
    <span>share-preview <?= sp_h(SHARE_PREVIEW_VERSION) ?></span>
    <span>·</span>
    <a href="https://blame76.com/">blame76.com</a>
</footer>

<div class="toast" data-toast hidden></div>
</body>
</html>
