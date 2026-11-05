<?php
declare(strict_types=1);

namespace App;

use DateTimeImmutable;

/** Leitner boxes: a right answer moves the card up a box (longer wait), a wrong one sends it back to box 1. */
final class LeitnerScheduler implements SchedulerInterface
{
    private const DAYS = [1 => 0, 2 => 1, 3 => 3, 4 => 7, 5 => 21];

    public function schedule(int $currentBox, bool $correct, DateTimeImmutable $now): array
    {
        $box = $correct ? min(5, $currentBox + 1) : 1;
        return ['box' => $box, 'due' => $now->modify('+' . self::DAYS[$box] . ' days')];
    }
}
