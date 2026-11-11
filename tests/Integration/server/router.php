<?php

// Router for PHP's built-in web server, used by TransportTest only.

declare(strict_types=1);

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

switch ($path) {
    case '/ok':
        header('Content-Type: text/html; charset=utf-8');
        header('X-Test: one');
        echo '<html>ok</html>';
        break;
    case '/redirect':
        header('Location: /ok', true, 302);
        break;
    case '/big':
        header('Content-Type: text/plain');
        echo str_repeat('a', 2_000_000);
        break;
    case '/slow':
        sleep(3);
        echo 'late';
        break;
    case '/missing':
        http_response_code(404);
        echo 'nope';
        break;
    case '/echo':
        header('Content-Type: application/json');
        echo json_encode([
            'method' => $_SERVER['REQUEST_METHOD'],
            'body' => file_get_contents('php://input'),
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'type' => $_SERVER['CONTENT_TYPE'] ?? null,
            'cookie' => $_SERVER['HTTP_COOKIE'] ?? null,
        ]);
        break;
    case '/set-cookie':
        header('Set-Cookie: tracker=1; Path=/');
        echo 'cookie set';
        break;
    default:
        http_response_code(500);
        echo 'unknown route';
}
