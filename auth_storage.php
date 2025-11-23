<?php
declare(strict_types=1);

function authStorageDir(): string
{
    return __DIR__ . '/state';
}

function authStoragePath(): string
{
    return authStorageDir() . '/auth_users.json';
}

function authEnsureStorageDir(): void
{
    $dir = authStorageDir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
}

function authSanitizeName(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    if (strlen($value) > 80) {
        $value = substr($value, 0, 80);
    }

    return $value;
}

function authSanitizeTelegramId(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (!preg_match('/^\d+$/', $value)) {
        return '';
    }

    return $value;
}

function authLoadUsers(): array
{
    $path = authStoragePath();
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $json = file_get_contents($path);
    if ($json === false) {
        return [];
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        return [];
    }

    return array_values(array_filter($data, static function ($row): bool {
        return is_array($row)
            && isset($row['name'], $row['telegram_id'])
            && authSanitizeName((string)$row['name']) !== ''
            && authSanitizeTelegramId((string)$row['telegram_id']) !== '';
    }));
}

function authSaveUsers(array $users): void
{
    authEnsureStorageDir();
    $payload = json_encode(array_values($users), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($payload === false) {
        return;
    }
    @file_put_contents(authStoragePath(), $payload, LOCK_EX);
}

function authUpsertUser(string $telegramId, string $name): string
{
    $telegramId = authSanitizeTelegramId($telegramId);
    $name = authSanitizeName($name);
    if ($telegramId === '' || $name === '') {
        return 'invalid';
    }

    $users = authLoadUsers();
    $now = date('c');

    foreach ($users as $index => $user) {
        if (($user['telegram_id'] ?? '') === $telegramId) {
            $users[$index]['name'] = $name;
            if (isset($users[$index]['created_at'])) {
                $users[$index]['created_at'] = (string)$users[$index]['created_at'];
            } else {
                $users[$index]['created_at'] = $now;
            }
            $users[$index]['updated_at'] = $now;
            authSaveUsers($users);
            return 'updated';
        }
    }

    $users[] = [
        'name' => $name,
        'telegram_id' => $telegramId,
        'created_at' => $now,
    ];
    authSaveUsers($users);

    return 'created';
}

function authDeleteUser(string $telegramId): bool
{
    $telegramId = authSanitizeTelegramId($telegramId);
    if ($telegramId === '') {
        return false;
    }

    $users = authLoadUsers();
    $filtered = array_values(array_filter($users, static function ($user) use ($telegramId): bool {
        return ($user['telegram_id'] ?? '') !== $telegramId;
    }));

    if (count($filtered) === count($users)) {
        return false;
    }

    authSaveUsers($filtered);
    return true;
}

function authFindUser(string $telegramId): ?array
{
    $telegramId = authSanitizeTelegramId($telegramId);
    if ($telegramId === '') {
        return null;
    }

    foreach (authLoadUsers() as $user) {
        if (($user['telegram_id'] ?? '') === $telegramId) {
            return $user;
        }
    }

    return null;
}

function authIsUserRegistered(string $telegramId): bool
{
    return authFindUser($telegramId) !== null;
}
