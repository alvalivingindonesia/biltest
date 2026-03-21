<?php
/**
 * Build in Lombok — Standalone SMTP Mailer
 *
 * Sends email via SMTP over SSL (port 465) with no external dependencies.
 * Compatible with PHP 7.x. Uses constants from biltest_config.php:
 *   SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_FROM_EMAIL, SMTP_FROM_NAME
 *
 * Place at: /api/smtp_mailer.php
 *
 * Usage:
 *   require_once(__DIR__ . '/smtp_mailer.php');
 *   $result = smtp_send_mail('to@example.com', 'Subject', '<h1>HTML body</h1>', 'Plain text fallback');
 *   if ($result !== true) { echo "Error: " . $result; }
 */

/**
 * Send an email via SMTP over SSL.
 *
 * @param string $to_email     Recipient email address
 * @param string $subject      Email subject
 * @param string $html_body    HTML version of the email body
 * @param string $text_body    Plain-text version of the email body
 * @return true|string         True on success, error message string on failure
 */
function smtp_send_mail($to_email, $subject, $html_body, $text_body)
{
    $host       = defined('SMTP_HOST') ? SMTP_HOST : 'mail.roving-i.com.au';
    $port       = defined('SMTP_PORT') ? SMTP_PORT : 465;
    $user       = defined('SMTP_USER') ? SMTP_USER : '';
    $pass       = defined('SMTP_PASS') ? SMTP_PASS : '';
    $from_email = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : $user;
    $from_name  = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Build in Lombok';

    if (empty($user) || empty($pass)) {
        return 'SMTP credentials not configured in biltest_config.php';
    }

    $errno  = 0;
    $errstr = '';

    // Open SSL socket to SMTP server
    $smtp = @fsockopen('ssl://' . $host, $port, $errno, $errstr, 30);
    if (!$smtp) {
        return 'Could not connect to SMTP server: ' . $errstr . ' (' . $errno . ')';
    }

    // Set stream timeout
    stream_set_timeout($smtp, 30);

    // Read greeting
    $resp = _smtp_read($smtp);
    if (_smtp_code($resp) !== 220) {
        fclose($smtp);
        return 'SMTP greeting error: ' . $resp;
    }

    // EHLO
    $err = _smtp_command($smtp, 'EHLO ' . gethostname(), 250);
    if ($err !== true) {
        fclose($smtp);
        return $err;
    }

    // AUTH LOGIN
    $err = _smtp_command($smtp, 'AUTH LOGIN', 334);
    if ($err !== true) {
        fclose($smtp);
        return $err;
    }

    // Username (base64)
    $err = _smtp_command($smtp, base64_encode($user), 334);
    if ($err !== true) {
        fclose($smtp);
        return $err;
    }

    // Password (base64)
    $err = _smtp_command($smtp, base64_encode($pass), 235);
    if ($err !== true) {
        fclose($smtp);
        return 'SMTP authentication failed — check SMTP_USER and SMTP_PASS in biltest_config.php';
    }

    // MAIL FROM
    $err = _smtp_command($smtp, 'MAIL FROM:<' . $from_email . '>', 250);
    if ($err !== true) {
        fclose($smtp);
        return $err;
    }

    // RCPT TO
    $err = _smtp_command($smtp, 'RCPT TO:<' . $to_email . '>', 250);
    if ($err !== true) {
        fclose($smtp);
        return $err;
    }

    // DATA
    $err = _smtp_command($smtp, 'DATA', 354);
    if ($err !== true) {
        fclose($smtp);
        return $err;
    }

    // Build MIME message
    $boundary = 'BIL_' . md5(uniqid(mt_rand(), true));
    $date = date('r');
    $msg_id = '<' . md5(uniqid(mt_rand(), true)) . '@' . $host . '>';

    $message  = 'Date: ' . $date . "\r\n";
    $message .= 'From: ' . _smtp_encode_name($from_name) . ' <' . $from_email . '>' . "\r\n";
    $message .= 'To: <' . $to_email . '>' . "\r\n";
    $message .= 'Subject: ' . _smtp_encode_subject($subject) . "\r\n";
    $message .= 'Message-ID: ' . $msg_id . "\r\n";
    $message .= 'MIME-Version: 1.0' . "\r\n";
    $message .= 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . "\r\n";
    $message .= "\r\n";

    // Plain-text part
    $message .= '--' . $boundary . "\r\n";
    $message .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
    $message .= 'Content-Transfer-Encoding: base64' . "\r\n";
    $message .= "\r\n";
    $message .= chunk_split(base64_encode($text_body)) . "\r\n";

    // HTML part
    $message .= '--' . $boundary . "\r\n";
    $message .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
    $message .= 'Content-Transfer-Encoding: base64' . "\r\n";
    $message .= "\r\n";
    $message .= chunk_split(base64_encode($html_body)) . "\r\n";

    // End boundary
    $message .= '--' . $boundary . '--' . "\r\n";

    // Transparency: escape lines starting with a dot
    $message = str_replace("\r\n.\r\n", "\r\n..\r\n", $message);

    // Send message data + terminator
    fwrite($smtp, $message . "\r\n.\r\n");
    $resp = _smtp_read($smtp);
    if (_smtp_code($resp) !== 250) {
        fclose($smtp);
        return 'SMTP data rejected: ' . $resp;
    }

    // QUIT
    _smtp_command($smtp, 'QUIT', 221);
    fclose($smtp);

    return true;
}

/**
 * Send an SMTP command and check the response code.
 *
 * @param resource $smtp     Socket resource
 * @param string   $cmd      SMTP command to send
 * @param int      $expect   Expected response code
 * @return true|string       True on success, error message on failure
 */
function _smtp_command($smtp, $cmd, $expect)
{
    fwrite($smtp, $cmd . "\r\n");
    $resp = _smtp_read($smtp);
    if (_smtp_code($resp) !== $expect) {
        return 'SMTP error (expected ' . $expect . '): ' . trim($resp);
    }
    return true;
}

/**
 * Read response from SMTP server (handles multi-line).
 *
 * @param resource $smtp
 * @return string
 */
function _smtp_read($smtp)
{
    $data = '';
    while ($line = fgets($smtp, 512)) {
        $data .= $line;
        // Last line of response: code followed by space (not hyphen)
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
        // Also break if line is shorter than 4 chars (safety)
        if (strlen($line) < 4) {
            break;
        }
    }
    return $data;
}

/**
 * Extract numeric response code from SMTP response.
 *
 * @param string $response
 * @return int
 */
function _smtp_code($response)
{
    return (int)substr(trim($response), 0, 3);
}

/**
 * Encode a display name for email headers (RFC 2047 if non-ASCII).
 *
 * @param string $name
 * @return string
 */
function _smtp_encode_name($name)
{
    if (preg_match('/[^\x20-\x7E]/', $name)) {
        return '=?UTF-8?B?' . base64_encode($name) . '?=';
    }
    return '"' . addcslashes($name, '"\\') . '"';
}

/**
 * Encode subject for email headers (RFC 2047 if non-ASCII).
 *
 * @param string $subject
 * @return string
 */
function _smtp_encode_subject($subject)
{
    if (preg_match('/[^\x20-\x7E]/', $subject)) {
        return '=?UTF-8?B?' . base64_encode($subject) . '?=';
    }
    return $subject;
}
