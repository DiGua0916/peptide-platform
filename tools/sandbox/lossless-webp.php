<?php
/** Offline sandbox-only sidecars. Input: JSON array of /wp-content/uploads/*.png paths on stdin. */
if (PHP_SAPI !== 'cli' || !extension_loaded('gd') || !defined('IMG_WEBP_LOSSLESS')) {
    throw new RuntimeException('CLI GD lossless WebP support required');
}
$paths = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($paths) || count($paths) > 500) { throw new RuntimeException('Invalid image list'); }
$root = '/srv/vialcove-sandbox/shared/uploads';
if (realpath($root) !== $root) { throw new RuntimeException('Unexpected sandbox upload root'); }
$results = [];
foreach (array_unique($paths) as $relative) {
    if (!is_string($relative) || !str_starts_with($relative, '/wp-content/uploads/') || !str_ends_with($relative, '.png')) {
        throw new RuntimeException('Expected local PNG upload path');
    }
    $source = realpath($root . '/' . substr($relative, strlen('/wp-content/uploads/')));
    if (!$source || !str_starts_with($source, $root . '/')) { throw new RuntimeException('Outside sandbox uploads'); }
    $hash = hash_file('sha256', $source);
    $image = imagecreatefrompng($source);
    imagepalettetotruecolor($image); imagesavealpha($image, true);
    $output = $source . '.webp';
    $temporary = tempnam(dirname($source), '.vc-webp-');
    try {
        if (!imagewebp($image, $temporary, IMG_WEBP_LOSSLESS)) { throw new RuntimeException('Encode failed'); }
        $decoded = imagecreatefromwebp($temporary);
        $same = imagesx($image) === imagesx($decoded) && imagesy($image) === imagesy($decoded);
        for ($y = 0; $same && $y < imagesy($image); $y++) {
            for ($x = 0; $x < imagesx($image); $x++) {
                if (imagecolorat($image, $x, $y) !== imagecolorat($decoded, $x, $y)) { $same = false; break; }
            }
        }
        if (hash_file('sha256', $source) !== $hash) { throw new RuntimeException('Source changed during conversion'); }
        $publish = $same && filesize($temporary) < filesize($source);
        if ($publish) {
            chmod($temporary, 0644); chown($temporary, 'vcsandbox'); chgrp($temporary, 'vcsandbox');
            if (!rename($temporary, $output)) { throw new RuntimeException('Atomic publish failed'); }
        } elseif ((file_exists($output) || is_link($output)) && !unlink($output)) {
            // A replaced PNG must not retain a sidecar of the previous image.
            throw new RuntimeException('Could not remove stale sidecar');
        }
        $results[] = ['path' => $relative, 'original_sha256' => $hash, 'png_bytes' => filesize($source), 'webp_bytes' => $publish ? filesize($output) : null, 'same_pixels' => $same];
        imagedestroy($decoded);
    } finally {
        imagedestroy($image);
        if (is_file($temporary)) { unlink($temporary); }
    }
}
echo json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
