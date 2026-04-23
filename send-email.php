<?php
// Catch fatal errors and always return JSON
set_exception_handler(function($e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Server error. Please call us at (931) 278-4651.']);
    exit;
});
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode(['error' => 'Server error. Please call us at (931) 278-4651.']);
    }
});

header('Content-Type: application/json');

// Load config — fall back to inline values if file not yet on server
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}
if (!defined('POSTMARK_TOKEN'))   define('POSTMARK_TOKEN',   '7e47618c-d3dc-43e0-9d06-758fe5d59418');
if (!defined('MAIL_FROM'))        define('MAIL_FROM',        'contact@r3doubtsec.com');
if (!defined('MAIL_NOTIFY'))      define('MAIL_NOTIFY',      'contact@r3doubtsec.com');
if (!defined('TURNSTILE_SECRET')) define('TURNSTILE_SECRET', '0x4AAAAAAC_FtFUiIvE3PzDOSeTf8napE9s');

// PHP < 8.0 polyfill
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

/* ─── 1. METHOD CHECK ────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

/* ─── 2. ORIGIN / CSRF CHECK ─────────────────────────────────────────────── */
$allowed_origins = [
    'https://r3doubtsec.com',
    'https://www.r3doubtsec.com',
    'http://localhost:3001',
    'http://localhost:3000',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (empty($origin) && !empty($_SERVER['HTTP_REFERER'])) {
    $p = parse_url($_SERVER['HTTP_REFERER']);
    $origin = ($p['scheme'] ?? '') . '://' . ($p['host'] ?? '');
}
$origin_ok = false;
foreach ($allowed_origins as $allowed) {
    if (str_starts_with($origin, $allowed)) { $origin_ok = true; break; }
}
if (!$origin_ok) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

/* ─── 3. RATE LIMIT (5 submissions per IP per hour) ─────────────────────── */
function check_rate_limit(string $ip, int $max = 5, int $window = 3600): bool {
    $file = sys_get_temp_dir() . '/r3d_rl_' . md5($ip) . '.json';
    $now  = time();
    $hits = file_exists($file) ? (json_decode(file_get_contents($file), true) ?? []) : [];
    $hits = array_values(array_filter($hits, fn($t) => $t > $now - $window));
    if (count($hits) >= $max) return false;
    $hits[] = $now;
    file_put_contents($file, json_encode($hits), LOCK_EX);
    return true;
}

$client_ip = $_SERVER['HTTP_CF_CONNECTING_IP']    // Cloudflare
          ?? $_SERVER['HTTP_X_FORWARDED_FOR']
          ?? $_SERVER['REMOTE_ADDR']
          ?? '0.0.0.0';
$client_ip = trim(explode(',', $client_ip)[0]);   // take first if comma-list

if (!check_rate_limit($client_ip)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many requests. Please try again later or call us directly at (931) 278-4651.']);
    exit;
}

/* ─── 4. TURNSTILE VERIFICATION ─────────────────────────────────────────── */
$turnstile_token = trim($_POST['cf-turnstile-response'] ?? '');
if (empty($turnstile_token)) {
    http_response_code(400);
    echo json_encode(['error' => 'Please complete the security check.']);
    exit;
}

$ts = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
curl_setopt_array($ts, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'secret'   => TURNSTILE_SECRET,
        'response' => $turnstile_token,
        'remoteip' => $client_ip,
    ]),
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_TIMEOUT        => 10,
]);
$ts_body   = curl_exec($ts);
$ts_status = curl_getinfo($ts, CURLINFO_HTTP_CODE);
curl_close($ts);

$ts_result = json_decode($ts_body, true);
if ($ts_status !== 200 || empty($ts_result['success'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Security check failed. Please refresh the page and try again.']);
    exit;
}

/* ─── 5. SANITIZE & VALIDATE INPUT ──────────────────────────────────────── */
$firstName    = substr(trim($_POST['firstName']    ?? ''), 0, 100);
$lastName     = substr(trim($_POST['lastName']     ?? ''), 0, 100);
$rawEmail     = trim($_POST['email'] ?? '');
$organization = substr(trim($_POST['organization'] ?? ''), 0, 200);
$phone        = substr(trim($_POST['phone']        ?? ''), 0, 30);
$service      = substr(trim($_POST['service']      ?? ''), 0, 200);
$message      = substr(trim($_POST['message']      ?? ''), 0, 5000);
$urgent       = ($_POST['urgent'] ?? '') === 'true';

// Validate
if (empty($firstName) || empty($lastName)) {
    http_response_code(400);
    echo json_encode(['error' => 'First and last name are required.']);
    exit;
}
$email = filter_var($rawEmail, FILTER_VALIDATE_EMAIL);
if (!$email) {
    http_response_code(400);
    echo json_encode(['error' => 'A valid email address is required.']);
    exit;
}

// HTML-encode for email template output
$firstName    = htmlspecialchars($firstName,    ENT_QUOTES, 'UTF-8');
$lastName     = htmlspecialchars($lastName,     ENT_QUOTES, 'UTF-8');
$organization = htmlspecialchars($organization, ENT_QUOTES, 'UTF-8');
$phone        = htmlspecialchars($phone,        ENT_QUOTES, 'UTF-8');
$service      = htmlspecialchars($service,      ENT_QUOTES, 'UTF-8');
$message      = htmlspecialchars($message,      ENT_QUOTES, 'UTF-8');
// email is already validated; encode for safe HTML embedding
$emailHtml    = htmlspecialchars($email,        ENT_QUOTES, 'UTF-8');

$fullName    = "$firstName $lastName";
$orgLine     = $organization ?: 'Not provided';
$phoneLine   = $phone        ?: 'Not provided';
$serviceLine = $service      ?: 'Not specified';
$messageLine = nl2br($message) ?: 'No message provided';

/* ─── 5. EMAIL TEMPLATES ─────────────────────────────────────────────────── */

/* -- Confirmation (to submitter) -- */
$confirmHtml = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>We Received Your Request</title>
</head>
<body style="margin:0;padding:0;background:#0D1525;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#0D1525;padding:40px 0;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">

        <!-- HEADER -->
        <tr>
          <td style="background:linear-gradient(135deg,#1E0A4E 0%,#3D1470 50%,#5B21B6 100%);border-radius:12px 12px 0 0;padding:40px 40px 32px;text-align:center;">
            <table cellpadding="0" cellspacing="0" style="margin:0 auto 24px;">
              <tr>
                <td style="background:#ffffff;border-radius:12px;padding:12px 20px;text-align:center;">
                  <img src="https://horizons-cdn.hostinger.com/6584349c-f0fa-4cdd-9bae-06e8483a0d47/1ad8da5147e7e42e3cc9226dfb5ea616.png"
                       alt="R3DOUBT Security Group" width="130" style="display:block;"/>
                </td>
              </tr>
            </table>
            <div style="display:inline-block;padding:5px 16px;border:1px solid rgba(196,181,253,.3);border-radius:100px;margin-bottom:20px;">
              <span style="font-size:10px;font-weight:700;letter-spacing:.2em;text-transform:uppercase;color:#C4B5FD;">Message Received</span>
            </div>
            <h1 style="margin:0;font-size:28px;font-weight:800;color:#ffffff;letter-spacing:-.02em;line-height:1.2;">
              Thank You, {$firstName}.
            </h1>
            <p style="margin:12px 0 0;font-size:15px;color:rgba(255,255,255,.65);line-height:1.6;">
              We've received your consultation request and will be in touch within 24 hours.
            </p>
          </td>
        </tr>

        <!-- BODY -->
        <tr>
          <td style="background:#111827;padding:40px;">
            <p style="margin:0 0 28px;font-size:15px;color:rgba(255,255,255,.7);line-height:1.75;">
              Our team of veteran cybersecurity professionals has been notified and will review your request shortly.
              In the meantime, here's a summary of what you submitted:
            </p>

            <!-- Submission Summary -->
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#0D1525;border:1px solid rgba(91,33,182,.25);border-radius:10px;margin-bottom:32px;">
              <tr>
                <td style="padding:24px 28px;">
                  <div style="font-size:10px;font-weight:700;letter-spacing:.2em;text-transform:uppercase;color:#7C3AED;margin-bottom:20px;">Submission Summary</div>
                  <table width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                      <td style="padding:9px 0;border-bottom:1px solid rgba(255,255,255,.06);">
                        <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#8A9BB5;">Name</span>
                      </td>
                      <td style="padding:9px 0;border-bottom:1px solid rgba(255,255,255,.06);text-align:right;">
                        <span style="font-size:14px;color:#fff;font-weight:600;">{$fullName}</span>
                      </td>
                    </tr>
                    <tr>
                      <td style="padding:9px 0;border-bottom:1px solid rgba(255,255,255,.06);">
                        <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#8A9BB5;">Organization</span>
                      </td>
                      <td style="padding:9px 0;border-bottom:1px solid rgba(255,255,255,.06);text-align:right;">
                        <span style="font-size:14px;color:#fff;font-weight:600;">{$orgLine}</span>
                      </td>
                    </tr>
                    <tr>
                      <td style="padding:9px 0;border-bottom:1px solid rgba(255,255,255,.06);">
                        <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#8A9BB5;">Service Interest</span>
                      </td>
                      <td style="padding:9px 0;border-bottom:1px solid rgba(255,255,255,.06);text-align:right;">
                        <span style="font-size:14px;color:#fff;font-weight:600;">{$serviceLine}</span>
                      </td>
                    </tr>
                    <tr>
                      <td style="padding:9px 0;">
                        <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#8A9BB5;">Phone</span>
                      </td>
                      <td style="padding:9px 0;text-align:right;">
                        <span style="font-size:14px;color:#fff;font-weight:600;">{$phoneLine}</span>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>

            <!-- What happens next -->
            <div style="background:#0D1525;border-left:3px solid #5B21B6;border-radius:0 8px 8px 0;padding:20px 24px;margin-bottom:32px;">
              <div style="font-size:11px;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:#7C3AED;margin-bottom:10px;">What Happens Next</div>
              <p style="margin:0;font-size:14px;color:rgba(255,255,255,.65);line-height:1.75;">
                A member of our team will reach out to you at <strong style="color:#C4B5FD;">{$emailHtml}</strong> within 24 hours to discuss your needs and schedule a consultation. For urgent matters, call us directly.
              </p>
            </div>

            <!-- CTA -->
            <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:36px;">
              <tr>
                <td align="center">
                  <a href="tel:+19312784651" style="display:inline-block;padding:14px 36px;background:#5B21B6;color:#fff;text-decoration:none;font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;border-radius:6px;">
                    Call Us Now: (931) 278-4651
                  </a>
                </td>
              </tr>
            </table>

            <hr style="border:none;border-top:1px solid rgba(255,255,255,.08);margin:0 0 28px;"/>
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td align="center">
                  <span style="font-size:11px;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:#8A9BB5;">
                    &#9670;&nbsp; VETERAN-OWNED &nbsp;&#9670;&nbsp; HIPAA COMPLIANT &nbsp;&#9670;&nbsp; 50 STATES SERVED &nbsp;&#9670;
                  </span>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- FOOTER -->
        <tr>
          <td style="background:#090E18;border-radius:0 0 12px 12px;padding:24px 40px;text-align:center;">
            <p style="margin:0 0 6px;font-size:12px;color:rgba(255,255,255,.25);">
              R3DOUBT Security Group &nbsp;·&nbsp; info@r3doubtsec.com &nbsp;·&nbsp; (931) 278-4651
            </p>
            <p style="margin:0;font-size:11px;color:rgba(255,255,255,.15);">
              You received this email because you submitted a consultation request on r3doubtsec.com.
            </p>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

$confirmText = "Thank you, {$firstName}.\n\nWe received your consultation request and will be in touch within 24 hours.\n\nSubmission Summary:\n- Name: {$fullName}\n- Organization: {$orgLine}\n- Service Interest: {$serviceLine}\n- Phone: {$phoneLine}\n\nFor urgent matters call (931) 278-4651 or email info@r3doubtsec.com.\n\nR3DOUBT Security Group";

/* -- Notification (to team) -- */
$urgentRow = $urgent ? "
  <tr>
    <td colspan='2' style='padding:10px 0;border-bottom:1px solid rgba(255,255,255,.06);'>
      <span style='display:inline-block;padding:4px 12px;background:#B71C1C;color:#fff;border-radius:4px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;'>&#9888; URGENT / ACTIVE INCIDENT</span>
    </td>
  </tr>" : '';

$notifyHtml = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>New Contact Form Submission</title>
</head>
<body style="margin:0;padding:0;background:#0D1525;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#0D1525;padding:40px 0;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">

        <!-- HEADER -->
        <tr>
          <td style="background:linear-gradient(135deg,#1E0A4E 0%,#3D1470 50%,#5B21B6 100%);border-radius:12px 12px 0 0;padding:32px 40px;text-align:center;">
            <table cellpadding="0" cellspacing="0" style="margin:0 auto 20px;">
              <tr>
                <td style="background:#ffffff;border-radius:10px;padding:10px 18px;text-align:center;">
                  <img src="https://horizons-cdn.hostinger.com/6584349c-f0fa-4cdd-9bae-06e8483a0d47/1ad8da5147e7e42e3cc9226dfb5ea616.png"
                       alt="R3DOUBT Security Group" width="110" style="display:block;"/>
                </td>
              </tr>
            </table>
            <div style="display:inline-block;padding:4px 14px;border:1px solid rgba(196,181,253,.3);border-radius:100px;margin-bottom:14px;">
              <span style="font-size:10px;font-weight:700;letter-spacing:.2em;text-transform:uppercase;color:#C4B5FD;">New Inquiry</span>
            </div>
            <h1 style="margin:0;font-size:24px;font-weight:800;color:#ffffff;letter-spacing:-.01em;">New Contact Form Submission</h1>
          </td>
        </tr>

        <!-- BODY -->
        <tr>
          <td style="background:#111827;padding:36px 40px;">
            <p style="margin:0 0 24px;font-size:15px;color:rgba(255,255,255,.7);line-height:1.7;">
              A new consultation request was submitted on <strong style="color:#C4B5FD;">r3doubtsec.com</strong>.
              Reply directly to this email to respond to the submitter.
            </p>

            <!-- Contact Details -->
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#0D1525;border:1px solid rgba(91,33,182,.25);border-radius:10px;margin-bottom:28px;">
              <tr>
                <td style="padding:24px 28px;">
                  <div style="font-size:10px;font-weight:700;letter-spacing:.2em;text-transform:uppercase;color:#7C3AED;margin-bottom:18px;">Contact Details</div>
                  <table width="100%" cellpadding="0" cellspacing="0">
                    {$urgentRow}
                    <tr>
                      <td style="padding:9px 0;border-bottom:1px solid rgba(255,255,255,.06);width:38%;">
                        <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#8A9BB5;">Name</span>
                      </td>
                      <td style="padding:9px 0;border-bottom:1px solid rgba(255,255,255,.06);">
                        <span style="font-size:14px;color:#fff;font-weight:600;">{$fullName}</span>
                      </td>
                    </tr>
                    <tr>
                      <td style="padding:9px 0;border-bottom:1px solid rgba(255,255,255,.06);">
                        <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#8A9BB5;">Email</span>
                      </td>
                      <td style="padding:9px 0;border-bottom:1px solid rgba(255,255,255,.06);">
                        <a href="mailto:{$emailHtml}" style="font-size:14px;color:#C4B5FD;font-weight:600;text-decoration:none;">{$emailHtml}</a>
                      </td>
                    </tr>
                    <tr>
                      <td style="padding:9px 0;border-bottom:1px solid rgba(255,255,255,.06);">
                        <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#8A9BB5;">Phone</span>
                      </td>
                      <td style="padding:9px 0;border-bottom:1px solid rgba(255,255,255,.06);">
                        <span style="font-size:14px;color:#fff;font-weight:600;">{$phoneLine}</span>
                      </td>
                    </tr>
                    <tr>
                      <td style="padding:9px 0;border-bottom:1px solid rgba(255,255,255,.06);">
                        <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#8A9BB5;">Organization</span>
                      </td>
                      <td style="padding:9px 0;border-bottom:1px solid rgba(255,255,255,.06);">
                        <span style="font-size:14px;color:#fff;font-weight:600;">{$orgLine}</span>
                      </td>
                    </tr>
                    <tr>
                      <td style="padding:9px 0;">
                        <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#8A9BB5;">Service Interest</span>
                      </td>
                      <td style="padding:9px 0;">
                        <span style="font-size:14px;color:#fff;font-weight:600;">{$serviceLine}</span>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>

            <!-- Message -->
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#0D1525;border:1px solid rgba(91,33,182,.25);border-radius:10px;margin-bottom:28px;">
              <tr>
                <td style="padding:24px 28px;">
                  <div style="font-size:10px;font-weight:700;letter-spacing:.2em;text-transform:uppercase;color:#7C3AED;margin-bottom:14px;">Message</div>
                  <p style="margin:0;font-size:14px;color:rgba(255,255,255,.75);line-height:1.75;">{$messageLine}</p>
                </td>
              </tr>
            </table>

            <!-- Reply CTA -->
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td align="center">
                  <a href="mailto:{$emailHtml}?subject=Re:%20Your%20R3DOUBT%20Security%20Consultation%20Request"
                     style="display:inline-block;padding:14px 36px;background:#5B21B6;color:#fff;text-decoration:none;font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;border-radius:6px;">
                    Reply to {$firstName}
                  </a>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- FOOTER -->
        <tr>
          <td style="background:#090E18;border-radius:0 0 12px 12px;padding:20px 40px;text-align:center;">
            <p style="margin:0;font-size:11px;color:rgba(255,255,255,.2);">
              R3DOUBT Security Group &nbsp;·&nbsp; Internal Notification &nbsp;·&nbsp; r3doubtsec.com
            </p>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

$notifyText = "New contact form submission from {$fullName} ({$email}).\n\nOrganization: {$orgLine}\nPhone: {$phoneLine}\nService: {$serviceLine}\nUrgent: " . ($urgent ? 'YES' : 'No') . "\n\nMessage:\n" . strip_tags($message);

/* ─── 6. SEND VIA POSTMARK (explicit SSL verification) ───────────────────── */
function postmark_send(string $token, array $payload): array {
    $ch = curl_init('https://api.postmarkapp.com/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Content-Type: application/json',
            "X-Postmark-Server-Token: $token",
        ],
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => json_decode($body, true)];
}

// 1. Notification to team
$r1 = postmark_send(POSTMARK_TOKEN, [
    'From'         => 'R3DOUBT Website <' . MAIL_FROM . '>',
    'To'           => MAIL_NOTIFY,
    'ReplyTo'      => $email,
    'Subject'      => ($urgent ? '[URGENT] ' : '') . "New Inquiry: {$fullName}" . ($organization ? " — {$organization}" : ''),
    'HtmlBody'     => $notifyHtml,
    'TextBody'     => $notifyText,
    'MessageStream'=> 'outbound',
]);

// 2. Confirmation to submitter
$r2 = postmark_send(POSTMARK_TOKEN, [
    'From'         => 'R3DOUBT Security Group <' . MAIL_FROM . '>',
    'To'           => $email,
    'Subject'      => 'We Received Your Request — R3DOUBT Security Group',
    'HtmlBody'     => $confirmHtml,
    'TextBody'     => $confirmText,
    'MessageStream'=> 'outbound',
]);

if ($r1['status'] === 200 && $r2['status'] === 200) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to send email. Please try calling us directly at (931) 278-4651.']);
}
