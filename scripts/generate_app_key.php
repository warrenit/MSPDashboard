<?php

declare(strict_types=1);

$target = $argv[1] ?? (__DIR__ . '/../../mspdashboard_app.key');
$key = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));

if (file_put_contents($target, $key . PHP_EOL) === false) {
    fwrite(STDERR, "Failed to write key file to {$target}\n");
    exit(1);
}

chmod($target, 0600);
echo "App key written to {$target}\n";
