<?php
declare(strict_types=1);

namespace App;

use DateTimeImmutable;

interface SchedulerInterface
{
    /** @return array{box: int, due: DateTimeImmutable} */
    public function schedule(int $currentBox, bool $correct, DateTimeImmutable $now): array;
}
