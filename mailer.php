<?php

declare(strict_types=1);

const CONTACT_RECIPIENT = 'info@impulsagroup.com';
const REDIRECT_PATH = 'index.html';
const REDIRECT_FRAGMENT = 'contacto';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWithStatus('error');
}

$honeypot = trim((string) ($_POST['website'] ?? ''));
if ($honeypot !== '') {
    redirectWithStatus('success');
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
    redirectWithStatus('invalid');
}

try {
    $env = loadEnv(__DIR__ . DIRECTORY_SEPARATOR . '.env');
    $smtpHost = requiredEnv($env, 'SMTP_HOST');
    $smtpUser = requiredEnv($env, 'SMTP_USERNAME');
    $smtpPassword = requiredEnv($env, 'SMTP_PASSWORD');
    $smtpPort = (int) requiredEnv($env, 'SMTP_PORT');

    $subject = 'Nuevo contacto desde la landing de Impulsa';
    $textBody = buildTextBody($nombre, $empresa, $email, $telefono, $equipo, $objetivo, $mensaje);
    $htmlBody = buildHtmlBody($nombre, $empresa, $email, $telefono, $equipo, $objetivo, $mensaje);

    sendSmtpMail([
        'host' => $smtpHost,
        'port' => $smtpPort,
        'username' => $smtpUser,
        'password' => $smtpPassword,
        'from_email' => $smtpUser,
        'from_name' => 'Landing Impulsa',
        'to_email' => CONTACT_RECIPIENT,
        'reply_to' => $email,
        'reply_to_name' => $nombre,
        'subject' => $subject,
        'text_body' => $textBody,
        'html_body' => $htmlBody,
    ]);

    redirectWithStatus('success');
} catch (Throwable $exception) {
    error_log('Mailer error: ' . $exception->getMessage());
    redirectWithStatus('error');
}

function redirectWithStatus(string $status): void
{
    $location = REDIRECT_PATH . '?status=' . urlencode($status) . '#' . REDIRECT_FRAGMENT;
    header('Location: ' . $location);
    exit;
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

function sendSmtpMail(array $config): void
{
    $transport = ((int) $config['port'] === 465 ? 'ssl://' : '') . $config['host'];
    $socket = @stream_socket_client(
        $transport . ':' . $config['port'],
        $errorNumber,
        $errorMessage,
        15,
        STREAM_CLIENT_CONNECT
    );

    if (!is_resource($socket)) {
        throw new RuntimeException("SMTP connection failed: {$errorMessage} ({$errorNumber})");
    }

    stream_set_timeout($socket, 15);

    expectSmtp($socket, [220]);
    sendSmtp($socket, 'EHLO ' . gethostnameOrDefault());
    expectSmtp($socket, [250]);

    if ((int) $config['port'] !== 465) {
        sendSmtp($socket, 'STARTTLS');
        expectSmtp($socket, [220]);

        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('Unable to enable TLS encryption');
        }

        sendSmtp($socket, 'EHLO ' . gethostnameOrDefault());
        expectSmtp($socket, [250]);
    }

    sendSmtp($socket, 'AUTH LOGIN');
    expectSmtp($socket, [334]);
    sendSmtp($socket, base64_encode((string) $config['username']));
    expectSmtp($socket, [334]);
    sendSmtp($socket, base64_encode((string) $config['password']));
    expectSmtp($socket, [235]);

    sendSmtp($socket, 'MAIL FROM:<' . $config['from_email'] . '>');
    expectSmtp($socket, [250]);
    sendSmtp($socket, 'RCPT TO:<' . $config['to_email'] . '>');
    expectSmtp($socket, [250, 251]);
    sendSmtp($socket, 'DATA');
    expectSmtp($socket, [354]);

    fwrite($socket, buildMimeMessage($config) . "\r\n.\r\n");
    expectSmtp($socket, [250]);

    sendSmtp($socket, 'QUIT');
    fclose($socket);
}

function buildMimeMessage(array $config): string
{
    $boundary = 'b1_' . bin2hex(random_bytes(12));
    $fromName = encodeHeader((string) $config['from_name']);
    $replyToName = encodeHeader((string) $config['reply_to_name']);
    $subject = encodeHeader((string) $config['subject']);
    $textBody = normalizeSmtpBody((string) $config['text_body']);
    $htmlBody = normalizeSmtpBody((string) $config['html_body']);

    $headers = [
        'From: ' . $fromName . ' <' . $config['from_email'] . '>',
        'To: <' . $config['to_email'] . '>',
        'Reply-To: ' . $replyToName . ' <' . $config['reply_to'] . '>',
        'Subject: ' . $subject,
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];

    $body = implode("\r\n", $headers)
        . "\r\n\r\n--{$boundary}\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $textBody
        . "\r\n--{$boundary}\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $htmlBody
        . "\r\n--{$boundary}--";

    return dotStuff($body);
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

function sendSmtp($socket, string $command): void
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

function gethostnameOrDefault(): string
{
    $hostname = gethostname();

    return is_string($hostname) && $hostname !== '' ? $hostname : 'localhost';
}
