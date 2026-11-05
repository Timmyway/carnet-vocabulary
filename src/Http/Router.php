<?php
declare(strict_types=1);

namespace App\Http;

/** Tiny JSON router. Handlers receive (array $params, array $body, array $query) and return [status, data]. */
final class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): self
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>\d+)', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler];
        return $this;
    }

    public function handle(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        try {
            [$status, $data] = $this->dispatch(
                $_SERVER['REQUEST_METHOD'] ?? 'GET',
                rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/')
            );
        } catch (HttpException $e) {
            [$status, $data] = [$e->status, ['error' => $e->getMessage()]];
        } catch (\Throwable $e) {
            error_log((string) $e);
            [$status, $data] = [500, ['error' => 'Server error.']];
        }

        http_response_code($status);
        if ($status !== 204) {
            echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    private function dispatch(string $method, string $path): array
    {
        $pathMatched = false;
        foreach ($this->routes as [$m, $regex, $handler]) {
            if (!preg_match($regex, $path, $match)) {
                continue;
            }
            $pathMatched = true;
            if ($m !== $method) {
                continue;
            }
            $params = array_map('intval', array_filter($match, 'is_string', ARRAY_FILTER_USE_KEY));
            return $handler($params, $this->body(), $_GET);
        }
        throw $pathMatched
            ? new HttpException(405, 'Method not allowed.')
            : new HttpException(404, 'Not found.');
    }

    private function body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new HttpException(400, 'Invalid JSON body.');
        }
        return $data;
    }
}
