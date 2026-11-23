<?php

declare(strict_types=1);

use SourceFunctions\VerifiedHumanApproval\Application;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\Evidence\EvidenceExporter;

require dirname(__DIR__) . '/bootstrap.php';

function reply(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

function body(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
        reply(['error' => 'A JSON POST is required.'], 405);
    }
    $raw = file_get_contents('php://input', false, null, 0, 131073);
    if (!is_string($raw) || strlen($raw) > 131072) {
        reply(['error' => 'Request body is too large.'], 413);
    }
    $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        reply(['error' => 'A JSON object is required.'], 400);
    }
    return $decoded;
}

function escape(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; connect-src 'self'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");

try {
    $configPath = getenv('VHA_PROVIDER_CONFIG') ?: '';
    if ($configPath === '') {
        throw new RuntimeException('Provider configuration is required.');
    }
    $app = Application::fromFile($configPath);
    $origin = $app->config['origin'];
    $parts = parse_url($origin);
    $host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    if (strtolower($_SERVER['HTTP_HOST'] ?? '') !== $host) {
        reply(['error' => 'Use the configured approval origin.'], 421);
    }
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($path === '/invoke') {
        if (!hash_equals('Bearer ' . $app->config['site_token'], $_SERVER['HTTP_AUTHORIZATION'] ?? '')) {
            reply(['error' => 'Site authentication is required.'], 401);
        }
        reply($app->invoke(body()));
    }
    if ($path === '/app.js' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        header('Content-Type: application/javascript');
        readfile(__DIR__ . '/app.js');
        exit;
    }
    // This isolated example has one separately authenticated human reviewer.
    // Deployments should replace this screen authentication with their own SSO.
    if (!hash_equals($app->config['reviewer_id'], $_SERVER['PHP_AUTH_USER'] ?? '')
        || !password_verify($_SERVER['PHP_AUTH_PW'] ?? '', $app->config['browser_password_hash'])) {
        header('WWW-Authenticate: Basic realm="Publication approval", charset="UTF-8"');
        reply(['error' => 'Sign in as the configured human reviewer.'], 401);
    }
    session_name('source_approval_review');
    session_start(['cookie_httponly' => true, 'cookie_secure' => ($parts['scheme'] === 'https'), 'cookie_samesite' => 'Strict', 'use_strict_mode' => true]);
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')
            || ($_SERVER['HTTP_ORIGIN'] ?? $origin) !== $origin) {
            reply(['error' => 'The review security token is missing or stale.'], 403);
        }
        $input = body();
        if ($path === '/enroll/options') {
            $options = $app->registrationOptions();
            $_SESSION['registration'] = $options;
            $_SESSION['registration_started'] = time();
            reply(['options' => $options]);
        }
        if ($path === '/enroll/complete') {
            $options = $_SESSION['registration'] ?? null;
            unset($_SESSION['registration']);
            if (!is_array($options) || time() - ($_SESSION['registration_started'] ?? 0) > 300
                || !is_string($input['code'] ?? null) || !is_array($input['response'] ?? null)) {
                reply(['error' => 'Start a fresh enrollment ceremony.'], 400);
            }
            $app->enroll($input['code'], $options, $input['response']);
            reply(['status' => 'enrolled']);
        }
        if (preg_match('#^/review/(apr_[a-f0-9]{32})/(begin|complete|cancel)$#D', (string) $path, $matches)) {
            [, $id, $operation] = $matches;
            if ($operation === 'begin') {
                reply($app->service->begin($app->reviewer, $id)->toArray());
            }
            if ($operation === 'cancel') {
                reply(['status' => $app->service->cancel($app->reviewer, $id)->status]);
            }
            if (!is_string($input['ceremonyId'] ?? null) || !is_array($input['assertion'] ?? null)) {
                reply(['error' => 'A passkey assertion is required.'], 400);
            }
            $ceremony = $app->store->getCeremony($input['ceremonyId']);
            if ($ceremony === null || $ceremony->requestId !== $id) {
                reply(['error' => 'The ceremony belongs to a different proposal.'], 400);
            }
            $state = $app->service->complete($app->reviewer, $input['ceremonyId'], $input['assertion']);
            reply(['status' => $state->status, 'effect' => $state->result['effect'] ?? null, 'drupal_published' => false]);
        }
        reply(['error' => 'Not found.'], 404);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        reply(['error' => 'Method not allowed.'], 405);
    }
    $content = '';
    if ($path === '/enroll') {
        $content = '<h1>Enroll your publication passkey</h1><p>Enter the one-time enrollment code delivered separately by the operator. A password alone cannot enroll a new passkey.</p>'
            . '<label>Enrollment code <input id="code" type="password" autocomplete="off"></label><button id="enroll">Enroll passkey</button>';
    } elseif (preg_match('#^/review/(apr_[a-f0-9]{32})(/receipt)?$#D', (string) $path, $matches)) {
        $state = $app->service->get($app->reviewer, $matches[1]);
        if (($matches[2] ?? '') === '/receipt') {
            reply((new EvidenceExporter())->export($state));
        }
        $publication = $state->request->prepared()->effect;
        $content = '<h1>Review this publication candidate</h1><p><strong>' . escape($state->request->statement()) . '</strong></p>'
            . '<p>This review covers the field snapshot below. It does not include the rendered page or referenced entities. Approval records permission to publish; it does not publish Drupal content.</p>'
            . '<dl><dt>Status</dt><dd>' . escape($state->status) . '</dd><dt>Candidate digest</dt><dd>' . escape($publication['snapshot_hash']) . '</dd></dl>'
            . '<pre>' . escape(json_encode(json_decode($publication['snapshot_json'], false, 64, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
        if ($state->status === 'pending') {
            $content .= '<button id="approve" data-request="' . escape($state->request->id) . '">Approve with your passkey</button> <button id="cancel" data-request="' . escape($state->request->id) . '">Cancel request</button>';
        } elseif ($state->status === 'completed') {
            $content .= '<p>Return to Drupal and retry the same publication before the authorization expires. Drupal will check the current candidate and consume the authorization when the save commits.</p><p><a href="' . escape($path . '/receipt') . '">Download authorization receipt</a></p>';
        }
    } else {
        reply(['error' => 'Not found.'], 404);
    }
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><meta name="csrf" content="' . escape($_SESSION['csrf']) . '"><title>Publication approval</title>'
        . '<style>body{font:17px/1.5 system-ui;max-width:860px;margin:40px auto;padding:0 20px;color:#182331}pre{font-size:14px;background:#f2f4f7;padding:20px;white-space:pre-wrap;overflow-wrap:anywhere}button,input{font:inherit;padding:10px;margin:10px 0}dd{margin-left:0;overflow-wrap:anywhere}dt{font-weight:700}#result{white-space:pre-wrap}</style>'
        . $content . '<p id="result" role="status"></p><script src="/app.js" defer></script></html>';
} catch (ApprovalException $error) {
    reply(['error' => $error->reason, 'message' => $error->getMessage()], 409);
} catch (JsonException|InvalidArgumentException $error) {
    reply(['error' => 'invalid_request', 'message' => 'The approval request could not be read.'], 400);
} catch (Throwable $error) {
    error_log('Publication approval provider failed: ' . $error::class);
    reply(['error' => 'approval_unavailable'], 503);
}
