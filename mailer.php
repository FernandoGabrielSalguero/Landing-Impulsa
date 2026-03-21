<?php

declare(strict_types=1);

const CONTACT_RECIPIENT = 'info@impulsagroup.com';
const REDIRECT_PATH = 'index.html';
const REDIRECT_FRAGMENT = 'contacto';
const CAPTCHA_EXPECTED = '7';
const MIN_SUBMIT_SECONDS = 3;
const MAX_SUBMIT_SECONDS = 7200;
const DEBUG_QUERY_LIMIT = 700;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setDebugMessage('Solicitud rechazada: metodo no permitido (' . ($_SERVER['REQUEST_METHOD'] ?? 'unknown') . ').');
    redirectWithStatus('error');
}

$honeypot = trim((string) ($_POST['website'] ?? ''));
if ($honeypot !== '') {
    setDebugMessage('Honeypot activado. Se descarta el envio como posible bot.');
    redirectWithStatus('success');
}

$renderedAt = (int) ($_POST['form_rendered_at'] ?? 0);
$captcha = cleanText($_POST['captcha'] ?? '');
$secondsSinceRendered = $renderedAt > 0 ? (int) floor((time() * 1000 - $renderedAt) / 1000) : 0;

if (
    $captcha !== CAPTCHA_EXPECTED ||
    $renderedAt <= 0 ||
    $secondsSinceRendered < MIN_SUBMIT_SECONDS ||
    $secondsSinceRendered > MAX_SUBMIT_SECONDS
) {
    setDebugMessage(
        'Captcha/tiempo invalido. captcha=' . $captcha
        . ', renderedAt=' . $renderedAt
        . ', elapsed=' . $secondsSinceRendered . 's.'
    );
    redirectWithStatus('invalid_captcha');
}

$nombre = cleanText($_POST['nombre'] ?? '');
$empresa = cleanText($_POST['empresa'] ?? '');
$email = trim((string) ($_POST['email'] ?? ''));
$telefono = cleanText($_POST['telefono'] ?? '');
$equipo = cleanText($_POST['equipo'] ?? '');
$objetivo = cleanText($_POST['objetivo'] ?? '');
$mensaje = cleanTextarea($_POST['mensaje'] ?? '');

if (
    $nombre === '' ||
    $empresa === '' ||
    $telefono === '' ||
    !filter_var($email, FILTER_VALIDATE_EMAIL)
) {
    setDebugMessage('Validacion invalida. Revisar nombre, empresa, telefono y email.');
    redirectWithStatus('invalid');
}

try {
    $env = loadEnv(__DIR__ . DIRECTORY_SEPARATOR . '.env');
    $smtpHost = requiredEnv($env, 'SMTP_HOST');
    $smtpUser = requiredEnv($env, 'SMTP_USERNAME');
    $smtpPassword = requiredEnv($env, 'SMTP_PASSWORD');
    $smtpPort = (int) requiredEnv($env, 'SMTP_PORT');

    $mailData = [
        'host' => $smtpHost,
        'port' => $smtpPort,
        'username' => $smtpUser,
        'password' => $smtpPassword,
        'from_email' => $smtpUser,
        'from_name' => 'Landing Impulsa',
        'to_email' => CONTACT_RECIPIENT,
        'to_name' => 'Info Impulsa',
        'reply_to' => $email,
        'reply_to_name' => $nombre,
        'subject' => 'Nuevo contacto desde la landing de Impulsa',
        'text_body' => buildTextBody($nombre, $empresa, $email, $telefono, $equipo, $objetivo, $mensaje),
        'html_body' => buildHtmlBody($nombre, $empresa, $email, $telefono, $equipo, $objetivo, $mensaje),
    ];

    setDebugMessage(
        'Intentando envio a ' . CONTACT_RECIPIENT
        . ' usando host=' . $smtpHost
        . ', port=' . $smtpPort
        . ', user=' . $smtpUser . '.'
    );
    sendMail($mailData);
    setDebugMessage('Correo enviado correctamente.');
    redirectWithStatus('success');
} catch (Throwable $exception) {
    error_log('Mailer error: ' . $exception->getMessage());
    setDebugMessage('Error final: ' . $exception->getMessage());
    redirectWithStatus('error');
}

function redirectWithStatus(string $status): void
{
    $debugMessage = getDebugMessage();
    $query = 'status=' . urlencode($status);
    if ($debugMessage !== '') {
        $query .= '&debug=' . rawurlencode(limitDebugMessage($debugMessage));
    }

    $location = REDIRECT_PATH . '?' . $query . '#' . REDIRECT_FRAGMENT;
    header('Location: ' . $location);
    exit;
}

function setDebugMessage(string $message): void
{
    $GLOBALS['mailer_debug'] = '[' . date('Y-m-d H:i:s') . '] ' . $message;
}

function getDebugMessage(): string
{
    return (string) ($GLOBALS['mailer_debug'] ?? '');
}

function limitDebugMessage(string $message): string
{
    if (strlen($message) <= DEBUG_QUERY_LIMIT) {
        return $message;
    }

    return substr($message, 0, DEBUG_QUERY_LIMIT) . '...';
}

function cleanText(mixed $value): string
{
    $text = trim((string) $value);
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';

    return str_replace(["\r", "\n"], ' ', $text);
}

function cleanTextarea(mixed $value): string
{
    $text = trim((string) $value);
    $text = str_replace("\r\n", "\n", $text);

    return preg_replace("/\n{3,}/", "\n\n", $text) ?? '';
}

function loadEnv(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('.env not found');
    }

    $env = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException('Unable to read .env');
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        $env[$key] = $value;
    }

    return $env;
}

function requiredEnv(array $env, string $key): string
{
    $value = $env[$key] ?? '';
    if ($value === '') {
        throw new RuntimeException("Missing env value: {$key}");
    }

    return $value;
}

function buildTextBody(
    string $nombre,
    string $empresa,
    string $email,
    string $telefono,
    string $equipo,
    string $objetivo,
    string $mensaje
): string {
    $lines = [
        'Nuevo contacto desde la landing de Impulsa',
        '',
        "Nombre: {$nombre}",
        "Empresa: {$empresa}",
        "Email: {$email}",
        "Telefono: {$telefono}",
        'Tamano del equipo: ' . ($equipo !== '' ? $equipo : 'No especificado'),
        'Principal necesidad: ' . ($objetivo !== '' ? $objetivo : 'No especificada'),
        '',
        'Mensaje:',
        $mensaje !== '' ? $mensaje : 'No dejo mensaje adicional.',
    ];

    return implode("\r\n", $lines);
}

function buildHtmlBody(
    string $nombre,
    string $empresa,
    string $email,
    string $telefono,
    string $equipo,
    string $objetivo,
    string $mensaje
): string {
    $rows = [
        'Nombre' => $nombre,
        'Empresa' => $empresa,
        'Email' => $email,
        'Telefono' => $telefono,
        'Tamano del equipo' => $equipo !== '' ? $equipo : 'No especificado',
        'Principal necesidad' => $objetivo !== '' ? $objetivo : 'No especificada',
    ];

    $htmlRows = '';
    foreach ($rows as $label => $value) {
        $htmlRows .= '<tr><td style="padding:8px 12px;font-weight:700;border:1px solid #dbe6ef;">'
            . escapeHtml($label)
            . '</td><td style="padding:8px 12px;border:1px solid #dbe6ef;">'
            . escapeHtml($value)
            . '</td></tr>';
    }

    $messageBlock = nl2br(escapeHtml($mensaje !== '' ? $mensaje : 'No dejo mensaje adicional.'));

    return '<!DOCTYPE html><html lang="es"><body style="font-family:Arial,sans-serif;color:#112c4e;">'
        . '<h2>Nuevo contacto desde la landing de Impulsa</h2>'
        . '<table style="border-collapse:collapse;width:100%;max-width:700px;">'
        . $htmlRows
        . '</table>'
        . '<h3 style="margin-top:24px;">Mensaje</h3>'
        . '<div style="padding:12px;border:1px solid #dbe6ef;border-radius:8px;background:#f7fbfd;max-width:700px;">'
        . $messageBlock
        . '</div>'
        . '</body></html>';
}

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sendMail(array $config): void
{
    $errors = [];

    try {
        sendSmtpMail($config);
        setDebugMessage('SMTP autenticado envio correctamente.');
        return;
    } catch (Throwable $exception) {
        $errors[] = 'SMTP: ' . $exception->getMessage();
        setDebugMessage('Fallo SMTP: ' . $exception->getMessage() . '. Se intenta mail() nativo.');
    }

    if (sendPhpMail($config)) {
        setDebugMessage('SMTP fallo, pero mail() del servidor envio correctamente.');
        return;
    }

    setDebugMessage('SMTP y mail() fallaron. ' . implode(' | ', $errors));
    throw new RuntimeException('No se pudo enviar el correo. ' . implode(' | ', $errors));
}

function sendPhpMail(array $config): bool
{
    $headers = buildMailHeaders($config);
    $subject = decodeMimeHeader(encodeHeader((string) $config['subject']));
    $body = buildMimeBody($config);
    $params = '-f' . $config['from_email'];

    return @mail((string) $config['to_email'], $subject, $body, $headers, $params);
}

function sendSmtpMail(array $config): void
{
    $socket = openSmtpSocket((string) $config['host'], (int) $config['port']);
    stream_set_timeout($socket, 20);

    expectSmtp($socket, [220]);
    smtpCommand($socket, 'EHLO ' . getEhloDomain((string) $config['from_email']));
    expectSmtp($socket, [250]);

    if ((int) $config['port'] === 587) {
        smtpCommand($socket, 'STARTTLS');
        expectSmtp($socket, [220]);

        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('Unable to enable TLS encryption');
        }

        smtpCommand($socket, 'EHLO ' . getEhloDomain((string) $config['from_email']));
        expectSmtp($socket, [250]);
    }

    smtpCommand($socket, 'AUTH LOGIN');
    expectSmtp($socket, [334]);
    smtpCommand($socket, base64_encode((string) $config['username']));
    expectSmtp($socket, [334]);
    smtpCommand($socket, base64_encode((string) $config['password']));
    expectSmtp($socket, [235]);

    smtpCommand($socket, 'MAIL FROM:<' . $config['from_email'] . '>');
    expectSmtp($socket, [250]);
    smtpCommand($socket, 'RCPT TO:<' . $config['to_email'] . '>');
    expectSmtp($socket, [250, 251]);
    smtpCommand($socket, 'DATA');
    expectSmtp($socket, [354]);

    fwrite($socket, buildSmtpMessage($config) . "\r\n.\r\n");
    expectSmtp($socket, [250]);

    smtpCommand($socket, 'QUIT');
    fclose($socket);
}

function openSmtpSocket(string $host, int $port)
{
    $remote = ($port === 465 ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ],
    ]);

    $socket = @stream_socket_client(
        $remote,
        $errorNumber,
        $errorMessage,
        20,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!is_resource($socket)) {
        throw new RuntimeException("SMTP connection failed: {$errorMessage} ({$errorNumber})");
    }

    return $socket;
}

function buildSmtpMessage(array $config): string
{
    return dotStuff(buildMailHeaders($config) . "\r\n\r\n" . buildMimeBody($config));
}

function buildMailHeaders(array $config): string
{
    $headers = [
        'Date: ' . gmdate('D, d M Y H:i:s O'),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . getEhloDomain((string) $config['from_email']) . '>',
        'From: ' . encodeAddressHeader((string) $config['from_name'], (string) $config['from_email']),
        'To: ' . encodeAddressHeader((string) ($config['to_name'] ?? ''), (string) $config['to_email']),
        'Reply-To: ' . encodeAddressHeader((string) $config['reply_to_name'], (string) $config['reply_to']),
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . getBoundary() . '"',
        'X-Mailer: PHP/' . PHP_VERSION,
    ];

    return implode("\r\n", $headers);
}

function buildMimeBody(array $config): string
{
    $boundary = getBoundary();
    $textBody = chunk_split(base64_encode(normalizeSmtpBody((string) $config['text_body'])));
    $htmlBody = chunk_split(base64_encode(normalizeSmtpBody((string) $config['html_body'])));

    return '--' . $boundary . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . $textBody
        . '--' . $boundary . "\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . $htmlBody
        . '--' . $boundary . "--";
}

function getBoundary(): string
{
    static $boundary = null;

    if ($boundary === null) {
        $boundary = 'b1_' . bin2hex(random_bytes(12));
    }

    return $boundary;
}

function encodeAddressHeader(string $name, string $email): string
{
    $name = trim($name);
    if ($name === '') {
        return '<' . $email . '>';
    }

    return encodeHeader($name) . ' <' . $email . '>';
}

function normalizeSmtpBody(string $body): string
{
    $body = str_replace("\r\n", "\n", $body);
    $body = str_replace("\r", "\n", $body);

    return str_replace("\n", "\r\n", $body);
}

function dotStuff(string $message): string
{
    return preg_replace('/^\./m', '..', $message) ?? $message;
}

function encodeHeader(string $value): string
{
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function decodeMimeHeader(string $value): string
{
    if (function_exists('mb_decode_mimeheader')) {
        return mb_decode_mimeheader($value);
    }

    return $value;
}

function smtpCommand($socket, string $command): void
{
    fwrite($socket, $command . "\r\n");
}

function expectSmtp($socket, array $expectedCodes): string
{
    $response = '';

    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }

    if ($response === '') {
        throw new RuntimeException('Empty SMTP response');
    }

    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('Unexpected SMTP response: ' . trim($response));
    }

    return $response;
}

function getEhloDomain(string $email): string
{
    $parts = explode('@', $email);
    $domain = $parts[1] ?? '';

    return $domain !== '' ? $domain : 'localhost';
}
