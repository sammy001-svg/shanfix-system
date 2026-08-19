<?php
/**
 * Builds a valid PNG without GD, for testing the product-image pipeline.
 *
 *   php make_png.php <path> [width] [height]
 *
 * Written as a file rather than inlined into the shell script: the PNG
 * signature contains bytes (\x89, \r, \x1a) that get mangled passing
 * through nested shell and heredoc quoting.
 */

function make_png(int $w, int $h, string $path): bool
{
    // Raw scanlines: filter byte 0 (none) then RGB triples.
    $raw = '';
    for ($y = 0; $y < $h; $y++) {
        $raw .= chr(0);
        // Two bands, so the result is visibly an image rather than flat.
        $rgb = ($y < $h * 0.55) ? [23, 146, 74] : [17, 17, 17];
        $raw .= str_repeat(chr($rgb[0]) . chr($rgb[1]) . chr($rgb[2]), $w);
    }

    $chunk = static fn(string $type, string $data): string =>
        pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));

    $png  = "\x89PNG\r\n\x1a\n";
    $png .= $chunk('IHDR', pack('NNccccc', $w, $h, 8, 2, 0, 0, 0)); // 8-bit truecolour
    $png .= $chunk('IDAT', gzcompress($raw, 6));
    $png .= $chunk('IEND', '');

    $dir = dirname($path);
    if (!is_dir($dir)) {
        fwrite(STDERR, "No such directory: {$dir}\n");
        return false;
    }

    return file_put_contents($path, $png) !== false;
}

$path = $argv[1] ?? null;
$w    = (int) ($argv[2] ?? 1220);
$h    = (int) ($argv[3] ?? 280);

if ($path === null) {
    fwrite(STDERR, "usage: php make_png.php <path> [width] [height]\n");
    exit(1);
}

if (!make_png($w, $h, $path)) {
    fwrite(STDERR, "failed to write {$path}\n");
    exit(1);
}

$info = getimagesize($path);
printf("  %s — %dx%d %s, %s KB\n",
    basename($path), $info[0], $info[1], $info['mime'],
    number_format(filesize($path) / 1024, 0));
