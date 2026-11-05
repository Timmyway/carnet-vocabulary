<?php
declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use PDO;

final class WordRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * One page of words plus the total number of matches.
     * Sort "random" is a seeded shuffle: the same seed always gives the same order, so
     * LIMIT/OFFSET pages never repeat or skip a word. A new seed gives a new order.
     * @return array{items: Word[], total: int}
     */
    public function search(string $q, string $tag, string $sort, int $seed, int $limit, int $offset): array
    {
        $where = [];
        $args = [];
        if ($q !== '') {
            $where[] = "(term LIKE :q1 ESCAPE '\\' OR translation LIKE :q2 ESCAPE '\\')";
            $args[':q1'] = $args[':q2'] = '%' . addcslashes($q, '%_\\') . '%';
        }
        if ($tag !== '') {
            $where[] = 'tag = :tag';
            $args[':tag'] = $tag;
        }

        // Multiplicative hash of the id, XOR-ed with the seed (SQLite has no XOR: a ^ b = (a | b) - (a & b)).
        // A bijection on ids, so there are no ties. $seed is an int, so inlining it is safe.
        $hash = '((id * 2654435761) % 4294967296)';
        $order = match ($sort) {
            'az' => 'term COLLATE NOCASE ASC, id ASC',
            'random' => sprintf('((%1$s | %2$d) - (%1$s & %2$d)) ASC, id ASC', $hash, $seed),
            default => 'created_at DESC, id DESC',
        };

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM words' . self::whereSql($where));
        $count->execute($args);

        return [
            'items' => $this->fetch($where, $args, $order, $limit, $offset),
            'total' => (int) $count->fetchColumn(),
        ];
    }

    /** Cards for a practice session: only the due ones, or everything shuffled. @return Word[] */
    public function queue(string $mode, int $limit, string $tag): array
    {
        $where = [];
        $args = [];
        if ($mode === 'due') {
            $where[] = 'next_review_at <= :now';
            $args[':now'] = self::now();
        }
        if ($tag !== '') {
            $where[] = 'tag = :tag';
            $args[':tag'] = $tag;
        }
        $order = $mode === 'due' ? 'box ASC, next_review_at ASC' : 'RANDOM()';
        return $this->fetch($where, $args, $order, $limit);
    }

    public function find(int $id): ?Word
    {
        $st = $this->pdo->prepare('SELECT * FROM words WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ? Word::fromRow($row) : null;
    }

    public function create(array $d): Word
    {
        $now = self::now();
        $st = $this->pdo->prepare(
            'INSERT INTO words (term, translation, example, tag, box, next_review_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, ?, ?, ?)'
        );
        $st->execute([$d['term'], $d['translation'], $d['example'], $d['tag'], $now, $now, $now]);
        return $this->find((int) $this->pdo->lastInsertId());
    }

    public function update(int $id, array $d): ?Word
    {
        $st = $this->pdo->prepare(
            'UPDATE words SET term = ?, translation = ?, example = ?, tag = ?, updated_at = ? WHERE id = ?'
        );
        $st->execute([$d['term'], $d['translation'], $d['example'], $d['tag'], self::now(), $id]);
        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        $st = $this->pdo->prepare('DELETE FROM words WHERE id = ?');
        $st->execute([$id]);
        return $st->rowCount() > 0;
    }

    public function recordReview(Word $word, int $box, DateTimeImmutable $due, bool $correct): Word
    {
        $st = $this->pdo->prepare(
            'UPDATE words SET box = ?, next_review_at = ?, correct_count = correct_count + ?,
             wrong_count = wrong_count + ?, updated_at = ? WHERE id = ?'
        );
        $st->execute([$box, $due->format('Y-m-d H:i:s'), (int) $correct, (int) !$correct, self::now(), $word->id]);
        return $this->find($word->id) ?? $word;
    }

    /**
     * Insert many words in one transaction, skipping terms already in the notebook (case-insensitive).
     * @return array{imported: int, skipped: int}
     */
    public function importMany(array $rows): array
    {
        $imported = 0;
        $skipped = 0;
        $exists = $this->pdo->prepare('SELECT 1 FROM words WHERE term = ? COLLATE NOCASE LIMIT 1');

        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $d) {
                $exists->execute([$d['term']]);
                $found = $exists->fetchColumn() !== false;
                $exists->closeCursor();
                if ($found) {
                    $skipped++;
                    continue;
                }
                $this->create($d);
                $imported++;
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /** @return string[] */
    public function tags(): array
    {
        return $this->pdo->query('SELECT DISTINCT tag FROM words WHERE tag IS NOT NULL ORDER BY tag')
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    public function stats(): array
    {
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM words')->fetchColumn();

        $st = $this->pdo->prepare('SELECT COUNT(*) FROM words WHERE next_review_at <= ?');
        $st->execute([self::now()]);

        $boxes = array_fill(1, 5, 0);
        foreach ($this->pdo->query('SELECT box, COUNT(*) AS c FROM words GROUP BY box') as $r) {
            $boxes[(int) $r['box']] = (int) $r['c'];
        }

        return ['total' => $total, 'due' => (int) $st->fetchColumn(), 'boxes' => array_values($boxes)];
    }

    private function fetch(array $where, array $args, string $order, int $limit, int $offset = 0): array
    {
        // $order, $limit and $offset are internal values (never raw user input), so inlining them is safe.
        $sql = 'SELECT * FROM words' . self::whereSql($where) . " ORDER BY $order LIMIT $limit OFFSET $offset";
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return array_map([Word::class, 'fromRow'], $st->fetchAll());
    }

    private static function whereSql(array $where): string
    {
        return $where ? ' WHERE ' . implode(' AND ', $where) : '';
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
