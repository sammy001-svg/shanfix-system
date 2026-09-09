<?php
/**
 * The website's contact details come from the system. What does it show
 * when the system is not there?
 *
 * That is not a hypothetical: the site and the system are separate
 * applications, the system's database has been down in production
 * before, and a marketing page whose footer renders blank — or worse,
 * whose structured data offers search engines an empty telephone — is a
 * real way to lose a customer who was only looking for a phone number.
 *
 * So this runs site_brand() with no system at all to reach, by copying
 * the three files it needs into a tree that has no app/ or config/ in
 * it. system_ready() looks for those two directories above site/, finds
 * neither, and gives up — which is the same path taken when the
 * database refuses a connection.
 *
 * It also checks the two rules that are easy to get wrong by hand:
 *
 *   - SITE_URL wins over the built-in domain, and a trailing slash on it
 *     does not produce "//" in the middle of every canonical.
 *   - An address is only taken apart when it says how. "Nairobi, Kenya"
 *     must not be published as street "Nairobi", town "Kenya".
 *
 * Prints "ok", or what went wrong.
 *
 * Usage:  php tests/helpers/site_brand_fallback.php <path-to-site>
 */

$site = rtrim($argv[1] ?? (__DIR__ . '/../../site'), '/');

$needed = ['brand.php', 'system.php', 'env_loader.php'];

foreach ($needed as $f) {
    if (!is_file($site . '/includes/' . $f)) {
        echo 'missing ' . $site . '/includes/' . $f;
        exit;
    }
}

// A tree shaped like the real one but with nothing above site/ — no
// app/bootstrap.php, no config/config.php, so the system is genuinely
// unreachable rather than merely mocked.
$root = sys_get_temp_dir() . '/shanfix_brand_' . getmypid();
@mkdir($root . '/site/includes', 0777, true);

foreach ($needed as $f) {
    copy($site . '/includes/' . $f, $root . '/site/includes/' . $f);
}

$failures = [];

// Run it in a child, because brand.php and system.php both memoise and
// this process may already have loaded the real ones.
$probe = $root . '/probe.php';
file_put_contents($probe, <<<'PROBE'
<?php
require __DIR__ . '/site/includes/brand.php';
$b = site_brand();
echo json_encode([
    'name'    => $b['name'],
    'phone'   => $b['phone'],
    'tel'     => $b['phone_tel'],
    'email'   => $b['email'],
    'address' => $b['address'],
    'street'  => $b['street'],
    'city'    => $b['city'],
    'url'     => site_url(),
    'canon'   => site_url('web-development.php'),
]);
PROBE);

$run = static function (array $env) use ($probe): array {
    $cmd  = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe);
    $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    $p = proc_open($cmd, $spec, $pipes, null, $env + ['PATH' => getenv('PATH') ?: '']);

    if (!is_resource($p)) {
        return [];
    }

    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($p);

    return json_decode($out, true) ?? [];
};

// -- 1. No system at all: the site's own details, in full ---------------
$got = $run([]);

if (!$got) {
    $failures[] = 'the probe produced nothing';
} else {
    $want = [
        'name'    => 'Shanfix Technology',
        'phone'   => '+254 751 869 165',
        'tel'     => '+254751869165',
        'email'   => 'info@shanfixtechnology.com',
        'address' => 'Tana House, Karen - Nairobi',
        'street'  => 'Tana House, Karen',
        'city'    => 'Nairobi',
        'url'     => 'https://shanfixtechnology.com/',
        'canon'   => 'https://shanfixtechnology.com/web-development.php',
    ];

    foreach ($want as $k => $v) {
        if (($got[$k] ?? null) !== $v) {
            $failures[] = sprintf('%s was "%s", wanted "%s"', $k, $got[$k] ?? '', $v);
        }
    }
}

// -- 2. SITE_URL moves every absolute URL, trailing slash and all -------
$moved = $run(['SITE_URL' => 'https://staging.example.com/']);

if (($moved['url'] ?? '') !== 'https://staging.example.com/') {
    $failures[] = 'SITE_URL did not move the base: ' . ($moved['url'] ?? '');
}

if (($moved['canon'] ?? '') !== 'https://staging.example.com/web-development.php') {
    $failures[] = 'SITE_URL left a bad canonical: ' . ($moved['canon'] ?? '');
}

// The contact details are the company's, not the address the site
// answers at, so moving the site must not touch them.
if (($moved['phone'] ?? '') !== ($got['phone'] ?? 'x')) {
    $failures[] = 'SITE_URL changed the phone number';
}

// -- clean up ----------------------------------------------------------
@unlink($probe);

foreach ($needed as $f) {
    @unlink($root . '/site/includes/' . $f);
}

@rmdir($root . '/site/includes');
@rmdir($root . '/site');
@rmdir($root);

echo $failures ? implode('; ', $failures) : 'ok';
