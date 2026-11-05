<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\HttpException;
use App\Word;
use App\WordRepository;

final class WordController
{
    public function __construct(private WordRepository $words)
    {
    }

    public function index(array $p, array $b, array $q): array
    {
        $limit = max(1, min(200, (int) ($q['limit'] ?? 50)));
        $offset = max(0, (int) ($q['offset'] ?? 0));
        $sort = in_array($q['sort'] ?? '', ['az', 'random'], true) ? $q['sort'] : 'newest';
        $seed = max(0, min(4294967295, (int) ($q['seed'] ?? 0)));

        $page = $this->words->search((string) ($q['q'] ?? ''), (string) ($q['tag'] ?? ''), $sort, $seed, $limit, $offset);
        return [200, [
            'items' => array_map(fn (Word $w) => $w->toArray(), $page['items']),
            'total' => $page['total'],
        ]];
    }

    public function store(array $p, array $b): array
    {
        return [201, $this->words->create($this->validate($b))->toArray()];
    }

    public function update(array $p, array $b): array
    {
        $word = $this->words->update($p['id'], $this->validate($b))
            ?? throw new HttpException(404, 'Word not found.');
        return [200, $word->toArray()];
    }

    public function destroy(array $p): array
    {
        if (!$this->words->delete($p['id'])) {
            throw new HttpException(404, 'Word not found.');
        }
        return [204, null];
    }

    /** Bulk import. Accepts {"items": [...]} or a bare JSON array of {term, translation, example?, tag?}. */
    public function import(array $p, array $b): array
    {
        $items = array_is_list($b) ? $b : ($b['items'] ?? null);
        if (!is_array($items) || $items === []) {
            throw new HttpException(422, 'Expected a non-empty JSON array of words.');
        }
        if (count($items) > 500) {
            throw new HttpException(422, 'Import at most 500 words at a time.');
        }

        $valid = [];
        $errors = [];
        foreach (array_values($items) as $i => $item) {
            try {
                if (!is_array($item)) {
                    throw new HttpException(422, 'Expected an object.');
                }
                $valid[] = $this->validate($item);
            } catch (HttpException $e) {
                $errors[] = 'Item ' . ($i + 1) . ': ' . $e->getMessage();
            }
        }

        return [200, $this->words->importMany($valid) + ['errors' => $errors]];
    }

    public function tags(): array
    {
        return [200, $this->words->tags()];
    }

    private function validate(array $b): array
    {
        $rules = ['term' => [120, true], 'translation' => [255, true], 'example' => [500, false], 'tag' => [40, false]];
        $out = [];
        foreach ($rules as $field => [$max, $required]) {
            $v = isset($b[$field]) && is_string($b[$field]) ? trim($b[$field]) : '';
            if ($required && $v === '') {
                throw new HttpException(422, ucfirst($field) . ' is required.');
            }
            if (mb_strlen($v) > $max) {
                throw new HttpException(422, ucfirst($field) . " must be at most $max characters.");
            }
            $out[$field] = $v === '' ? null : ($field === 'tag' ? mb_strtolower($v) : $v);
        }
        return $out;
    }
}
