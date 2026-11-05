<?php
declare(strict_types=1);

namespace App;

final class Word
{
    public function __construct(
        public readonly int $id,
        public readonly string $term,
        public readonly string $translation,
        public readonly ?string $example,
        public readonly ?string $tag,
        public readonly int $box,
        public readonly string $nextReviewAt,
        public readonly int $correctCount,
        public readonly int $wrongCount,
    ) {
    }

    public static function fromRow(array $r): self
    {
        return new self(
            (int) $r['id'], $r['term'], $r['translation'], $r['example'], $r['tag'],
            (int) $r['box'], $r['next_review_at'], (int) $r['correct_count'], (int) $r['wrong_count'],
        );
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
