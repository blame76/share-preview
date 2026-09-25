<?php

declare(strict_types=1);

const SHARE_PREVIEW_VERSION = '0.1.0';
const SHARE_PREVIEW_MAX_URL_BYTES = 4096;
const SHARE_PREVIEW_MAX_REQUEST_BYTES = 16_384;
const SHARE_PREVIEW_MAX_HTML_BYTES = 2_000_000;
const SHARE_PREVIEW_MAX_IMAGE_BYTES = 3_000_000;
const SHARE_PREVIEW_MAX_HEADER_BYTES = 65_536;
const SHARE_PREVIEW_MAX_REDIRECTS = 5;
const SHARE_PREVIEW_CONNECT_TIMEOUT = 5;
const SHARE_PREVIEW_TOTAL_TIMEOUT = 10;
const SHARE_PREVIEW_MAX_TITLE_BYTES = 512;
const SHARE_PREVIEW_MAX_DESCRIPTION_BYTES = 4096;
const SHARE_PREVIEW_MAX_META_URL_BYTES = 4096;

function sp_send_common_headers(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'none'; font-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
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
        'detail' => version_compare(PHP_VERSION, '8.1.0', '>=') ? 'PHP 8.1 oder neuer ist verfügbar.' : 'Die installierte PHP-Version ist älter als PHP 8.1.',
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

    $hasLibxml = extension_loaded('libxml') && defined('LIBXML_VERSION');
    $checks[] = [
        'label' => 'libxml / XML',
        'ok' => $hasLibxml,
        'detail' => $hasLibxml ? 'libxml ist verfügbar.' : 'Die PHP-Erweiterung libxml/XML fehlt.',
        'help' => 'Aktiviere „libxml“ bzw. das XML-Paket deiner PHP-Installation. Es wird zum sicheren Einlesen der HTML-Metadaten benötigt.',
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

    $curlHasTls = false;
    if (extension_loaded('curl') && function_exists('curl_version')) {
        $curlInfo = curl_version();
        $protocols = array_map('strtolower', is_array($curlInfo['protocols'] ?? null) ? $curlInfo['protocols'] : []);
        $features = (int)($curlInfo['features'] ?? 0);
        $curlHasTls = in_array('https', $protocols, true)
            && defined('CURL_VERSION_SSL')
            && (($features & CURL_VERSION_SSL) !== 0);
    }
    $checks[] = [
        'label' => 'TLS-Unterstützung in cURL',
        'ok' => $curlHasTls,
        'detail' => $curlHasTls ? 'cURL kann HTTPS mit TLS abrufen.' : 'Die installierte cURL-Version meldet keine nutzbare HTTPS-/TLS-Unterstützung.',
        'help' => 'Bitte den Hoster, PHP cURL mit HTTPS-/TLS-Unterstützung bereitzustellen. Ohne TLS können HTTPS-Zielseiten nicht sicher geprüft werden.',
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

    if (strlen($url) > SHARE_PREVIEW_MAX_URL_BYTES) {
        throw new RuntimeException('Die URL ist zu lang. Erlaubt sind maximal ' . SHARE_PREVIEW_MAX_URL_BYTES . ' Bytes.');
    }

    if (preg_match('/[\x00-\x1F\x7F]/', $url) || str_contains($url, '\\')) {
        throw new RuntimeException('Die URL enthält nicht erlaubte Steuer- oder Backslash-Zeichen.');
    }

    if (str_starts_with($url, '//')) {
        $url = 'https:' . $url;
    } elseif (preg_match('~^([a-z][a-z0-9+.-]*):~i', $url, $schemeMatch)) {
        $explicitScheme = strtolower($schemeMatch[1]);
        $looksLikeHostAndPort = str_contains($explicitScheme, '.')
            && preg_match('~^\d+(?:[/?#]|$)~', substr($url, strlen($schemeMatch[0]))) === 1;
        if (!in_array($explicitScheme, ['http', 'https'], true) && !$looksLikeHostAndPort) {
            throw new RuntimeException('Erlaubt sind ausschließlich http:// und https:// URLs.');
        }
        if ($looksLikeHostAndPort) {
            $url = 'https://' . $url;
        }
    } else {
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
    if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
        $host = substr($host, 1, -1);
    }
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

    $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
    if (!$isIp) {
        if (strlen($host) > 253 || preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
            throw new RuntimeException('Der Hostname der URL ist ungültig.');
        }
        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63 || $label[0] === '-' || str_ends_with($label, '-')) {
                throw new RuntimeException('Der Hostname der URL ist ungültig.');
            }
        }
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

    $urlHost = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $host . ']' : $host;
    $normalized = $scheme . '://' . $urlHost . $port . $path . $query;
    if (strlen($normalized) > SHARE_PREVIEW_MAX_URL_BYTES) {
        throw new RuntimeException('Die normalisierte URL ist zu lang. Erlaubt sind maximal ' . SHARE_PREVIEW_MAX_URL_BYTES . ' Bytes.');
    }

    return $normalized;
}

function sp_is_public_ip(string $ip): bool
{
    $packed = @inet_pton($ip);
    if ($packed === false) {
        return false;
    }

    if (strlen($packed) === 4) {
        $blockedCidrs = [
            '0.0.0.0/8',
            '10.0.0.0/8',
            '100.64.0.0/10',
            '127.0.0.0/8',
            '169.254.0.0/16',
            '172.16.0.0/12',
            '192.0.0.0/24',
            '192.0.2.0/24',
            '192.31.196.0/24',
            '192.52.193.0/24',
            '192.88.99.0/24',
            '192.168.0.0/16',
            '192.175.48.0/24',
            '198.18.0.0/15',
            '198.51.100.0/24',
            '203.0.113.0/24',
            '224.0.0.0/4',
            '240.0.0.0/4',
        ];
    } else {
        // Only current IPv6 global unicast is eligible; special-purpose ranges stay blocked.
        if (!sp_ip_matches_cidr($packed, '2000::/3')) {
            return false;
        }
        $blockedCidrs = [
            '2001::/23',
            '2001:db8::/32',
            '2002::/16',
            '2620:4f:8000::/48',
            '3fff::/20',
        ];
    }

    foreach ($blockedCidrs as $cidr) {
        if (sp_ip_matches_cidr($packed, $cidr)) {
            return false;
        }
    }

    return true;
}

function sp_ip_matches_cidr(string $packedIp, string $cidr): bool
{
    [$network, $prefixText] = explode('/', $cidr, 2);
    $packedNetwork = @inet_pton($network);
    if ($packedNetwork === false || strlen($packedNetwork) !== strlen($packedIp)) {
        return false;
    }

    $prefix = (int)$prefixText;
    $maxBits = strlen($packedIp) * 8;
    if ($prefix < 0 || $prefix > $maxBits) {
        return false;
    }

    $wholeBytes = intdiv($prefix, 8);
    if ($wholeBytes > 0 && substr($packedIp, 0, $wholeBytes) !== substr($packedNetwork, 0, $wholeBytes)) {
        return false;
    }

    $remainingBits = $prefix % 8;
    if ($remainingBits === 0) {
        return true;
    }

    $mask = (0xff << (8 - $remainingBits)) & 0xff;
    return (ord($packedIp[$wholeBytes]) & $mask) === (ord($packedNetwork[$wholeBytes]) & $mask);
}

function sp_validate_resolved_ips(array $ips): array
{
    $validated = [];
    foreach ($ips as $ip) {
        $ip = trim((string)$ip);
        if ($ip === '' || !sp_is_public_ip($ip)) {
            throw new RuntimeException('Die Domain verweist mindestens teilweise auf eine private, lokale oder reservierte IP-Adresse und wird deshalb nicht abgerufen.');
        }
        $validated[bin2hex((string)inet_pton($ip))] = $ip;
    }

    if ($validated === []) {
        throw new RuntimeException('Die Domain konnte nicht auf eine öffentliche IP-Adresse aufgelöst werden.');
    }

    return array_values($validated);
}

function sp_ip_in_list(string $ip, array $allowedIps): bool
{
    $packed = @inet_pton($ip);
    if ($packed === false) {
        return false;
    }
    foreach ($allowedIps as $allowedIp) {
        if (@inet_pton((string)$allowedIp) === $packed) {
            return true;
        }
    }
    return false;
}

function sp_resolve_public_ips(string $host): array
{
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return sp_validate_resolved_ips([$host]);
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

    return sp_validate_resolved_ips($ips);
}

function sp_prepare_fetch_target(string $url): array
{
    $normalized = sp_normalize_input_url($url);
    $parts = parse_url($normalized);
    if ($parts === false) {
        throw new RuntimeException('Die URL konnte nicht für den Abruf vorbereitet werden.');
    }

    $host = strtolower((string)($parts['host'] ?? ''));
    if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
        $host = substr($host, 1, -1);
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);

    return [
        'url' => $normalized,
        'host' => $host,
        'port' => $port,
        'ips' => sp_resolve_public_ips($host),
        'literal_ip' => filter_var($host, FILTER_VALIDATE_IP) !== false,
    ];
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
    $deadline = hrtime(true) + (SHARE_PREVIEW_TOTAL_TIMEOUT * 1_000_000_000);

    for ($redirect = 0; $redirect <= SHARE_PREVIEW_MAX_REDIRECTS; $redirect++) {
        $target = sp_prepare_fetch_target($current);
        $current = $target['url'];
        $host = $target['host'];
        $port = $target['port'];
        $ips = $target['ips'];
        usort($ips, static fn(string $a, string $b): int => (filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 0 : 1) <=> (filter_var($b, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 0 : 1));
        $ip = $ips[0];
        $resolveIp = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $ip . ']' : $ip;

        $remainingMs = (int)floor(($deadline - hrtime(true)) / 1_000_000);
        if ($remainingMs <= 0) {
            throw new RuntimeException('Der Abruf hat das gesamte Zeitlimit von ' . SHARE_PREVIEW_TOTAL_TIMEOUT . ' Sekunden überschritten.');
        }

        $headers = [];
        $body = '';
        $tooLarge = false;
        $headersTooLarge = false;
        $headerBytes = 0;

        $ch = curl_init();
        $curlOptions = [
            CURLOPT_URL => $current,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_AUTOREFERER => false,
            CURLOPT_CONNECTTIMEOUT_MS => min(SHARE_PREVIEW_CONNECT_TIMEOUT * 1000, $remainingMs),
            CURLOPT_TIMEOUT_MS => $remainingMs,
            CURLOPT_USERAGENT => 'share-preview/' . SHARE_PREVIEW_VERSION,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,image/avif,image/webp,image/*;q=0.9,*/*;q=0.1',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
                'Authorization:',
                'Cookie:',
            ],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_UNRESTRICTED_AUTH => false,
            CURLOPT_HTTPAUTH => CURLAUTH_NONE,
            CURLOPT_NETRC => CURL_NETRC_IGNORED,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_ENCODING => '',
            CURLOPT_DNS_CACHE_TIMEOUT => 0,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers, &$headerBytes, &$headersTooLarge, &$tooLarge, $maxBytes): int {
                $length = strlen($line);
                $headerBytes += $length;
                if ($headerBytes > SHARE_PREVIEW_MAX_HEADER_BYTES) {
                    $headersTooLarge = true;
                    return 0;
                }
                $line = trim($line);
                if (str_starts_with($line, 'HTTP/')) {
                    $headers = [];
                    return $length;
                }
                if ($line === '') {
                    return $length;
                }
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $name = strtolower(trim(substr($line, 0, $pos)));
                    $value = trim(substr($line, $pos + 1));
                    $headers[$name][] = $value;
                    if ($name === 'content-length' && ctype_digit($value) && (int)$value > $maxBytes) {
                        $tooLarge = true;
                        return 0;
                    }
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
        ];
        if (!$target['literal_ip']) {
            $curlOptions[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . $resolveIp];
        }
        curl_setopt_array($ch, $curlOptions);

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $primaryIp = (string)curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        curl_close($ch);

        if ($headersTooLarge) {
            throw new RuntimeException('Die Antwort enthält mehr Headerdaten als das erlaubte Limit.');
        }

        if ($tooLarge) {
            throw new RuntimeException('Die Antwort ist größer als das erlaubte Limit von ' . number_format($maxBytes / 1_000_000, 1, ',', '.') . ' MB.');
        }

        if ($ok === false) {
            throw new RuntimeException('Die Zielseite konnte wegen eines Netzwerk- oder TLS-Fehlers nicht geladen werden (cURL-Code ' . $errno . ').');
        }

        if (!sp_is_public_ip($primaryIp) || !sp_ip_in_list($primaryIp, $ips)) {
            throw new RuntimeException('Die tatsächlich verwendete Ziel-IP stimmt nicht mit der geprüften öffentlichen DNS-Auflösung überein.');
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
        foreach ($acceptedContentTypes as $allowedType) {
            if ($normalizedType === $allowedType || (str_ends_with($allowedType, '/') && str_starts_with($normalizedType, $allowedType))) {
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

function sp_limit_meta_value(string $value, int $maxBytes): string
{
    $value = trim($value);
    if (strlen($value) <= $maxBytes) {
        return $value;
    }
    return rtrim(substr($value, 0, $maxBytes));
}

function sp_safe_metadata_url(string $baseUrl, string $candidate): string
{
    $candidate = sp_limit_meta_value($candidate, SHARE_PREVIEW_MAX_META_URL_BYTES + 1);
    if ($candidate === '' || strlen($candidate) > SHARE_PREVIEW_MAX_META_URL_BYTES) {
        return '';
    }

    try {
        return sp_normalize_input_url(sp_absolute_url($baseUrl, $candidate));
    } catch (Throwable) {
        return '';
    }
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
    $previousLibxmlErrorMode = libxml_use_internal_errors(true);
    try {
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET | LIBXML_COMPACT);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlErrorMode);
    }

    if (!$loaded) {
        throw new RuntimeException('Das HTML der Zielseite konnte nicht verarbeitet werden.');
    }

    $xpath = new DOMXPath($dom);
    $title = '';
    $titleNodes = $dom->getElementsByTagName('title');
    if ($titleNodes->length > 0) {
        $title = sp_limit_meta_value((string)$titleNodes->item(0)?->textContent, SHARE_PREVIEW_MAX_TITLE_BYTES);
    }

    $description = sp_limit_meta_value(sp_first_meta($xpath, 'name', 'description'), SHARE_PREVIEW_MAX_DESCRIPTION_BYTES);
    $canonical = sp_first_link($xpath, 'canonical');

    $og = [
        'title' => sp_limit_meta_value(sp_first_meta($xpath, 'property', 'og:title'), SHARE_PREVIEW_MAX_TITLE_BYTES),
        'description' => sp_limit_meta_value(sp_first_meta($xpath, 'property', 'og:description'), SHARE_PREVIEW_MAX_DESCRIPTION_BYTES),
        'image' => sp_first_meta($xpath, 'property', 'og:image'),
        'url' => sp_first_meta($xpath, 'property', 'og:url'),
        'type' => sp_limit_meta_value(sp_first_meta($xpath, 'property', 'og:type'), 128),
        'site_name' => sp_limit_meta_value(sp_first_meta($xpath, 'property', 'og:site_name'), 256),
    ];

    $twitter = [
        'card' => sp_limit_meta_value(sp_first_meta($xpath, 'name', 'twitter:card'), 128),
        'title' => sp_limit_meta_value(sp_first_meta($xpath, 'name', 'twitter:title'), SHARE_PREVIEW_MAX_TITLE_BYTES),
        'description' => sp_limit_meta_value(sp_first_meta($xpath, 'name', 'twitter:description'), SHARE_PREVIEW_MAX_DESCRIPTION_BYTES),
        'image' => sp_first_meta($xpath, 'name', 'twitter:image'),
        'site' => sp_limit_meta_value(sp_first_meta($xpath, 'name', 'twitter:site'), 256),
    ];

    $canonical = sp_safe_metadata_url($finalUrl, $canonical);
    $og['url'] = sp_safe_metadata_url($finalUrl, $og['url']);
    $og['image'] = sp_safe_metadata_url($finalUrl, $og['image']);
    $twitter['image'] = sp_safe_metadata_url($finalUrl, $twitter['image']);

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
        if (!sp_image_matches_mime($result['body'], $mime)) {
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

function sp_image_matches_mime(string $body, string $mime): bool
{
    return match ($mime) {
        'image/jpeg' => str_starts_with($body, "\xff\xd8\xff"),
        'image/png' => str_starts_with($body, "\x89PNG\r\n\x1a\n"),
        'image/gif' => str_starts_with($body, 'GIF87a') || str_starts_with($body, 'GIF89a'),
        'image/webp' => strlen($body) >= 12
            && str_starts_with($body, 'RIFF')
            && substr($body, 8, 4) === 'WEBP',
        'image/avif' => sp_has_avif_signature($body),
        default => false,
    };
}

function sp_has_avif_signature(string $body): bool
{
    if (strlen($body) < 16 || substr($body, 4, 4) !== 'ftyp') {
        return false;
    }

    $sizeData = unpack('Nsize', substr($body, 0, 4));
    $boxSize = (int)($sizeData['size'] ?? 0);
    if ($boxSize < 16 || $boxSize > strlen($body)) {
        return false;
    }

    if (in_array(substr($body, 8, 4), ['avif', 'avis'], true)) {
        return true;
    }

    for ($offset = 16; $offset + 4 <= $boxSize; $offset += 4) {
        if (in_array(substr($body, $offset, 4), ['avif', 'avis'], true)) {
            return true;
        }
    }

    return false;
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
