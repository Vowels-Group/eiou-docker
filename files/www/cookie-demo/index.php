<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Cross-Subdomain Cookie Demo
 *
 * Deploy on any *.eiou.org subdomain to demonstrate that wallet cookies
 * set on .eiou.org by wallet.eiou.org are readable here.
 *
 * No authentication required — this page only reads public wallet metadata.
 */

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use Eiou\Gui\Controllers\CrossDomainDemoController;

$controller = new CrossDomainDemoController();
echo $controller->render();
