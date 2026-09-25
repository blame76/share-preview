<?php

declare(strict_types=1);

require dirname(__DIR__) . '/inc/app.php';

$failures = 0;
$checks = 0;

function test_pass(string $label): void
{
    global $checks;
    $checks++;
    fwrite(STDOUT, "PASS  {$label}\n");
}

function test_fail(string $label, string $detail): void
{
    global $checks, $failures;
    $checks++;
    $failures++;
    fwrite(STDERR, "FAIL  {$label}: {$detail}\n");
}

function expect_rejected(string $label, callable $callback): void
{
    try {
        $callback();
        test_fail($label, 'wurde unerwartet akzeptiert');
    } catch (RuntimeException) {
        test_pass($label);
    } catch (Throwable $error) {
        test_fail($label, 'falscher Fehlertyp: ' . $error::class);
    }
}

function expect_same(string $label, mixed $expected, mixed $actual): void
{
    if ($expected === $actual) {
        test_pass($label);
        return;
    }
    test_fail($label, 'erwartet ' . var_export($expected, true) . ', erhalten ' . var_export($actual, true));
}

$blockedTargets = [
    'localhost' => 'http://localhost/',
    'IPv4 loopback' => 'http://127.0.0.1/',
    'IPv6 loopback' => 'http://[::1]/',
    'privates Netz 192.168/16' => 'http://192.168.1.1/',
    'privates Netz 10/8' => 'http://10.0.0.1/',
];

foreach ($blockedTargets as $label => $url) {
    expect_rejected($label, static fn() => sp_prepare_fetch_target($url));
}

$blockedSyntax = [
    'file-Scheme' => 'file:///etc/passwd',
    'ftp-Scheme' => 'ftp://example.org/',
    'gopher-Scheme' => 'gopher://example.org/',
    'data-Scheme' => 'data:text/plain,test',
    'javascript-Scheme' => 'javascript:alert(1)',
    'ldap-Scheme' => 'ldap://example.org/',
    'smb-Scheme' => 'smb://example.org/share',
    'unbekanntes Scheme' => 'custom://example.org/',
    'URL mit Zugangsdaten' => 'https://user:password@example.org/',
    'nicht erlaubter Port' => 'https://example.org:1234/',
    'überlange URL' => 'https://example.org/' . str_repeat('a', SHARE_PREVIEW_MAX_URL_BYTES),
];

foreach ($blockedSyntax as $label => $url) {
    expect_rejected($label, static fn() => sp_normalize_input_url($url));
}

expect_rejected('Redirect auf private IP', static function (): void {
    $redirect = sp_absolute_url('https://example.org/start', 'http://127.0.0.1/internal');
    sp_prepare_fetch_target($redirect);
});

expect_rejected('gemischte öffentliche/private DNS-Antworten', static fn() => sp_validate_resolved_ips(['93.184.216.34', '127.0.0.1']));
expect_rejected('IPv4-mapped IPv6', static fn() => sp_validate_resolved_ips(['::ffff:8.8.8.8']));

$reservedIps = [
    'CGNAT' => '100.64.0.1',
    'IETF Protocol Assignments' => '192.0.0.1',
    'Benchmarking' => '198.18.0.1',
    'IPv4 Multicast' => '224.0.0.1',
    'IPv6 Dokumentation' => '2001:db8::1',
    'IPv6 6to4' => '2002:7f00:1::',
    'IPv6 Dokumentation 3fff' => '3fff::1',
];
foreach ($reservedIps as $label => $ip) {
    expect_rejected($label, static fn() => sp_validate_resolved_ips([$ip]));
}

expect_same('normale HTTPS-URL', 'https://example.org/path?x=1', sp_normalize_input_url('https://example.org/path?x=1'));
expect_same('normale HTTP-URL', 'http://example.org/', sp_normalize_input_url('http://example.org/'));
expect_same('URL ohne Scheme erhält HTTPS', 'https://example.org/', sp_normalize_input_url('example.org'));
expect_same('öffentliche IPv4 ist erlaubt', true, sp_is_public_ip('93.184.216.34'));
expect_same('öffentliche IPv6 ist erlaubt', true, sp_is_public_ip('2606:4700:4700::1111'));
expect_same('fremde Bild-Schemes werden verworfen', '', sp_safe_metadata_url('https://example.org/', 'data:image/png;base64,AA=='));
expect_same('SVG mit gefälschtem PNG-Typ wird verworfen', false, sp_image_matches_mime('<svg><script>alert(1)</script></svg>', 'image/png'));
expect_same('PNG-Signatur wird erkannt', true, sp_image_matches_mime("\x89PNG\r\n\x1a\nrest", 'image/png'));
expect_same('Metadaten werden begrenzt', 32, strlen(sp_limit_meta_value(str_repeat('x', 64), 32)));

fwrite(STDOUT, "\n{$checks} Prüfungen, {$failures} Fehler.\n");
exit($failures === 0 ? 0 : 1);
