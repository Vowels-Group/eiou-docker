<?php
# Copyright 2025-2026 Vowels Group, LLC
#
# Grant the app database user the privileges required by the plugin
# isolation feature. Idempotent — safe to run on every container boot.
#
# What it does:
#   1. Reads the app username from /etc/eiou/config/dbconfig.json (decrypts
#      if encrypted, which requires the master key to be loadable).
#   2. Connects to MariaDB as root via the unix socket (no password needed
#      from inside the container running as the mysql/root UID).
#   3. Issues `GRANT ALL ON eiou.* ... WITH GRANT OPTION` and
#      `GRANT CREATE USER ON *.*` against that exact app user, then
#      FLUSH PRIVILEGES.
#
# Why a separate root-credentialed step:
#   PluginDbUserService runs as the app user when it CREATEs plugin_<id>
#   users and GRANTs them per-table privileges. The app user can't grant
#   itself those upstream privileges, so on upgrades from versions that
#   pre-date the plugin isolation feature (where freshInstall() never
#   issued these grants) the plugin enable path fails with MySQL 1227.
#   Fresh installs get the same grants directly in DatabaseSetup.php; this
#   helper only matters for upgrade paths and for self-healing after
#   manual REVOKEs.
#
# Exit codes:
#   0 = success or no-op (no dbconfig yet, or master key not loadable)
#   1 = error (message printed to stderr)

require_once '/app/eiou/vendor/autoload.php';

use Eiou\Core\DatabaseContext;

const DBCONFIG_PATH = '/etc/eiou/config/dbconfig.json';
const MYSQL_SOCKET  = '/var/run/mysqld/mysqld.sock';

if (!file_exists(DBCONFIG_PATH)) {
    // Fresh container, no wallet yet — DatabaseSetup.php will issue the
    // grants when freshInstall() runs on first Application boot.
    echo "no dbconfig — skipping (fresh install path will handle grants)\n";
    exit(0);
}

$ctx = DatabaseContext::getInstance();
if (!$ctx->isInitialized()) {
    fwrite(STDERR, "dbconfig.json present but DatabaseContext failed to initialize\n");
    exit(1);
}

$dbUser = $ctx->getDbUser();
if ($dbUser === null || $dbUser === '') {
    // Encrypted dbUser couldn't be decrypted (master key not loadable yet).
    // This is expected when the volume passphrase hasn't been provided in
    // a headless boot. Plugin enable will fail until the wallet is unlocked
    // and a subsequent boot re-runs this script.
    echo "app user not readable yet (master key unavailable) — skipping\n";
    exit(0);
}

$dbHost = $ctx->getDbHost() ?? 'localhost';

// Defence-in-depth: the username flows directly into DDL and we don't have
// a way to bind it as a parameter (CREATE/GRANT don't accept placeholders).
// freshInstall() generates `eiou_user_<hex>` so anything outside that shape
// indicates corruption or tampering — refuse rather than interpolate.
if (!preg_match('/^eiou_user_[a-f0-9]{16}$/', $dbUser)) {
    fwrite(STDERR, "Refusing to grant: app user '{$dbUser}' does not match expected pattern\n");
    exit(1);
}
if (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $dbHost)) {
    fwrite(STDERR, "Refusing to grant: db host '{$dbHost}' contains unexpected characters\n");
    exit(1);
}

try {
    $root = new PDO("mysql:unix_socket=" . MYSQL_SOCKET, 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "Cannot connect to MariaDB as root via socket: " . $e->getMessage() . "\n");
    exit(1);
}

// Verify the user exists before granting — granting to a non-existent user
// would CREATE one with no password, which would be a security regression.
$stmt = $root->prepare(
    "SELECT 1 FROM mysql.user WHERE User = :u AND Host = :h LIMIT 1"
);
$stmt->execute([':u' => $dbUser, ':h' => $dbHost]);
if ($stmt->fetchColumn() === false) {
    fwrite(STDERR, "App user '{$dbUser}'@'{$dbHost}' not found in mysql.user — refusing to grant\n");
    exit(1);
}

try {
    $root->exec("GRANT ALL ON `eiou`.* TO '{$dbUser}'@'{$dbHost}' WITH GRANT OPTION");
    $root->exec("GRANT CREATE USER ON *.* TO '{$dbUser}'@'{$dbHost}'");
    $root->exec("FLUSH PRIVILEGES");
} catch (PDOException $e) {
    fwrite(STDERR, "Failed to grant plugin-isolation privileges: " . $e->getMessage() . "\n");
    exit(1);
}

echo "plugin-isolation privileges ensured for '{$dbUser}'@'{$dbHost}'\n";
exit(0);
