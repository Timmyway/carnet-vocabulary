<?php
declare(strict_types=1);

return [
    'db_path' => getenv('VOCAB_DB') ?: __DIR__ . '/data/vocab.sqlite',
];
