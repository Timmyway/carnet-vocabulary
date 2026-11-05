<?php
declare(strict_types=1);

namespace App;

use App\Http\HttpException;
use DateTimeImmutable;
use DateTimeZone;

final class ReviewService
{
    public function __construct(private WordRepository $words, private SchedulerInterface $scheduler)
    {
    }

    public function answer(int $id, bool $correct): Word
    {
        $word = $this->words->find($id) ?? throw new HttpException(404, 'Word not found.');
        $next = $this->scheduler->schedule($word->box, $correct, new DateTimeImmutable('now', new DateTimeZone('UTC')));
        return $this->words->recordReview($word, $next['box'], $next['due'], $correct);
    }
}
