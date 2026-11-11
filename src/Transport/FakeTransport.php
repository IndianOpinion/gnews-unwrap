<?php

declare(strict_types=1);

namespace IndianOpinion\GnewsUnwrap\Transport;

use IndianOpinion\GnewsUnwrap\Clock\FakeClock;
use IndianOpinion\GnewsUnwrap\Exception\InvalidConfigException;
use IndianOpinion\GnewsUnwrap\Exception\TransportException;

/**
 * In-memory transport that replays canned responses. For tests and for
 * the bundled examples; never touches the network.
 *
 * Routes are keyed by method and exact URL. Several responses for one
 * route are served in order, and the last one repeats. A request with no
 * route fails with a network error, so a test notices an unexpected call.
 *
 * With a FakeClock, a route can carry a delay (seconds) and a connect
 * delay; a delay longer than the request's timeout throws a timeout and
 * moves the clock by the timeout.
 */
class FakeTransport implements Transport
{
    /** @var array<string, list<array{response: Response|TransportException, delay: float, connectDelay: float}>> */
    private array $routes = [];

    /** @var list<Request> */
    private array $requests = [];

    public function __construct(private readonly ?FakeClock $clock = null)
    {
    }

    public function on(
        string $url,
        Response|TransportException $response,
        string $method = 'GET',
        float $delay = 0.0,
        float $connectDelay = 0.0,
    ): static {
        $this->routes[strtoupper($method) . ' ' . $url][] = [
            'response' => $response,
            'delay' => $delay,
            'connectDelay' => $connectDelay,
        ];

        return $this;
    }

    /**
     * Shortcut for a redirect hop.
     */
    public function redirect(string $from, string $location, int $status = 302): static
    {
        return $this->on($from, new Response($status, ['Location' => $location], '', $from));
    }

    /**
     * Load a JSON fixture:
     * {"exchanges": [{"request": {"method": "GET", "url": "..."},
     *   "response": {"status": 302, "headers": {...}, "body": "...", "body_file": "rel/path",
     *   "delay": 0, "connect_delay": 0, "error": "timeout|network"}}]}
     */
    public function load(string $fixtureFile): static
    {
        $json = file_get_contents($fixtureFile);
        if ($json === false) {
            throw new InvalidConfigException('Cannot read fixture ' . $fixtureFile);
        }
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidConfigException('Fixture is not valid JSON: ' . $fixtureFile, 0, $e);
        }
        if (!is_array($data) || !is_array($data['exchanges'] ?? null)) {
            throw new InvalidConfigException('Fixture has no exchanges: ' . $fixtureFile);
        }
        $dir = dirname($fixtureFile);
        foreach ($data['exchanges'] as $exchange) {
            if (!is_array($exchange) || !is_array($exchange['request'] ?? null) || !is_array($exchange['response'] ?? null)) {
                throw new InvalidConfigException('Malformed exchange in ' . $fixtureFile);
            }
            $req = $exchange['request'];
            $res = $exchange['response'];
            $url = (string) ($req['url'] ?? '');
            $method = (string) ($req['method'] ?? 'GET');
            if (isset($res['error'])) {
                $response = $res['error'] === 'timeout'
                    ? TransportException::timeout('fixture timeout')
                    : TransportException::network('fixture network error');
            } else {
                $body = (string) ($res['body'] ?? '');
                if (isset($res['body_file'])) {
                    $content = file_get_contents($dir . '/' . $res['body_file']);
                    if ($content === false) {
                        throw new InvalidConfigException('Cannot read body file ' . $res['body_file']);
                    }
                    $body = $content;
                }
                /** @var array<string, string|list<string>> $headers */
                $headers = is_array($res['headers'] ?? null) ? $res['headers'] : [];
                $response = new Response((int) ($res['status'] ?? 200), $headers, $body, $url);
            }
            $this->on(
                $url,
                $response,
                $method,
                (float) ($res['delay'] ?? 0.0),
                (float) ($res['connect_delay'] ?? 0.0),
            );
        }

        return $this;
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;
        $key = strtoupper($request->method) . ' ' . $request->url;
        if (!isset($this->routes[$key]) || $this->routes[$key] === []) {
            throw TransportException::network('FakeTransport: no route for ' . $key);
        }
        $route = count($this->routes[$key]) > 1 ? array_shift($this->routes[$key]) : $this->routes[$key][0];

        if ($route['connectDelay'] > $request->connectTimeout) {
            $this->clock?->advance($request->connectTimeout);
            throw TransportException::timeout('FakeTransport: connect timeout');
        }
        if ($route['connectDelay'] + $route['delay'] > $request->timeout) {
            $this->clock?->advance($request->timeout);
            throw TransportException::timeout('FakeTransport: request timeout');
        }
        $this->clock?->advance($route['connectDelay'] + $route['delay']);

        $response = $route['response'];
        if ($response instanceof TransportException) {
            throw $response;
        }
        if (strlen($response->body) > $request->maxBodyBytes) {
            return new Response(
                $response->status,
                $response->headers,
                substr($response->body, 0, $request->maxBodyBytes),
                $request->url,
                true,
            );
        }

        return new Response($response->status, $response->headers, $response->body, $request->url);
    }

    /**
     * @return list<Request> every request sent, in order
     * @phpstan-impure
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * @return list<string> "METHOD url" for every request sent
     * @phpstan-impure
     */
    public function requestLines(): array
    {
        return array_map(
            static fn (Request $r): string => strtoupper($r->method) . ' ' . $r->url,
            $this->requests
        );
    }
}
