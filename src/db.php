<?php

declare(strict_types=1);



// Database connection (SQLite)

$dsn = 'sqlite:' . __DIR__ . '/../storage/database.sqlite';

$options = [

    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,

    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

    PDO::ATTR_EMULATE_PREPARES   => false,

];



$pdo = new PDO($dsn, null, null, $options);



// Always enforce relational integrity

$pdo->exec('PRAGMA foreign_keys = ON');



// --- Minimal migrations ---

// This guarantees the core tables exist even on a fresh DB.

// You can expand these later as we add features.

function migrate(PDO $pdo): void {

    // Courses (mains/appetisers)

    $pdo->exec('CREATE TABLE IF NOT EXISTS courses (

        id     INTEGER PRIMARY KEY AUTOINCREMENT,

        slug   TEXT NOT NULL UNIQUE CHECK (slug IN ("mains","appetisers")),

        label  TEXT NOT NULL

    )');



    // Items (menu dishes)

    $pdo->exec('CREATE TABLE IF NOT EXISTS items (

        id          INTEGER PRIMARY KEY AUTOINCREMENT,

        name        TEXT NOT NULL UNIQUE,

        description TEXT,

        course_id   INTEGER NOT NULL,

        base_spice  INTEGER NOT NULL DEFAULT 0 CHECK (base_spice BETWEEN 0 AND 5),

        image_path  TEXT,

        enabled     INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0,1)),

        created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

        updated_at  TEXT,

        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE RESTRICT

    )');



    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_items_course_enabled ON items(course_id, enabled)');



    // Proteins (vegan/vegetarian/pork/etc.)

    $pdo->exec('CREATE TABLE IF NOT EXISTS proteins (

        id    INTEGER PRIMARY KEY AUTOINCREMENT,

        slug  TEXT NOT NULL UNIQUE CHECK (slug IN ("vegan","vegetarian","pork","seafood","chicken","beef")),

        label TEXT NOT NULL

    )');



    // Item ↔ Protein links

    $pdo->exec('CREATE TABLE IF NOT EXISTS item_allowed_proteins (

        item_id    INTEGER NOT NULL,

        protein_id INTEGER NOT NULL,

        PRIMARY KEY (item_id, protein_id),

        FOREIGN KEY (item_id)    REFERENCES items(id)    ON DELETE CASCADE,

        FOREIGN KEY (protein_id) REFERENCES proteins(id) ON DELETE RESTRICT

    )');



    // Seed lookup tables

    $pdo->exec('INSERT OR IGNORE INTO courses(slug,label) VALUES ("mains","Mains"),("appetisers","Appetisers")');

    $pdo->exec('INSERT OR IGNORE INTO proteins(slug,label) VALUES

        ("vegan","Vegan"),

        ("vegetarian","Vegetarian"),

        ("pork","Pork"),

        ("seafood","Seafood"),

        ("chicken","Chicken"),

        ("beef","Beef")');

}



// Run migrations

migrate($pdo);


