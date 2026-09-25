<?php

declare(strict_types=1);
require __DIR__ . '/inc/app.php';
sp_send_common_headers();
$checks = sp_environment_checks();
$ready = sp_environment_ready();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Selfcheck · Share Preview</title>
    <meta name="robots" content="noindex,nofollow">
    <link rel="stylesheet" href="assets/app.css?v=<?= sp_h(sp_asset_version('assets/app.css')) ?>">
</head>
<body>
<header class="site-header">
    <a class="brand" href="./">share-preview</a>
    <nav class="header-actions"><a href="./">Zurück</a></nav>
</header>

<main class="selfcheck-page">
    <section class="hero compact">
        <p class="eyebrow">Installation prüfen</p>
        <h1>Selfcheck</h1>
        <p class="intro">Share Preview speichert nichts und benötigt keine Datenbank. Für den Betrieb müssen nur wenige PHP-Funktionen vorhanden sein.</p>
        <div class="notice <?= $ready ? 'notice-success' : 'notice-error' ?>">
            <strong><?= $ready ? 'Bereit.' : 'Noch nicht bereit.' ?></strong>
            <span><?= $ready ? 'Die notwendigen Voraussetzungen sind vorhanden.' : 'Mindestens eine notwendige Voraussetzung fehlt. Die Hinweise unten sagen dir, was zu tun ist.' ?></span>
        </div>
    </section>

    <section class="check-list" aria-label="Systemprüfung">
        <?php foreach ($checks as $check): ?>
            <article class="check-card">
                <div class="check-state <?= $check['ok'] ? 'ok' : ($check['required'] ? 'error' : 'warn') ?>" aria-hidden="true">
                    <?= $check['ok'] ? '✓' : ($check['required'] ? '!' : 'i') ?>
                </div>
                <div>
                    <div class="check-title-row">
                        <h2><?= sp_h($check['label']) ?></h2>
                        <span class="requirement"><?= $check['required'] ? 'notwendig' : 'Hinweis' ?></span>
                    </div>
                    <p><?= sp_h($check['detail']) ?></p>
                    <?php if (!$check['ok']): ?><p class="help-text"><?= sp_h($check['help']) ?></p><?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="privacy-note">
        <h2>Kein Schreibtest notwendig</h2>
        <p>Share Preview schreibt keine Dateien und benötigt keine beschreibbaren Verzeichnisse. Wenn der Selfcheck grün ist, kannst du direkt zur Startseite zurückgehen und eine öffentliche URL prüfen.</p>
        <p><strong>Hinweis:</strong> Ein grüner Selfcheck prüft nicht, ob dein Hoster ausgehende HTTP/HTTPS-Verbindungen generell blockiert. Falls ein Abruf später scheitert, zeigt die Anwendung eine verständliche Fehlerkategorie an.</p>
    </section>
</main>
<footer>
    <span>share-preview <?= sp_h(SHARE_PREVIEW_VERSION) ?></span>
    <span>·</span>
    <a href="datenschutz.html">Datenschutz</a>
    <span>·</span>
    <a href="https://github.com/blame76/share-preview">GitHub</a>
</footer>
</body>
</html>
