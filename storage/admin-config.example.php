<?php
declare(strict_types=1);

// Скопируйте файл как admin-config.php и подставьте результат:
// php -r "echo password_hash('ВАШ_ДЛИННЫЙ_ПАРОЛЬ', defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT), PHP_EOL;"
return [
    'password_hash' => 'REPLACE_WITH_PASSWORD_HASH',
    'updated_at' => '',
];
