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
    $stmt = $pdo->query('SELECT id, slug, label FROM courses ORDER BY label');
    return $stmt->fetchAll();
}

/**
 * Fetch protein options from DB.
 *
 * @return array<int, array{ id:int, slug:string, label:string }>
 */
function fetch_protein_options(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, slug, label FROM proteins ORDER BY label');
    return $stmt->fetchAll();
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
    $courseSlugs  = array_column($courses, 'slug');
    $proteinSlugs = array_column($proteins, 'slug');

    $sanitisedCourses = array_values(array_intersect($prefs['courses'] ?? [], $courseSlugs));
    $sanitisedProteins = array_values(array_intersect($prefs['proteins'] ?? [], $proteinSlugs));

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

    if ($userId !== null) {
        $stmt = $pdo->prepare('SELECT filters_json FROM user_preferences WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row && is_string($row['filters_json'])) {
            try {
                $data = json_decode($row['filters_json'], true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                $data = null;
            }
            if (is_array($data)) {
                return sanitise_preferences($data, $courses, $proteins);
            }
        }
    }

    if (!empty($_COOKIE[PREF_COOKIE_NAME])) {
        try {
            $data = json_decode((string)$_COOKIE[PREF_COOKIE_NAME], true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $data = null;
        }
        if (is_array($data)) {
            return sanitise_preferences($data, $courses, $proteins);
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
    $json = json_encode($prefs, JSON_THROW_ON_ERROR);

    if ($userId !== null) {
        $stmt = $pdo->prepare('REPLACE INTO user_preferences (user_id, filters_json, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)');
        $stmt->execute([$userId, $json]);
    } else {
        setcookie(PREF_COOKIE_NAME, $json, [
            'expires'  => time() + PREF_COOKIE_TTL,
            'path'     => '/',
            'secure'   => false,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[PREF_COOKIE_NAME] = $json;
    }
}

?>
