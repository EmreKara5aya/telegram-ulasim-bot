<?php
declare(strict_types=1);

function placesStorageBaseDir(): string
{
    return __DIR__ . '/state/places';
}

function placesEnsureStorageDir(): void
{
    $dir = placesStorageBaseDir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
}

function placesStoragePath(string $chatId): string
{
    $normalized = preg_replace('/[^0-9A-Za-z_-]+/', '_', $chatId) ?? $chatId;
    return placesStorageBaseDir() . '/places_' . $normalized . '.json';
}

function placesLoad(string $chatId): array
{
    $path = placesStoragePath($chatId);
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
            && isset($row['id'], $row['name'], $row['latitude'], $row['longitude'])
            && $row['name'] !== '';
    }));
}

function placesSave(string $chatId, array $places): void
{
    placesEnsureStorageDir();
    $payload = json_encode(array_values($places), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($payload === false) {
        return;
    }

    @file_put_contents(placesStoragePath($chatId), $payload, LOCK_EX);
}

function placesGenerateId(): string
{
    try {
        return 'pl_' . bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        return 'pl_' . substr(md5((string)microtime(true)), 0, 8);
    }
}

function placesSanitizeName(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/\s+/', ' ', $name) ?? $name;
    if (strlen($name) > 60) {
        $name = substr($name, 0, 60);
    }
    return $name;
}

function placesAdd(string $chatId, string $name, float $lat, float $lng): array
{
    $name = placesSanitizeName($name);
    if ($name === '') {
        return ['status' => 'invalid'];
    }

    $places = placesLoad($chatId);
    $now = date('c');
    $place = [
        'id' => placesGenerateId(),
        'name' => $name,
        'latitude' => $lat,
        'longitude' => $lng,
        'created_at' => $now,
        'updated_at' => $now,
    ];
    $places[] = $place;
    placesSave($chatId, $places);

    return ['status' => 'created', 'place' => $place];
}

function placesUpdate(string $chatId, string $placeId, array $updates): bool
{
    $places = placesLoad($chatId);
    foreach ($places as $index => $item) {
        if (($item['id'] ?? '') === $placeId) {
            $places[$index] = array_merge($item, $updates, ['updated_at' => date('c')]);
            placesSave($chatId, $places);
            return true;
        }
    }
    return false;
}

function placesDelete(string $chatId, string $placeId): bool
{
    $places = placesLoad($chatId);
    $filtered = array_values(array_filter($places, static function ($item) use ($placeId): bool {
        return ($item['id'] ?? '') !== $placeId;
    }));

    if (count($filtered) === count($places)) {
        return false;
    }

    placesSave($chatId, $filtered);
    return true;
}

function placesFind(string $chatId, string $placeId): ?array
{
    foreach (placesLoad($chatId) as $place) {
        if (($place['id'] ?? '') === $placeId) {
            return $place;
        }
    }
    return null;
}
