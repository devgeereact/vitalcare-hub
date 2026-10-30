<?php

declare(strict_types=1);

use Vitalcare\{Api, ContactService, HttpError, Turnstile};

/** @var PDO $pdo */
$tsOk = fn (string $token, ?string $ip): bool => $token === 'good-token';
$api = new Api(fn () => [], $pdo, '8.4.0', true, $tsOk, false, 'info@vitalcare.uk');
$form = fn (array $o = []) => json_encode(array_merge([
    'name' => 'Pat Example', 'email' => 'Pat@Example.com', 'phone' => '020 8059 8757',
    'message' => 'Please send details of your first aid courses for twelve staff.', 'turnstileToken' => 'good-token', 'website' => '',
], $o));
$post = fn (string $body, string $ip = '203.0.113.50') => $api->handle('POST', '/api/contact', null, $body, $ip);
$rows = fn (string $t) => (int) $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();

// Happy path.
[$s, $b] = $post($form());
check('a valid enquiry is 201 received', $s === 201 && $b === ['received' => true]);
check('one enquiry is stored', $rows('enquiries') === 1);
$e = $pdo->query('SELECT * FROM enquiries')->fetch();
check('email is normalised', $e['email'] === 'pat@example.com');
check('the sender address is stored as a hash only', $e['ip_hash'] === hash('sha256', '203.0.113.50') && !str_contains(json_encode($e), '203.0.113'));
$m = $pdo->query('SELECT * FROM outbox_email')->fetch();
check('exactly one email is queued', $rows('outbox_email') === 1);
check('it goes to the office only', $m['to_email'] === 'info@vitalcare.uk');
check('the visitor is the Reply-To', $m['reply_to'] === 'pat@example.com');
check('the subject is fixed', $m['subject'] === 'New website enquiry');
check('the body carries the message', str_contains($m['body_text'], 'twelve staff') && str_contains($m['body_text'], 'Pat Example'));
check('nothing is queued for the visitor (no relay)', (int) $pdo->query("SELECT COUNT(*) FROM outbox_email WHERE to_email <> 'info@vitalcare.uk'")->fetchColumn() === 0);

// Validation: plain messages per field, nothing stored.
$before = [$rows('enquiries'), $rows('outbox_email')];
$cases = [
    'empty name' => [['name' => ''], 'name'],
    'name with a newline' => [['name' => "Pat\r\nBcc: x@evil.test"], 'name'],
    'overlong name' => [['name' => str_repeat('a', 121)], 'name'],
    'no email' => [['email' => ''], 'email'],
    'bad email' => [['email' => 'pat@'], 'email'],
    'email with a header injection' => [['email' => "pat@example.com\r\nBcc: x@evil.test"], 'email'],
    'email as an array' => [['email' => ['a@b.co']], 'email'],
    'phone with letters' => [['phone' => 'call me'], 'phone'],
    'short message' => [['message' => 'hi'], 'message'],
    'overlong message' => [['message' => str_repeat('a', 4001)], 'message'],
    'message as a number' => [['message' => 12345678901], 'message'],
];
foreach ($cases as $label => [$over, $field]) {
    [$s, $b] = $post($form($over), '198.51.100.' . random_int(1, 250));
    check("$label is 422 on the right field", $s === 422 && isset($b['error']['fields'][$field]), json_encode($b['error'] ?? $b));
}
check('invalid submissions store and queue nothing', [$rows('enquiries'), $rows('outbox_email')] === $before);
[$s, $b] = $post($form(['name' => '', 'email' => 'x']), '198.51.100.77');
check('several problems are reported together', isset($b['error']['fields']['name'], $b['error']['fields']['email']));
check('an optional phone may be empty', $post($form(['phone' => '']), '198.51.100.78')[0] === 201);

// Honeypot: success is faked, nothing is stored.
$before = [$rows('enquiries'), $rows('outbox_email')];
[$s, $b] = $post($form(['website' => 'http://spam.example']), '198.51.100.90');
check('honeypot gets a normal-looking 201', $s === 201 && $b === ['received' => true]);
check('honeypot stores and queues nothing', [$rows('enquiries'), $rows('outbox_email')] === $before);

// Turnstile.
[$s, $b] = $post($form(['turnstileToken' => 'forged']), '198.51.100.91');
check('a bad Turnstile token is 403 verification_failed', $s === 403 && $b['error']['code'] === 'verification_failed');
[$s] = $post($form(['turnstileToken' => '']), '198.51.100.92');
check('a missing Turnstile token is refused', $s === 403);
[$s] = $post(json_encode(['name' => 'Pat', 'email' => 'p@example.com', 'message' => 'A reasonable message here']), '198.51.100.93');
check('no token field at all is refused', $s === 403);
check('a refused token stores nothing', [$rows('enquiries'), $rows('outbox_email')] === $before);
$none = new Api(fn () => [], $pdo, '8.4.0', true, null, false);
check('Turnstile not configured: the form is 503, not open', $none->handle('POST', '/api/contact', null, $form(), '198.51.100.94')[0] === 503);
$bypass = new Api(fn () => [], $pdo, '8.4.0', true, null, true);
check('explicit local bypass accepts without a token', $bypass->handle('POST', '/api/contact', null, $form(['turnstileToken' => '']), '198.51.100.95')[0] === 201);

// Body handling.
check('oversized body is 413', $post(str_repeat('x', 20000), '198.51.100.96')[0] === 413);
check('missing body is 413-or-422, never stored', in_array($api->handle('POST', '/api/contact', null, null, '198.51.100.97')[0], [413, 422], true));
check('invalid JSON is 422', $post('{not json', '198.51.100.98')[0] === 422);
check('a JSON list is 422', $post('[1,2,3]', '198.51.100.99')[0] === 422);
check('GET is refused with 405', $api->handle('GET', '/api/contact', null, null, '198.51.100.100')[0] === 405);

// Rate limit: 5 per hour per caller (valid and invalid submissions both count).
$ip = '192.0.2.44';
$codes = [];
for ($i = 0; $i < 7; $i++) {
    $codes[] = $post($form(), $ip)[0];
}
check('five submissions pass, then 429', $codes === [201, 201, 201, 201, 201, 429, 429], json_encode($codes));
check('another caller is unaffected', $post($form(), '192.0.2.45')[0] === 201);

// Turnstile helper.
$ok = fn (string $url, array $f): string => json_encode(['success' => true]);
$no = fn (string $url, array $f): string => json_encode(['success' => false, 'error-codes' => ['invalid-input-response']]);
check('Turnstile accepts a success response', Turnstile::verify('secret', 'tok', '1.2.3.4', $ok));
check('Turnstile refuses a failure response', !Turnstile::verify('secret', 'tok', '1.2.3.4', $no));
check('Turnstile refuses a network error', !Turnstile::verify('secret', 'tok', null, function () { throw new RuntimeException('down'); }));
check('Turnstile refuses a null body', !Turnstile::verify('secret', 'tok', null, fn () => null));
check('Turnstile refuses garbage', !Turnstile::verify('secret', 'tok', null, fn () => 'not json'));
check('Turnstile refuses a truthy string instead of true', !Turnstile::verify('secret', 'tok', null, fn () => '{"success":"true"}'));
check('Turnstile refuses an empty token', !Turnstile::verify('secret', '', null, $ok));
check('Turnstile refuses an empty secret', !Turnstile::verify('', 'tok', null, $ok));
check('Turnstile refuses an oversized token', !Turnstile::verify('secret', str_repeat('a', 3000), null, $ok));
$seen = null;
Turnstile::verify('s3cret', 'tok', '9.9.9.9', function (string $url, array $f) use (&$seen): string { $seen = [$url, $f]; return '{"success":true}'; });
check('Turnstile posts to Cloudflare over https with the secret, token and ip', $seen[0] === Turnstile::VERIFY_URL && str_starts_with($seen[0], 'https://challenges.cloudflare.com/') && $seen[1] === ['secret' => 's3cret', 'response' => 'tok', 'remoteip' => '9.9.9.9']);

// The secret never reaches a response.
[$s, $b] = $post($form(['turnstileToken' => 'forged']), '198.51.100.101');
check('no secret in an error body', !str_contains(json_encode($b), 'secret'));
