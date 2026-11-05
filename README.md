# Carnet — vocabulary notebook with flashcards

PHP 8.1+ (with `pdo_sqlite`), SQLite, Alpine.js, Tailwind (CDN). No Composer needed.

## Run

    php -S localhost:8000 -t public public/index.php

Open http://localhost:8000. The database is created in `data/vocab.sqlite` on first request
(override with the `VOCAB_DB` env var). On Apache, point the document root to `public/`
(`.htaccess` included); on nginx, route everything that isn't a file to `public/index.php`.

## Layout

    bootstrap.php            PSR-4 autoloader (App\ => src/)
    config.php               settings
    src/Database.php         PDO + versioned migrations (PRAGMA user_version)
    src/Word.php             entity
    src/WordRepository.php   all SQL lives here
    src/SchedulerInterface   how a card's next review date is computed
    src/LeitnerScheduler     5-box Leitner system (0 / 1 / 3 / 7 / 21 days)
    src/ReviewService.php    applies an answer through the scheduler
    src/Http/Router.php      tiny JSON router
    src/Controllers/         WordController, ReviewController
    public/index.php        front controller (wiring + routes)
    public/app.html + assets/app.js   Alpine UI

## API

    GET    /api/words?q=&tag=&sort=newest|az|random&seed=&limit=50&offset=0   paginated list, returns {items, total}
    POST   /api/words                  {term, translation, example?, tag?}
    POST   /api/words/import           {items: [{term, translation, example?, tag?}, ...]} (max 500, skips duplicate terms)
    PUT    /api/words/{id}
    DELETE /api/words/{id}
    GET    /api/tags
    GET    /api/stats
    GET    /api/review/queue?mode=due|all&limit=20&tag=
    POST   /api/review/{id}            {correct: true|false}

## Extending

- New column/table: append an entry to `Database::migrations()` (never edit old ones).
- Better spaced repetition (e.g. SM-2): implement `SchedulerInterface`, swap it in `public/index.php`.
- Multiple users / decks: add `users` / `decks` tables and a `deck_id` filter in `WordRepository`.
- Past ~100k words: switch the `LIKE` search to SQLite FTS5 (one new migration).
- No authentication: meant for local or single-user use. Put it behind auth before exposing it.
