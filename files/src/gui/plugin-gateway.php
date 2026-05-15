<?php
# Copyright 2025-2026 Vowels Group, LLC
#
# HTTP entry point for the plugin sandbox service gateway.
# nginx routes /__plugin_gateway here, served by the wallet's www-data
# FPM pool. The dispatched method runs with the wallet's privileges
# (master key in scope, full DB access) AFTER PluginGatewayController
# validates the per-plugin bearer token and the manifest allow-list.
#
# Plugin code never reaches here — only its OUTBOUND HTTP request does.
# See docs/PLUGINS.md (Sandboxing).

declare(strict_types=1);

require_once '/app/eiou/Functions.php';

use Eiou\Core\Application;
use Eiou\Services\Plugins\PluginGatewayController;
use Eiou\Services\Plugins\PluginGatewayTokenService;
use Eiou\Utils\Logger;

header('Content-Type: application/json');

try {
    $app = Application::getInstance();
    $services = $app->services;
    $loader = $app->pluginLoader;

    if ($services === null || $loader === null) {
        // Wallet hasn't booted yet — happens during early-boot probes.
        // Don't crash; return a structured 503 so the plugin can retry.
        http_response_code(503);
        echo json_encode([
            'ok' => false,
            'error' => ['code' => 'wallet_not_ready', 'message' => 'wallet not initialized'],
        ]);
        exit;
    }

    $controller = new PluginGatewayController(
        new PluginGatewayTokenService(),
        $loader,
        $services,
        Logger::getInstance()
    );

    // Collapse all incoming headers to lower-case keys — PHP exposes
    // them as $_SERVER['HTTP_AUTHORIZATION'] etc., we want a flat
    // ['authorization' => '...', ...] dict for the controller.
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $headerName = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$headerName] = (string) $value;
        }
    }

    $rawBody = (string) @file_get_contents('php://input');
    $response = $controller->handle($rawBody, $headers);

    http_response_code($response['status']);
    echo json_encode($response['body']);
} catch (\Throwable $e) {
    Logger::getInstance()->logException($e, ['context' => 'plugin_gateway_entry']);
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => ['code' => 'server_error', 'message' => 'gateway entry crashed'],
    ]);
}
