<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Accept, Content-Type');

const SMTP_HOST = 'smtp.strato.com';
const SMTP_PORT = 587;
const SMTP_USER = 'info@assistans-r-h.se';
const SMTP_PASS = 'Ayad67ayad67@ayad';
const SITE_NAME = 'Assistans Runt Hornan AB';
const RECIPIENT_EMAIL = 'info@assistans-r-h.se';

function json_response(bool $ok, string $message, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function clean_text(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/[\r\n]+/', ' ', $value) ?? $value;
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function clean_message(string $value): string
{
    $value = trim($value);
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function post_string(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? $value : '';
}

function smtp_read($socket): string
{
    $data = '';
    while (($line = fgets($socket, 515)) !== false) {
        $data .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return $data;
}

function smtp_command($socket, string $command, array $expected): string
{
    fwrite($socket, $command . "\r\n");
    $response = smtp_read($socket);
    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expected, true)) {
        throw new RuntimeException('SMTP error: ' . trim($response));
    }
    return $response;
}

function header_encode(string $value): string
{
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function build_email(string $to, string $toName, string $subject, string $html, string $text, ?string $replyTo = null, ?string $replyToName = null): string
{
    $boundary = 'b1_' . bin2hex(random_bytes(12));
    $altBoundary = 'b2_' . bin2hex(random_bytes(12));
    $logoPath = __DIR__ . '/logo.png';
    $logo = is_file($logoPath) ? chunk_split(base64_encode((string) file_get_contents($logoPath))) : '';

    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . header_encode(SITE_NAME) . ' <' . SMTP_USER . '>',
        'To: ' . header_encode($toName) . ' <' . $to . '>',
        'Subject: ' . header_encode($subject),
        'MIME-Version: 1.0',
        'Content-Type: multipart/related; boundary="' . $boundary . '"',
    ];

    if ($replyTo !== null) {
        $headers[] = 'Reply-To: ' . header_encode($replyToName ?? $replyTo) . ' <' . $replyTo . '>';
    }

    $message = implode("\r\n", $headers) . "\r\n\r\n";
    $message .= "--{$boundary}\r\n";
    $message .= "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n";
    $message .= "--{$altBoundary}\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $text . "\r\n\r\n";
    $message .= "--{$altBoundary}\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $html . "\r\n\r\n";
    $message .= "--{$altBoundary}--\r\n";

    if ($logo !== '') {
        $message .= "--{$boundary}\r\n";
        $message .= "Content-Type: image/webp; name=\"logo.png\"\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n";
        $message .= "Content-ID: <arh-logo>\r\n";
        $message .= "Content-Disposition: inline; filename=\"logo.png\"\r\n\r\n";
        $message .= $logo . "\r\n";
    }

    $message .= "--{$boundary}--\r\n";
    return $message;
}

function send_smtp(string $to, string $toName, string $subject, string $html, string $text, ?string $replyTo = null, ?string $replyToName = null): void
{
    $socket = stream_socket_client('tcp://' . SMTP_HOST . ':' . SMTP_PORT, $errno, $errstr, 20);
    if (!$socket) {
        throw new RuntimeException("Could not connect to SMTP server: {$errstr}");
    }

    stream_set_timeout($socket, 20);
    smtp_read($socket);
    smtp_command($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), [250]);
    smtp_command($socket, 'STARTTLS', [220]);

    if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        throw new RuntimeException('Could not start SMTP encryption.');
    }

    smtp_command($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), [250]);
    smtp_command($socket, 'AUTH LOGIN', [334]);
    smtp_command($socket, base64_encode(SMTP_USER), [334]);
    smtp_command($socket, base64_encode(SMTP_PASS), [235]);
    smtp_command($socket, 'MAIL FROM:<' . SMTP_USER . '>', [250]);
    smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
    smtp_command($socket, 'DATA', [354]);

    $email = build_email($to, $toName, $subject, $html, $text, $replyTo, $replyToName);
    fwrite($socket, str_replace("\n.", "\n..", $email) . "\r\n.\r\n");
    $response = smtp_read($socket);
    $code = (int) substr($response, 0, 3);
    if ($code !== 250) {
        throw new RuntimeException('SMTP send error: ' . trim($response));
    }

    smtp_command($socket, 'QUIT', [221]);
    fclose($socket);
}

function email_shell(string $title, string $body): string
{
    return '<!doctype html><html><body style="margin:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;color:#161616;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f4f4;padding:28px 12px;">'
        . '<tr><td align="center"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:680px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e8e8e8;">'
        . '<tr><td style="padding:28px 34px 20px 34px;background:#ffffff;border-bottom:1px solid #eeeeee;">'
        . '<img src="cid:arh-logo" alt="Assistans Runt Hornan AB" style="display:block;width:210px;max-width:100%;height:auto;">'
        . '</td></tr><tr><td style="padding:34px;">'
        . '<h1 style="margin:0 0 18px 0;font-size:28px;line-height:1.25;color:#111111;">' . $title . '</h1>'
        . $body
        . '</td></tr><tr><td style="padding:22px 34px;background:#111111;color:#ffffff;font-size:14px;line-height:1.6;">'
        . '<strong>Assistans Runt Hornan AB</strong><br>Tvärvägen 5, 169 36 Solna<br>info@assistans-r-h.se | +46 8 760 19 31'
        . '</td></tr></table></td></tr></table></body></html>';
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    json_response(true, 'OK');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Method not allowed.', 405);
}

$name = clean_text(post_string('name'));
$email = filter_var(trim(post_string('email')), FILTER_VALIDATE_EMAIL);
$phone = clean_text(post_string('phone'));
$message = clean_message(post_string('message'));
$honeypot = trim(post_string('company'));

if ($honeypot !== '') {
    json_response(true, 'Thank you.');
}

if ($name === '' || !$email || $message === '') {
    json_response(false, 'Please fill in your name, email and message.', 422);
}

$plainMessage = trim(strip_tags(html_entity_decode($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')));
$phoneDisplay = $phone !== '' ? $phone : 'Ej angivet';
$submittedAt = date('Y-m-d H:i');

$internalBody = ''
    . '<p style="margin:0 0 22px 0;font-size:16px;line-height:1.7;color:#444;">Ett nytt meddelande har skickats via webbplatsens kontaktformulär.</p>'
    . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 0 24px 0;">'
    . '<tr><td style="padding:12px 0;border-bottom:1px solid #eeeeee;color:#777;width:150px;">Namn</td><td style="padding:12px 0;border-bottom:1px solid #eeeeee;font-weight:700;">' . $name . '</td></tr>'
    . '<tr><td style="padding:12px 0;border-bottom:1px solid #eeeeee;color:#777;">E-post</td><td style="padding:12px 0;border-bottom:1px solid #eeeeee;"><a href="mailto:' . $email . '" style="color:#111111;font-weight:700;">' . $email . '</a></td></tr>'
    . '<tr><td style="padding:12px 0;border-bottom:1px solid #eeeeee;color:#777;">Telefon</td><td style="padding:12px 0;border-bottom:1px solid #eeeeee;font-weight:700;">' . $phoneDisplay . '</td></tr>'
    . '<tr><td style="padding:12px 0;color:#777;">Skickat</td><td style="padding:12px 0;font-weight:700;">' . $submittedAt . '</td></tr>'
    . '</table>'
    . '<div style="background:#f7f7f7;border:1px solid #e8e8e8;border-radius:12px;padding:22px;">'
    . '<div style="font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#777;margin-bottom:10px;">Meddelande</div>'
    . '<div style="font-size:17px;line-height:1.75;color:#222;">' . nl2br($message) . '</div>'
    . '</div>';

$replyBody = ''
    . '<p style="margin:0 0 18px 0;font-size:17px;line-height:1.7;color:#333;">Thank you for contacting us. We have received your message and will get back to you soon.</p>'
    . '<p style="margin:0 0 24px 0;font-size:16px;line-height:1.7;color:#555;">Tack för att du kontaktade Assistans Runt Hornan AB. Vi återkommer så snart vi kan.</p>'
    . '<div style="background:#f7f7f7;border:1px solid #e8e8e8;border-radius:12px;padding:22px;margin-top:8px;">'
    . '<div style="font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#777;margin-bottom:10px;">Your message</div>'
    . '<div style="font-size:16px;line-height:1.75;color:#222;">' . nl2br($message) . '</div>'
    . '</div>';

try {
    send_smtp(
        RECIPIENT_EMAIL,
        SITE_NAME,
        'Nytt meddelande fran webbplatsen - ' . html_entity_decode($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        email_shell('Nytt kontaktmeddelande', $internalBody),
        "Nytt kontaktmeddelande\n\nNamn: {$name}\nE-post: {$email}\nTelefon: {$phoneDisplay}\nSkickat: {$submittedAt}\n\nMeddelande:\n{$plainMessage}",
        (string) $email,
        html_entity_decode($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
    );

    send_smtp(
        (string) $email,
        html_entity_decode($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        'Thank you for contacting Assistans Runt Hornan AB',
        email_shell('Thank you for contacting us', $replyBody),
        "Thank you for contacting us.\n\nWe have received your message and will get back to you soon.\n\nYour message:\n{$plainMessage}",
        RECIPIENT_EMAIL,
        SITE_NAME
    );

    json_response(true, 'Message sent successfully.');
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_response(false, 'The message could not be sent right now. Please try again later.', 500);
}
