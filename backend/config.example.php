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
    'clerk' => [
        'issuer' => 'https://clerk.vitalcare.uk',
        'authorized_parties' => ['https://vitalcare.uk', 'https://local.vitalcare.uk'],
    ],
];
