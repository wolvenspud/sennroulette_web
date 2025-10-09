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
        if (db_table_exists($pdo, 'user_preferences')) {
            try {
                $stmt = $pdo->prepare('SELECT filters_json FROM user_preferences WHERE user_id = ?');
                if ($stmt !== false && $stmt->execute([$userId])) {
                    $row = $stmt->fetch();
                    if ($row && isset($row['filters_json']) && is_string($row['filters_json'])) {
                        $decoded = $decode($row['filters_json']);
                        if ($decoded !== null) {
                            return $decoded;
                        }
                    }
                }
            } catch (Throwable $e) {
                // Fall through to legacy handling / defaults.
            }
        } elseif (db_table_exists($pdo, 'user_prefs')) {
            try {
                $stmt = $pdo->prepare('SELECT spice_tolerance, course_choice FROM user_prefs WHERE user_id = ?');
                if ($stmt !== false && $stmt->execute([$userId])) {
                    $row = $stmt->fetch();
                    if ($row) {
                        $courseChoice = isset($row['course_choice']) ? strtolower((string)$row['course_choice']) : 'both';
                        $legacyCourses = [];
                        if ($courseChoice === 'mains' || $courseChoice === 'main') {
                            $legacyCourses = ['mains'];
                        } elseif ($courseChoice === 'appetisers' || $courseChoice === 'appetizers' || $courseChoice === 'appetiser' || $courseChoice === 'appetizer') {
                            $legacyCourses = ['appetisers'];
                        }

                        $legacyProteins = [];
                        if (db_table_exists($pdo, 'user_allowed_proteins') && db_table_exists($pdo, 'proteins')) {
                            $protStmt = $pdo->prepare('SELECT p.slug FROM user_allowed_proteins uap INNER JOIN proteins p ON p.id = uap.protein_id WHERE uap.user_id = ?');
                            if ($protStmt !== false && $protStmt->execute([$userId])) {
                                foreach ($protStmt->fetchAll() as $protRow) {
                                    if (!empty($protRow['slug'])) {
                                        $legacyProteins[] = (string)$protRow['slug'];
                                    }
                                }
                            }
                        }

                        $raw = [
                            'courses'   => $legacyCourses,
                            'proteins'  => $legacyProteins,
                            'max_spice' => isset($row['spice_tolerance']) && is_numeric($row['spice_tolerance']) ? (int)$row['spice_tolerance'] : 5,
                        ];

                        $decoded = sanitise_preferences($raw, $courses, $proteins);
                        if (!empty($decoded)) {
                            return $decoded;
                        }
                    }
                }
            } catch (Throwable $e) {
                // Ignore and fall back to cookie/defaults.
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
        if (db_table_exists($pdo, 'user_preferences')) {
            $stmt = $pdo->prepare('REPLACE INTO user_preferences (user_id, filters_json, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)');
            if ($stmt === false) {
                throw new RuntimeException('Unable to prepare preference persistence statement.');
            }
            $stmt->execute([$userId, $json]);
            return;
        }

        if (db_table_exists($pdo, 'user_prefs')) {
            $courseSlugs = array_map('strval', array_column($courses, 'slug'));
            $normalisedSelection = array_values(array_intersect($prefs['courses'], $courseSlugs));
            sort($normalisedSelection);

            $courseChoice = 'both';
            if (count($normalisedSelection) === 1) {
                $single = $normalisedSelection[0];
                if ($single === 'mains' || $single === 'main') {
                    $courseChoice = 'mains';
                } elseif ($single === 'appetisers' || $single === 'appetizers') {
                    $courseChoice = 'appetisers';
                }
            }

            $maxSpice = (int)$prefs['max_spice'];

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('INSERT INTO user_prefs (user_id, spice_tolerance, course_choice) VALUES (?, ?, ?)
                    ON CONFLICT(user_id) DO UPDATE SET spice_tolerance = excluded.spice_tolerance, course_choice = excluded.course_choice');
                if ($stmt === false) {
                    throw new RuntimeException('Unable to prepare legacy preference upsert.');
                }
                $stmt->execute([$userId, $maxSpice, $courseChoice]);

                if (db_table_exists($pdo, 'user_allowed_proteins')) {
                    $deleteStmt = $pdo->prepare('DELETE FROM user_allowed_proteins WHERE user_id = ?');
                    if ($deleteStmt === false) {
                        throw new RuntimeException('Unable to prepare protein reset statement.');
                    }
                    $deleteStmt->execute([$userId]);

                    if (!empty($prefs['proteins']) && db_table_exists($pdo, 'proteins')) {
                        $proteinIdMap = [];
                        foreach ($proteins as $protein) {
                            if (!isset($protein['slug'], $protein['id'])) {
                                continue;
                            }
                            $proteinIdMap[(string)$protein['slug']] = (int)$protein['id'];
                        }

                        $insertStmt = $pdo->prepare('INSERT OR IGNORE INTO user_allowed_proteins (user_id, protein_id) VALUES (?, ?)');
                        if ($insertStmt === false) {
                            throw new RuntimeException('Unable to prepare protein insert statement.');
                        }

                        foreach ($prefs['proteins'] as $slug) {
                            if (!isset($proteinIdMap[$slug])) {
                                continue;
                            }
                            $insertStmt->execute([$userId, $proteinIdMap[$slug]]);
                        }
                    }
                }

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            return;
        }

        // If no recognised user preference tables exist, fall back to cookies.
    }

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

?>
