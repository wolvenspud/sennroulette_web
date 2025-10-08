<?php

declare(strict_types=1);

const PREF_COOKIE_NAME = 'sennroulette_preferences';
const PREF_COOKIE_TTL = 31536000; // 1 year

/**
 * Fetch course options from DB.
 *
 * @return array<int, array{ id:int, slug:string, label:string }>
 */
function fetch_course_options(PDO $pdo): array
{
    if (!db_table_exists($pdo, 'courses')) {
        return [];
    }

    try {
        $stmt = $pdo->query('SELECT id, slug, label FROM courses ORDER BY label');
    } catch (Throwable $e) {
        return [];
    }

    if ($stmt === false) {
        return [];
    }

    $options = [];
    $seen = [];

    foreach ($stmt->fetchAll() as $row) {
        if (empty($row['slug']) || isset($seen[$row['slug']])) {
            continue;
        }

        $slug = (string)$row['slug'];
        $seen[$slug] = true;

        $options[] = [
            'id'    => isset($row['id']) ? (int)$row['id'] : count($options),
            'slug'  => $slug,
            'label' => isset($row['label']) && $row['label'] !== '' ? (string)$row['label'] : $slug,
        ];
    }

    return $options;
}

/**
 * Fetch protein options from DB.
 *
 * @return array<int, array{ id:int, slug:string, label:string }>
 */
function fetch_protein_options(PDO $pdo): array
{
    if (!db_table_exists($pdo, 'proteins')) {
        return [];
    }

    try {
        $stmt = $pdo->query('SELECT id, slug, label FROM proteins ORDER BY label');
    } catch (Throwable $e) {
        return [];
    }

    if ($stmt === false) {
        return [];
    }

    $options = [];
    $seen = [];

    foreach ($stmt->fetchAll() as $row) {
        if (empty($row['slug']) || isset($seen[$row['slug']])) {
            continue;
        }

        $slug = (string)$row['slug'];
        $seen[$slug] = true;

        $options[] = [
            'id'    => isset($row['id']) ? (int)$row['id'] : count($options),
            'slug'  => $slug,
            'label' => isset($row['label']) && $row['label'] !== '' ? (string)$row['label'] : $slug,
        ];
    }

    return $options;
}

/**
 * Build the default preferences (all options selected).
 */
function default_preferences(array $courses, array $proteins): array
{
    return [
        'courses'   => array_values(array_column($courses, 'slug')),
        'proteins'  => array_values(array_column($proteins, 'slug')),
        'max_spice' => 5,
    ];
}

/**
 * Ensure the provided preferences contain only known values.
 */
function sanitise_preferences(array $prefs, array $courses, array $proteins): array
{
    $courseSlugs  = array_map('strval', array_column($courses, 'slug'));
    $proteinSlugs = array_map('strval', array_column($proteins, 'slug'));

    $rawCourses = isset($prefs['courses']) && is_array($prefs['courses']) ? array_map('strval', $prefs['courses']) : [];
    $rawProteins = isset($prefs['proteins']) && is_array($prefs['proteins']) ? array_map('strval', $prefs['proteins']) : [];

    $sanitisedCourses = array_values(array_intersect($rawCourses, $courseSlugs));
    $sanitisedProteins = array_values(array_intersect($rawProteins, $proteinSlugs));

    $maxSpice = $prefs['max_spice'] ?? 5;
    if (!is_numeric($maxSpice)) {
        $maxSpice = 5;
    }
    $maxSpice = max(0, min(5, (int)$maxSpice));

    if (empty($sanitisedCourses)) {
        $sanitisedCourses = $courseSlugs;
    }
    if (empty($sanitisedProteins)) {
        $sanitisedProteins = $proteinSlugs;
    }

    return [
        'courses'   => $sanitisedCourses,
        'proteins'  => $sanitisedProteins,
        'max_spice' => $maxSpice,
    ];
}

/**
 * Load preferences from DB or cookie.
 */
function load_preferences(PDO $pdo, ?int $userId, array $courses, array $proteins): array
{
    $defaults = default_preferences($courses, $proteins);

    $decode = static function (string $json) use ($courses, $proteins): ?array {
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return null;
        }

        return sanitise_preferences($data, $courses, $proteins);
    };

    if ($userId !== null) {
        $stmt = $pdo->prepare('SELECT filters_json FROM user_preferences WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row && is_string($row['filters_json'])) {
            $decoded = $decode($row['filters_json']);
            if ($decoded !== null) {
                return $decoded;
            }
        }
    }

    if (!empty($_COOKIE[PREF_COOKIE_NAME]) && is_string($_COOKIE[PREF_COOKIE_NAME])) {
        $decoded = $decode($_COOKIE[PREF_COOKIE_NAME]);
        if ($decoded !== null) {
            return $decoded;
        }
    }

    return $defaults;
}

/**
 * Persist preferences either to DB (signed-in users) or to a cookie.
 */
function persist_preferences(PDO $pdo, ?int $userId, array $prefs, array $courses, array $proteins): void
{
    $prefs = sanitise_preferences($prefs, $courses, $proteins);
    $json = json_encode($prefs, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Unable to encode preferences to JSON.');
    }

    if ($userId !== null) {
        $stmt = $pdo->prepare('REPLACE INTO user_preferences (user_id, filters_json, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)');
        $stmt->execute([$userId, $json]);
    } else {
        setcookie(
            PREF_COOKIE_NAME,
            $json,
            time() + PREF_COOKIE_TTL,
            '/',
            '',
            false,
            false
        );
        $_COOKIE[PREF_COOKIE_NAME] = $json;
    }
}

?>
