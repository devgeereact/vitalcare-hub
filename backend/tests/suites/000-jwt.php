<?php

declare(strict_types=1);

// Clerk token verification (ported from the proven siterelay check, plus attacks written for this project).
use Vitalcare\{HttpError, Jwt};

const ISS = 'https://clerk.vitalcare.uk';
const AZP = 'https://vitalcare.uk';

function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

function new_key(): array
{
    $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $d = openssl_pkey_get_details($k);
    $jwk = ['kty' => 'RSA', 'n' => b64u($d['rsa']['n']), 'e' => b64u($d['rsa']['e'])];
    return [$k, $jwk];
}

function sign_token(array $header, array $claims, $key, string $alg = 'RS256'): string
{
    $h = b64u(json_encode($header));
    $p = b64u(json_encode($claims));
    openssl_sign("$h.$p", $sig, $key, OPENSSL_ALGO_SHA256);
    return "$h.$p." . b64u($sig);
}

$now = 1_900_000_000;
[$key, $jwk] = new_key();
[$otherKey, $otherJwk] = new_key();
$jwk['kid'] = 'kid-1';
$otherJwk['kid'] = 'kid-2';
$tmp = dirname(__DIR__, 2) . '/../.local-test/tmp';
@mkdir($tmp, 0700, true);

$fetches = 0;
$served = [$jwk];
$fetch = function (string $url) use (&$fetches, &$served): string {
    $fetches++;
    check('JWKS is fetched from the issuer well-known path', $url === ISS . '/.well-known/jwks.json', $url);
    return json_encode(['keys' => $served]);
};
function jwt_for(string $cache, callable $fetch, int $now, array $parties = [AZP]): Jwt
{
    return new Jwt(ISS, $parties, $cache, $fetch, $now);
}
function good_claims(int $now, array $over = []): array
{
    return array_merge(['iss' => ISS, 'sub' => 'user_abc', 'azp' => AZP, 'exp' => $now + 60, 'nbf' => $now - 5, 'email' => 'a@example.com'], $over);
}
$hdr = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'kid-1'];

$cache = "$tmp/jwt-" . bin2hex(random_bytes(4));
mkdir($cache, 0700);
$j = jwt_for($cache, $fetch, $now);

// Accepts a valid token and returns its claims.
$claims = $j->verify(sign_token($hdr, good_claims($now), $key));
check('valid token verifies', ($claims['sub'] ?? '') === 'user_abc');
check('second verify uses the cached key set', (function () use ($j, $hdr, $now, $key, &$fetches) {
    $before = $fetches;
    $j->verify(sign_token($hdr, good_claims($now), $key));
    return $fetches === $before;
})());

// Rejects everything else, always as 401.
$bad = function (string $label, string $token, ?Jwt $with = null) use ($j): void {
    expectError($label, fn () => ($with ?? $j)->verify($token), 401);
};
$bad('empty string', '');
$bad('not three parts', 'a.b');
$bad('four parts', 'a.b.c.d');
$bad('oversized token', str_repeat('a', 9000) . '.b.c');
$bad('garbage', 'not.a.token');
$bad('signed by a different key', sign_token($hdr, good_claims($now), $otherKey));
$bad('payload tampered after signing', (function () use ($hdr, $now, $key) {
    [$h, , $s] = explode('.', sign_token($hdr, good_claims($now), $key));
    return "$h." . b64u(json_encode(good_claims($now, ['sub' => 'user_admin']))) . ".$s";
})());
$bad('alg none', b64u(json_encode(['alg' => 'none', 'kid' => 'kid-1'])) . '.' . b64u(json_encode(good_claims($now))) . '.');
$bad('alg HS256 using the public key as secret', (function () use ($jwk, $now) {
    $h = b64u(json_encode(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => 'kid-1']));
    $p = b64u(json_encode(good_claims($now)));
    return "$h.$p." . b64u(hash_hmac('sha256', "$h.$p", Vitalcare\Jwt::jwkToPem($jwk), true));
})());
$bad('alg RS512 header', sign_token(['alg' => 'RS512', 'kid' => 'kid-1'], good_claims($now), $key));
$bad('missing kid', sign_token(['alg' => 'RS256'], good_claims($now), $key));
$bad('unknown kid', sign_token(['alg' => 'RS256', 'kid' => 'nope'], good_claims($now), $key));
$bad('expired', sign_token($hdr, good_claims($now, ['exp' => $now - 60]), $key));
$bad('exp missing', sign_token($hdr, array_diff_key(good_claims($now), ['exp' => 1]), $key));
$bad('exp as string', sign_token($hdr, good_claims($now, ['exp' => (string) ($now + 60)]), $key));
$bad('not yet valid (nbf in the future)', sign_token($hdr, good_claims($now, ['nbf' => $now + 600]), $key));
$bad('wrong issuer', sign_token($hdr, good_claims($now, ['iss' => 'https://evil.example']), $key));
$bad('issuer with trailing slash', sign_token($hdr, good_claims($now, ['iss' => ISS . '/']), $key));
$bad('missing sub', sign_token($hdr, array_diff_key(good_claims($now), ['sub' => 1]), $key));
$bad('empty sub', sign_token($hdr, good_claims($now, ['sub' => '']), $key));
$bad('azp not allowed', sign_token($hdr, good_claims($now, ['azp' => 'https://evil.example']), $key));
$bad('azp missing', sign_token($hdr, array_diff_key(good_claims($now), ['azp' => 1]), $key));
$bad('azp only differs by scheme', sign_token($hdr, good_claims($now, ['azp' => 'http://vitalcare.uk']), $key));

// Clock skew: 5 seconds of leeway and no more.
check('expired by 4 seconds still passes (leeway)', ($j->verify(sign_token($hdr, good_claims($now, ['exp' => $now - 4]), $key))['sub'] ?? '') === 'user_abc');
$bad('expired by 10 seconds is rejected', sign_token($hdr, good_claims($now, ['exp' => $now - 10]), $key));

// Authorised parties: both live and local-live origins when configured.
$jTwo = jwt_for($cache, $fetch, $now, [AZP, 'https://local.vitalcare.uk']);
check('local-live origin accepted when configured', ($jTwo->verify(sign_token($hdr, good_claims($now, ['azp' => 'https://local.vitalcare.uk']), $key))['sub'] ?? '') === 'user_abc');
$bad('local-live origin rejected when not configured', sign_token($hdr, good_claims($now, ['azp' => 'https://local.vitalcare.uk']), $key));

// Key rotation: an unknown kid triggers one refresh, then the new key is accepted.
$cache2 = "$tmp/jwt-" . bin2hex(random_bytes(4));
mkdir($cache2, 0700);
$served = [$jwk];
$j2 = new Jwt(ISS, [AZP], $cache2, $fetch, $now);
$j2->verify(sign_token($hdr, good_claims($now), $key));
$served = [$jwk, $otherJwk];
$rot = new Jwt(ISS, [AZP], $cache2, $fetch, $now + 120); // 2 minutes later: refresh allowed
check('rotated key accepted after a refresh', ($rot->verify(sign_token(['alg' => 'RS256', 'kid' => 'kid-2'], good_claims($now + 120), $otherKey))['sub'] ?? '') === 'user_abc');

// An attacker cannot make the server hammer the issuer: repeated unknown kids refresh at most once a minute.
$cache3 = "$tmp/jwt-" . bin2hex(random_bytes(4));
mkdir($cache3, 0700);
$served = [$jwk];
$j3 = new Jwt(ISS, [AZP], $cache3, $fetch, $now);
$j3->verify(sign_token($hdr, good_claims($now), $key));
$before = $fetches;
for ($i = 0; $i < 5; $i++) {
    try { $j3->verify(sign_token(['alg' => 'RS256', 'kid' => 'spam' . $i], good_claims($now), $key)); } catch (HttpError) {}
}
check('unknown-kid spam does not refetch the key set', $fetches === $before, "fetches +" . ($fetches - $before));

// Issuer unreachable and no cache: fail closed.
$jDown = new Jwt(ISS, [AZP], "$tmp/jwt-" . bin2hex(random_bytes(4)), function (): string { throw new RuntimeException('down'); }, $now);
@mkdir("$tmp/jwt-down", 0700);
expectError('issuer unreachable and nothing cached: fails closed', fn () => $jDown->verify(sign_token($hdr, good_claims($now), $key)), 401);

// Constructor refuses an unsafe issuer.
foreach (['http://clerk.vitalcare.uk', 'https://clerk.vitalcare.uk/'] as $badIss) {
    try {
        new Jwt($badIss, [AZP], $cache, $fetch, $now);
        check("issuer $badIss refused", false);
    } catch (InvalidArgumentException) {
        check("issuer $badIss refused", true);
    }
}
