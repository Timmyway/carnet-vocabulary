<?php
declare(strict_types=1);

use App\Controllers\{ReviewController, WordController};
use App\Http\Router;
use App\{Database, LeitnerScheduler, ReviewService, WordRepository};

require __DIR__ . '/../bootstrap.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// PHP's built-in server: let it serve static files (assets/app.js) itself.
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path) && !str_ends_with($path, '.php')) {
    return false;
}

if ($path === '/') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/app.html');
    exit;
}

if (!str_starts_with($path, '/api/')) {
    http_response_code(404);
    exit('Not found');
}

try {
    $config = require __DIR__ . '/../config.php';
    $words = new WordRepository(Database::connect($config['db_path']));
    $words_ctl = new WordController($words);
    $review_ctl = new ReviewController($words, new ReviewService($words, new LeitnerScheduler()));

    (new Router())
        ->add('GET', '/api/words', [$words_ctl, 'index'])
        ->add('POST', '/api/words', [$words_ctl, 'store'])
        ->add('POST', '/api/words/import', [$words_ctl, 'import'])
        ->add('PUT', '/api/words/{id}', [$words_ctl, 'update'])
        ->add('DELETE', '/api/words/{id}', [$words_ctl, 'destroy'])
        ->add('GET', '/api/tags', [$words_ctl, 'tags'])
        ->add('GET', '/api/stats', [$review_ctl, 'stats'])
        ->add('GET', '/api/review/queue', [$review_ctl, 'queue'])
        ->add('POST', '/api/review/{id}', [$review_ctl, 'answer'])
        ->handle();
} catch (Throwable $e) {
    error_log((string) $e);
    http_response_code(500);
    header('Content-Type: application/json');
    echo '{"error":"Server error."}';
}
