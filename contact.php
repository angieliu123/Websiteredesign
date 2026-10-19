<?php
/**
 * Evaluation form handler (wishlist 2.1).
 * Receives JSON {email, company, agent, website, source}, emails info@versarai.ai,
 * and forwards the lead to your CRM if a webhook URL is configured.
 *
 * CRM: set the environment variable VERSARAI_CRM_WEBHOOK to a webhook URL
 * (HubSpot, Zapier, Make, Pipedrive, ...). It receives the same JSON plus "received_at".
 */
declare(strict_types=1);

const TO_ADDRESS = 'info@versarai.ai';
const FROM_ADDRESS = 'no-reply@versarai.ai';   // must be an address on a domain your server may send from
const MAX_PER_HOUR = 5;                         // per IP

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function respond(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 10000) {
    respond(413, ['ok' => false, 'error' => 'too_large']);
}
$in = json_decode($raw, true);
if (!is_array($in)) {
    respond(400, ['ok' => false, 'error' => 'bad_json']);
}

// Honeypot: bots fill the hidden field. Pretend success and drop it.
if (!empty($in['website'])) {
    respond(200, ['ok' => true]);
}

function clean(string $s, int $max): string {
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    $s = trim($s);
    return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}

$email   = clean((string)($in['email'] ?? ''), 254);
$company = clean((string)($in['company'] ?? ''), 200);
$agent   = clean((string)($in['agent'] ?? ''), 2000);
$source  = clean((string)($in['source'] ?? ''), 100);

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email)) {
    respond(422, ['ok' => false, 'error' => 'invalid_email']);
}
if ($company === '' || $agent === '') {
    respond(422, ['ok' => false, 'error' => 'missing_fields']);
}

// Simple per-IP rate limit (file based, no database needed).
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rlFile = sys_get_temp_dir() . '/versarai_rl_' . md5($ip);
$now = time();
$hits = [];
if (is_file($rlFile)) {
    $hits = array_filter(array_map('intval', explode(',', (string)file_get_contents($rlFile))), fn($t) => $t > $now - 3600);
}
if (count($hits) >= MAX_PER_HOUR) {
    respond(429, ['ok' => false, 'error' => 'rate_limited']);
}
$hits[] = $now;
@file_put_contents($rlFile, implode(',', $hits), LOCK_EX);

$lead = [
    'email' => $email,
    'company' => $company,
    'agent' => $agent,
    'source' => $source,
    'received_at' => gmdate('c'),
];

// 1. Email to the team
$subject = 'Evaluation request: ' . preg_replace('/[\r\n]+/', ' ', $company);
$body = "Work email: $email\nCompany: $company\nSource page: $source\n\nAgent they are trying to get approved:\n$agent\n";
$headers = [
    'From: VersarAI site <' . FROM_ADDRESS . '>',
    'Reply-To: ' . $email,
    'Content-Type: text/plain; charset=UTF-8',
    'X-Mailer: versarai-contact',
];
$mailOk = @mail(TO_ADDRESS, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));

// 2. CRM webhook (optional)
$crmOk = false;
$webhook = getenv('VERSARAI_CRM_WEBHOOK');
if ($webhook && function_exists('curl_init')) {
    $ch = curl_init($webhook);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($lead),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    curl_exec($ch);
    $crmOk = curl_getinfo($ch, CURLINFO_HTTP_CODE) >= 200 && curl_getinfo($ch, CURLINFO_HTTP_CODE) < 300;
    curl_close($ch);
}

if ($mailOk || $crmOk) {
    respond(200, ['ok' => true]);
}
error_log('contact.php: neither mail() nor the CRM webhook succeeded for ' . $email);
respond(502, ['ok' => false, 'error' => 'delivery_failed']);
