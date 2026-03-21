<?php

declare(strict_types=1);

const CAPTCHA_EXPECTED = '7';
const MIN_SUBMIT_SECONDS = 3;
const MAX_SUBMIT_SECONDS = 7200;

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond('error', 'Metodo no permitido.', 405);
}

$honeypot = trim((string) ($_POST['website'] ?? ''));
if ($honeypot !== '') {
    respond('success', 'Solicitud recibida.');
}

$renderedAt = (int) ($_POST['form_rendered_at'] ?? 0);
$captcha = cleanText($_POST['captcha'] ?? '');
$secondsSinceRendered = $renderedAt > 0 ? (int) floor((microtime(true) * 1000 - $renderedAt) / 1000) : 0;

if (
    $captcha !== CAPTCHA_EXPECTED ||
    $renderedAt <= 0 ||
    $secondsSinceRendered < MIN_SUBMIT_SECONDS ||
    $secondsSinceRendered > MAX_SUBMIT_SECONDS
) {
    respond('invalid_captcha', 'No pudimos validar el captcha.', 422);
}

$nombre = cleanText($_POST['nombre'] ?? '');
$empresa = cleanText($_POST['empresa'] ?? '');
$email = trim((string) ($_POST['email'] ?? ''));
$telefono = cleanText($_POST['telefono'] ?? '');
$equipo = cleanText($_POST['equipo'] ?? '');
$objetivo = cleanText($_POST['objetivo'] ?? '');
$mensaje = cleanTextarea($_POST['mensaje'] ?? '');
$formSource = cleanText($_POST['form_source'] ?? 'landing-impulsa');
$ipAddress = cleanText($_SERVER['REMOTE_ADDR'] ?? '');
$userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

if (
    $nombre === '' ||
    $empresa === '' ||
    $telefono === '' ||
    !filter_var($email, FILTER_VALIDATE_EMAIL)
) {
    respond('invalid', 'Revisa los datos ingresados e intenta nuevamente.', 422);
}

try {
    $env = loadEnv(__DIR__ . DIRECTORY_SEPARATOR . '.env');
    $pdo = connectDatabase($env);
    ensureContactsTable($pdo);
    insertContact($pdo, [
        'nombre' => $nombre,
        'empresa' => $empresa,
        'email' => $email,
        'telefono' => $telefono,
        'equipo' => $equipo,
        'objetivo' => $objetivo,
        'mensaje' => $mensaje,
        'form_source' => $formSource,
        'ip_address' => $ipAddress,
        'user_agent' => $userAgent,
    ]);

    respond('success', 'Gracias. Tu mensaje fue enviado y te responderemos a la brevedad.');
} catch (Throwable $exception) {
    error_log('Contact form error: ' . $exception->getMessage());
    respond('error', 'No se pudo enviar el mensaje en este momento. Intenta nuevamente en unos minutos.', 500);
}

function respond(string $status, string $message, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode([
        'status' => $status,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? '';

    return mb_substr($text, 0, 5000);
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

function connectDatabase(array $env): PDO
{
    $hostConfig = requiredEnv($env, 'DB_HOST');
    $database = requiredEnv($env, 'DB_NAME');
    $username = requiredEnv($env, 'DB_USER');
    $password = requiredEnv($env, 'DB_PASS');

    [$host, $port] = parseHostConfig($hostConfig);
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function parseHostConfig(string $hostConfig): array
{
    $host = $hostConfig;
    $port = 3306;

    if (str_contains($hostConfig, ':')) {
        [$host, $portValue] = explode(':', $hostConfig, 2);
        $host = trim($host);
        $port = (int) trim($portValue);
    }

    if ($host === '' || $port <= 0) {
        throw new RuntimeException('Invalid DB_HOST configuration.');
    }

    return [$host, $port];
}

function ensureContactsTable(PDO $pdo): void
{
    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS contacto_landing (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(150) NOT NULL,
    empresa VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL,
    telefono VARCHAR(80) NOT NULL,
    equipo VARCHAR(80) DEFAULT NULL,
    objetivo VARCHAR(120) DEFAULT NULL,
    mensaje TEXT DEFAULT NULL,
    form_source VARCHAR(100) NOT NULL DEFAULT 'landing-impulsa',
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_contacto_landing_created_at (created_at),
    KEY idx_contacto_landing_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    $pdo->exec($sql);
}

function insertContact(PDO $pdo, array $contact): void
{
    $sql = <<<SQL
INSERT INTO contacto_landing (
    nombre,
    empresa,
    email,
    telefono,
    equipo,
    objetivo,
    mensaje,
    form_source,
    ip_address,
    user_agent
) VALUES (
    :nombre,
    :empresa,
    :email,
    :telefono,
    :equipo,
    :objetivo,
    :mensaje,
    :form_source,
    :ip_address,
    :user_agent
)
SQL;

    $statement = $pdo->prepare($sql);
    $statement->execute([
        'nombre' => $contact['nombre'],
        'empresa' => $contact['empresa'],
        'email' => $contact['email'],
        'telefono' => $contact['telefono'],
        'equipo' => $contact['equipo'] !== '' ? $contact['equipo'] : null,
        'objetivo' => $contact['objetivo'] !== '' ? $contact['objetivo'] : null,
        'mensaje' => $contact['mensaje'] !== '' ? $contact['mensaje'] : null,
        'form_source' => $contact['form_source'] !== '' ? $contact['form_source'] : 'landing-impulsa',
        'ip_address' => $contact['ip_address'] !== '' ? $contact['ip_address'] : null,
        'user_agent' => $contact['user_agent'] !== '' ? mb_substr($contact['user_agent'], 0, 255) : null,
    ]);
}
