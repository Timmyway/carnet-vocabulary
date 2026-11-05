<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\HttpException;
use App\ReviewService;
use App\Word;
use App\WordRepository;

final class ReviewController
{
    public function __construct(private WordRepository $words, private ReviewService $reviews)
    {
    }

    public function queue(array $p, array $b, array $q): array
    {
        $mode = ($q['mode'] ?? 'due') === 'all' ? 'all' : 'due';
        $limit = max(1, min(100, (int) ($q['limit'] ?? 20)));
        $cards = $this->words->queue($mode, $limit, (string) ($q['tag'] ?? ''));
        return [200, array_map(fn (Word $w) => $w->toArray(), $cards)];
    }

    public function answer(array $p, array $b): array
    {
        if (!isset($b['correct']) || !is_bool($b['correct'])) {
            throw new HttpException(422, '"correct" must be true or false.');
        }
        return [200, $this->reviews->answer($p['id'], $b['correct'])->toArray()];
    }

    public function stats(): array
    {
        return [200, $this->words->stats()];
    }
}
