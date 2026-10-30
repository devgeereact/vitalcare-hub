<?php

// Copy to ~/vitalcare-private/config/prod.php (outside every webroot, mode 0600). Never commit the real file.
// Names and placeholders only here.
return [
    // Runtime user: data rights only.
    'db' => [
        'dsn' => 'mysql:host=localhost;dbname=DATABASE_NAME;charset=utf8mb4',
        'user' => 'RUNTIME_USER',
        'password' => 'SET_ON_THE_SERVER',
    ],
    // Migration user: schema rights, used only by bin/migrate.php.
    'migrate' => [
        'dsn' => 'mysql:host=localhost;dbname=DATABASE_NAME;charset=utf8mb4',
        'user' => 'MIGRATION_USER',
        'password' => 'SET_ON_THE_SERVER',
    ],
    // Where contact-form enquiries are queued for.
    'office_email' => 'info@vitalcare.uk',
    // Cloudflare Turnstile secret key. Without it the contact form answers 503 (fails closed).
    // 'dev_bypass' skips the check for LOCAL development only; never set it in production.
    'turnstile' => ['secret' => 'SET_ON_THE_SERVER', 'dev_bypass' => false],
    'clerk' => [
        'issuer' => 'https://clerk.vitalcare.uk',
        'authorized_parties' => ['https://vitalcare.uk', 'https://local.vitalcare.uk'],
    ],
];
