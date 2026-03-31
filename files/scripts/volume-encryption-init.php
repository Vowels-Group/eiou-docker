<?php
# Copyright 2025-2026 Vowels Group, LLC
#
# Volume Encryption Initialization Script
#
# Called by startup.sh BEFORE MariaDB starts to:
#   1. Read the volume key from /dev/shm/.volume_key (placed there by startup.sh)
#   2. Initialize volume encryption (decrypt/migrate master key to /dev/shm)
#
# The MariaDB TDE key file setup is handled separately by mariadb-tde-init.php.
#
# Exit codes:
#   0 = success
#   1 = error (message printed to stderr)

require_once '/app/eiou/vendor/autoload.php';

use Eiou\Security\VolumeEncryption;

try {
    // Read passphrase from /dev/shm (startup.sh writes it there)
    $passphrase = VolumeEncryption::getPassphrase();

    // Initialize volume encryption (decrypt/migrate/copy master key)
    $status = VolumeEncryption::init($passphrase);
    echo $status . "\n";

    // Clear passphrase from this process's memory
    if ($passphrase !== null) {
        \Eiou\Security\KeyEncryption::secureClear($passphrase);
    }

} catch (Exception $e) {
    fwrite(STDERR, "Volume encryption error: " . $e->getMessage() . "\n");
    exit(1);
}
