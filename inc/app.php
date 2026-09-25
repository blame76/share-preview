<?php

declare(strict_types=1);

const SHARE_PREVIEW_VERSION = '0.1.0';
const SHARE_PREVIEW_MAX_HTML_BYTES = 2_000_000;
const SHARE_PREVIEW_MAX_IMAGE_BYTES = 3_000_000;
const SHARE_PREVIEW_MAX_REDIRECTS = 5;
const SHARE_PREVIEW_CONNECT_TIMEOUT = 5;
const SHARE_PREVIEW_TOTAL_TIMEOUT = 10;

function sp_send_common_headers(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'; object-src 'none'");
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()');
}

function sp_environment_checks(): array
{
    $checks = [];

    $checks[] = [
        'label' => 'PHP-Version',
        'ok' => version_compare(PHP_VERSION, '8.1.0', '>='),
        'detail' => 'Gefunden: PHP ' . PHP_VERSION . ' · benötigt: PHP 8.1 oder neuer.',
        'help' => 'Stelle im Hosting-Menü für dieses Verzeichnis PHP 8.1 oder neuer ein.',
        'required' => true,
    ];

    $checks[] = [
        'label' => 'PHP cURL',
        'ok' => extension_loaded('curl'),
        'detail' => extension_loaded('curl') ? 'cURL ist verfügbar.' : 'Die PHP-Erweiterung cURL fehlt.',
        'help' => 'Aktiviere die PHP-Erweiterung „curl“ beim Hoster oder bitte den Hosting-Support darum. Ohne cURL kann Share Preview keine Zielseiten abrufen.',
        'required' => true,
    ];

    $checks[] = [
        'label' => 'PHP DOM',
        'ok' => class_exists('DOMDocument'),
        'detail' => class_exists('DOMDocument') ? 'DOMDocument ist verfügbar.' : 'Die PHP-DOM-Erweiterung fehlt.',
        'help' => 'Aktiviere „dom“ bzw. „php-xml“. Share Preview benötigt DOMDocument zum Lesen der Meta-Tags.',
        'required' => true,
    ];

    $checks[] = [
        'label' => 'JSON',
        'ok' => extension_loaded('json'),
        'detail' => extension_loaded('json') ? 'JSON ist verfügbar.' : 'Die PHP-JSON-Erweiterung fehlt.',
        'help' => 'Aktiviere die JSON-Erweiterung. Bei aktuellen PHP-Versionen ist sie normalerweise standardmäßig vorhanden.',
        'required' => true,
    ];

    $hasDns = function_exists('dns_get_record') || function_exists('gethostbynamel');
    $checks[] = [
        'label' => 'DNS-Auflösung',
        'ok' => $hasDns,
        'detail' => $hasDns ? 'DNS-Funktionen sind verfügbar.' : 'Weder dns_get_record() noch gethostbynamel() sind verfügbar.',
        'help' => 'Der Hoster muss DNS-Auflösung aus PHP erlauben. Share Preview prüft Zieladressen vor dem Abruf gegen interne/private Netze.',
        'required' => true,
    ];

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $checks[] = [
        'label' => 'HTTPS',
        'ok' => $https,
        'detail' => $https ? 'Die Seite läuft über HTTPS.' : 'Die Seite läuft nicht über HTTPS.',
        'help' => 'Das Tool funktioniert grundsätzlich auch ohne HTTPS, aber der native Share-Button benötigt in vielen Browsern eine sichere HTTPS-Verbindung.',
        'required' => false,
    ];

    return $checks;
}

function sp_environment_ready(): bool
{
    foreach (sp_environment_checks() as $check) {
        if ($check['required'] && !$check['ok']) {
            return false;
        }
    }
    return true;
}

function sp_normalize_input_url(string $input): string
{
    $url = trim($input);
    if ($url === '') {
        throw new RuntimeException('Bitte gib eine URL ein.');
    }

    if (!preg_match('~^https?://~i', $url)) {
        $url = 'https://' . $url;
    }

    $parts = parse_url($url);
    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        throw new RuntimeException('Die URL konnte nicht gelesen werden. Beispiel: https://example.com/artikel');
    }

    $scheme = strtolower((string)$parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new RuntimeException('Erlaubt sind nur http:// und https:// URLs.');
    }

    if (isset($parts['user']) || isset($parts['pass'])) {
        throw new RuntimeException('URLs mit Benutzername oder Passwort werden nicht abgerufen.');
    }

    $host = strtolower(rtrim((string)$parts['host'], '.'));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
        throw new RuntimeException('Lokale oder interne Adressen werden aus Sicherheitsgründen nicht abgerufen.');
    }

    if (preg_match('/[^\x20-\x7E]/', $host)) {
        if (!function_exists('idn_to_ascii')) {
            throw new RuntimeException('Diese Domain enthält internationale Zeichen. Auf dem Server fehlt dafür die PHP-Erweiterung „intl“. Bitte verwende vorerst die Punycode-Domain.');
        }
        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($ascii === false) {
            throw new RuntimeException('Die internationale Domain konnte nicht normalisiert werden.');
        }
        $host = strtolower($ascii);
    }

    if (isset($parts['port']) && !in_array((int)$parts['port'], [80, 443], true)) {
        throw new RuntimeException('Aus Sicherheitsgründen werden nur die Standard-Ports 80 und 443 abgerufen.');
    }

    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    $path = $parts['path'] ?? '/';
    if ($path === '') {
        $path = '/';
    }
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';

    return $scheme . '://' . $host . $port . $path . $query;
}

function sp_is_public_ip(string $ip): bool
{
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

function sp_resolve_public_ips(string $host): array
{
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!sp_is_public_ip($host)) {
            throw new RuntimeException('Private, lokale oder reservierte IP-Adressen werden nicht abgerufen.');
        }
        return [$host];
    }

    $ips = [];

    if (function_exists('dns_get_record')) {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ip'])) {
                    $ips[] = $record['ip'];
                }
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }
    }

    if ($ips === [] && function_exists('gethostbynamel')) {
        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            $ips = array_merge($ips, $ipv4);
        }
    }

    $ips = array_values(array_unique(array_filter($ips, 'sp_is_public_ip')));
    if ($ips === []) {
        throw new RuntimeException('Die Domain konnte nicht auf eine öffentliche IP-Adresse aufgelöst werden. Interne/private Ziele werden bewusst blockiert.');
    }

    return $ips;
}

function sp_absolute_url(string $base, string $relative): string
{
    $relative = trim($relative);
    if ($relative === '') {
        return '';
    }

    if (preg_match('~^https?://~i', $relative)) {
        return $relative;
    }

    if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $relative)) {
        throw new RuntimeException('Die Weiterleitung verwendet ein nicht unterstütztes URL-Schema.');
    }

    if (str_starts_with($relative, '//')) {
        $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
        return $scheme . ':' . $relative;
    }

    $baseParts = parse_url($base);
    if ($baseParts === false || empty($baseParts['scheme']) || empty($baseParts['host'])) {
        return $relative;
    }

    $origin = $baseParts['scheme'] . '://' . $baseParts['host'];
    if (!empty($baseParts['port'])) {
        $origin .= ':' . $baseParts['port'];
    }

    if (str_starts_with($relative, '/')) {
        return $origin . sp_remove_dot_segments($relative);
    }

    $basePath = $baseParts['path'] ?? '/';

    if (str_starts_with($relative, '?')) {
        return $origin . $basePath . $relative;
    }

    if (str_starts_with($relative, '#')) {
        return $origin . $basePath . $relative;
    }

    $dir = preg_replace('~/[^/]*$~', '/', $basePath) ?: '/';

    return $origin . sp_remove_dot_segments($dir . $relative);
}

function sp_remove_dot_segments(string $path): string
{
    $query = '';
    if (($pos = strpos($path, '?')) !== false) {
        $query = substr($path, $pos);
        $path = substr($path, 0, $pos);
    }

    $segments = explode('/', $path);
    $out = [];
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($out);
            continue;
        }
        $out[] = $segment;
    }

    return '/' . implode('/', $out) . $query;
}

function sp_fetch(string $url, int $maxBytes, array $acceptedContentTypes): array
{
    if (!extension_loaded('curl')) {
        throw new RuntimeException('Die PHP-Erweiterung cURL fehlt. Bitte führe zuerst den Selfcheck aus.');
    }

    $current = sp_normalize_input_url($url);

    for ($redirect = 0; $redirect <= SHARE_PREVIEW_MAX_REDIRECTS; $redirect++) {
        $parts = parse_url($current);
        $host = strtolower((string)($parts['host'] ?? ''));
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
        $ips = sp_resolve_public_ips($host);
        usort($ips, static fn(string $a, string $b): int => (filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 0 : 1) <=> (filter_var($b, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 0 : 1));
        $ip = $ips[0];
        $resolveIp = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $ip . ']' : $ip;

        $headers = [];
        $body = '';
        $tooLarge = false;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $current,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => SHARE_PREVIEW_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => SHARE_PREVIEW_TOTAL_TIMEOUT,
            CURLOPT_USERAGENT => 'SharePreview/' . SHARE_PREVIEW_VERSION . ' (+https://blame76.com/share-preview/)',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,image/avif,image/webp,image/*;q=0.9,*/*;q=0.1',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
            ],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $resolveIp],
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $length = strlen($line);
                $line = trim($line);
                if ($line === '' || str_starts_with($line, 'HTTP/')) {
                    return $length;
                }
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $name = strtolower(trim(substr($line, 0, $pos)));
                    $value = trim(substr($line, $pos + 1));
                    $headers[$name][] = $value;
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($tooLarge) {
            throw new RuntimeException('Die Antwort ist größer als das erlaubte Limit von ' . number_format($maxBytes / 1_000_000, 1, ',', '.') . ' MB.');
        }

        if ($ok === false && $errno !== CURLE_WRITE_ERROR) {
            throw new RuntimeException('Die Zielseite konnte nicht geladen werden: ' . ($error !== '' ? $error : 'unbekannter Netzwerkfehler') . '.');
        }

        if ($status >= 300 && $status < 400) {
            $location = $headers['location'][0] ?? '';
            if ($location === '') {
                throw new RuntimeException('Die Zielseite antwortet mit einer Weiterleitung, aber ohne gültiges Location-Ziel.');
            }
            if ($redirect === SHARE_PREVIEW_MAX_REDIRECTS) {
                throw new RuntimeException('Zu viele Weiterleitungen. Maximal ' . SHARE_PREVIEW_MAX_REDIRECTS . ' sind erlaubt.');
            }
            $current = sp_normalize_input_url(sp_absolute_url($current, $location));
            continue;
        }

        if ($status < 200 || $status >= 400) {
            throw new RuntimeException('Die Zielseite antwortet mit HTTP-Status ' . $status . '.');
        }

        $normalizedType = strtolower(trim(explode(';', $contentType)[0] ?? ''));
        $accepted = false;
        foreach ($acceptedContentTypes as $prefix) {
            if ($normalizedType === $prefix || str_starts_with($normalizedType, $prefix)) {
                $accepted = true;
                break;
            }
        }

        if (!$accepted && $normalizedType === '' && in_array('text/html', $acceptedContentTypes, true)) {
            $prefix = ltrim(substr($body, 0, 512));
            $accepted = preg_match('~^<(?:!doctype\s+html|html)(?:\s|>)~i', $prefix) === 1;
        }

        if (!$accepted) {
            throw new RuntimeException('Unerwarteter Inhaltstyp: ' . ($normalizedType !== '' ? $normalizedType : 'nicht angegeben') . '.');
        }

        return [
            'url' => $effectiveUrl !== '' ? $effectiveUrl : $current,
            'status' => $status,
            'content_type' => $normalizedType,
            'body' => $body,
        ];
    }

    throw new RuntimeException('Die Zielseite konnte nicht geladen werden.');
}

function sp_first_meta(DOMXPath $xpath, string $attribute, string $value): string
{
    $query = sprintf('//meta[translate(@%s,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="%s"]/@content', $attribute, strtolower($value));
    $nodes = $xpath->query($query);
    if ($nodes && $nodes->length > 0) {
        return trim((string)$nodes->item(0)?->nodeValue);
    }
    return '';
}

function sp_first_link(DOMXPath $xpath, string $rel): string
{
    $query = sprintf('//link[contains(concat(" ", normalize-space(translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")), " "), " %s ")]/@href', strtolower($rel));
    $nodes = $xpath->query($query);
    if ($nodes && $nodes->length > 0) {
        return trim((string)$nodes->item(0)?->nodeValue);
    }
    return '';
}

function sp_parse_metadata(string $html, string $finalUrl): array
{
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
    libxml_clear_errors();

    if (!$loaded) {
        throw new RuntimeException('Das HTML der Zielseite konnte nicht verarbeitet werden.');
    }

    $xpath = new DOMXPath($dom);
    $title = '';
    $titleNodes = $dom->getElementsByTagName('title');
    if ($titleNodes->length > 0) {
        $title = trim((string)$titleNodes->item(0)?->textContent);
    }

    $description = sp_first_meta($xpath, 'name', 'description');
    $canonical = sp_first_link($xpath, 'canonical');

    $og = [
        'title' => sp_first_meta($xpath, 'property', 'og:title'),
        'description' => sp_first_meta($xpath, 'property', 'og:description'),
        'image' => sp_first_meta($xpath, 'property', 'og:image'),
        'url' => sp_first_meta($xpath, 'property', 'og:url'),
        'type' => sp_first_meta($xpath, 'property', 'og:type'),
        'site_name' => sp_first_meta($xpath, 'property', 'og:site_name'),
    ];

    $twitter = [
        'card' => sp_first_meta($xpath, 'name', 'twitter:card'),
        'title' => sp_first_meta($xpath, 'name', 'twitter:title'),
        'description' => sp_first_meta($xpath, 'name', 'twitter:description'),
        'image' => sp_first_meta($xpath, 'name', 'twitter:image'),
        'site' => sp_first_meta($xpath, 'name', 'twitter:site'),
    ];

    $canonical = $canonical !== '' ? sp_absolute_url($finalUrl, $canonical) : '';
    $og['url'] = $og['url'] !== '' ? sp_absolute_url($finalUrl, $og['url']) : '';
    $og['image'] = $og['image'] !== '' ? sp_absolute_url($finalUrl, $og['image']) : '';
    $twitter['image'] = $twitter['image'] !== '' ? sp_absolute_url($finalUrl, $twitter['image']) : '';

    return [
        'document' => [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
        ],
        'og' => $og,
        'twitter' => $twitter,
        'preview' => [
            'title' => $og['title'] !== '' ? $og['title'] : $title,
            'description' => $og['description'] !== '' ? $og['description'] : $description,
            'image' => $og['image'],
            'url' => $og['url'] !== '' ? $og['url'] : ($canonical !== '' ? $canonical : $finalUrl),
            'site_name' => $og['site_name'],
        ],
        'twitter_preview' => [
            'card' => $twitter['card'] !== '' ? $twitter['card'] : 'summary_large_image',
            'title' => $twitter['title'] !== '' ? $twitter['title'] : ($og['title'] !== '' ? $og['title'] : $title),
            'description' => $twitter['description'] !== '' ? $twitter['description'] : ($og['description'] !== '' ? $og['description'] : $description),
            'image' => $twitter['image'] !== '' ? $twitter['image'] : $og['image'],
        ],
    ];
}

function sp_fetch_preview_image(string $url): ?array
{
    if ($url === '') {
        return null;
    }

    try {
        $result = sp_fetch($url, SHARE_PREVIEW_MAX_IMAGE_BYTES, ['image/']);
        $mime = $result['content_type'];
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'], true)) {
            return null;
        }

        return [
            'data_uri' => 'data:' . $mime . ';base64,' . base64_encode($result['body']),
            'mime' => $mime,
            'bytes' => strlen($result['body']),
        ];
    } catch (Throwable) {
        return null;
    }
}

function sp_analyze_url(string $url): array
{
    $response = sp_fetch($url, SHARE_PREVIEW_MAX_HTML_BYTES, ['text/html', 'application/xhtml+xml']);
    $meta = sp_parse_metadata($response['body'], $response['url']);

    $imageSource = $meta['preview']['image'] !== '' ? $meta['preview']['image'] : $meta['twitter_preview']['image'];
    $image = $imageSource !== '' ? sp_fetch_preview_image($imageSource) : null;

    return [
        'requested_url' => sp_normalize_input_url($url),
        'final_url' => $response['url'],
        'http_status' => $response['status'],
        'metadata' => $meta,
        'image' => $image,
    ];
}

function sp_asset_version(string $relativePath): string
{
    $file = dirname(__DIR__) . '/' . ltrim($relativePath, '/');
    $mtime = @filemtime($file);
    return $mtime !== false ? (string)$mtime : SHARE_PREVIEW_VERSION;
}

function sp_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sp_self_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
        $host = 'localhost';
    }
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    return $scheme . '://' . $host . ($dir !== '' && $dir !== '.' ? $dir : '') . '/';
}

function sp_meta_status_rows(array $result): array
{
    $m = $result['metadata'];

    return [
        ['Open Graph', 'og:title', $m['og']['title'], $m['preview']['title'], $m['og']['title'] !== '' ? 'direct' : 'fallback'],
        ['Open Graph', 'og:description', $m['og']['description'], $m['preview']['description'], $m['og']['description'] !== '' ? 'direct' : 'fallback'],
        ['Open Graph', 'og:image', $m['og']['image'], $m['preview']['image'], $m['og']['image'] !== '' ? 'direct' : 'missing'],
        ['Open Graph', 'og:url', $m['og']['url'], $m['preview']['url'], $m['og']['url'] !== '' ? 'direct' : 'fallback'],
        ['Open Graph', 'og:type', $m['og']['type'], $m['og']['type'], $m['og']['type'] !== '' ? 'direct' : 'missing'],
        ['X / Twitter', 'twitter:card', $m['twitter']['card'], $m['twitter_preview']['card'], $m['twitter']['card'] !== '' ? 'direct' : 'fallback'],
        ['X / Twitter', 'twitter:title', $m['twitter']['title'], $m['twitter_preview']['title'], $m['twitter']['title'] !== '' ? 'direct' : 'fallback'],
        ['X / Twitter', 'twitter:description', $m['twitter']['description'], $m['twitter_preview']['description'], $m['twitter']['description'] !== '' ? 'direct' : 'fallback'],
        ['X / Twitter', 'twitter:image', $m['twitter']['image'], $m['twitter_preview']['image'], $m['twitter']['image'] !== '' ? 'direct' : ($m['og']['image'] !== '' ? 'fallback' : 'missing')],
        ['Document', 'title', $m['document']['title'], $m['document']['title'], $m['document']['title'] !== '' ? 'direct' : 'missing'],
        ['Document', 'meta description', $m['document']['description'], $m['document']['description'], $m['document']['description'] !== '' ? 'direct' : 'missing'],
        ['Document', 'canonical', $m['document']['canonical'], $m['document']['canonical'], $m['document']['canonical'] !== '' ? 'direct' : 'missing'],
    ];
}
