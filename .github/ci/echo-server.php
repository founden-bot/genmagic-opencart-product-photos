<?php
/**
 * A tiny local web server (php -S 127.0.0.1:8099 echo-server.php) the unit test points the real curl transport at,
 * so the transport itself (headers, POST body, no redirect following, size cap) is tested without GenMagic.
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

switch ($path) {
	case '/json':
		header('Content-Type: application/json');
		header('X-Cost-Cents: 7');
		header('X-Credits-Remaining: 993');
		echo '{"ok":true}';
		break;
	case '/redirect':
		header('Location: http://127.0.0.1:8099/json', true, 302);
		break;
	case '/big':
		header('Content-Type: application/octet-stream');
		echo str_repeat('a', 3000000);
		break;
	case '/echo':
		header('Content-Type: application/json');
		echo json_encode([
			'method'        => $_SERVER['REQUEST_METHOD'],
			'body'          => file_get_contents('php://input'),
			'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
			'client'        => $_SERVER['HTTP_X_GENMAGIC_CLIENT'] ?? null,
			'type'          => $_SERVER['CONTENT_TYPE'] ?? null
		]);
		break;
	case '/error':
		http_response_code(402);
		header('Content-Type: application/json');
		echo '{"error":{"message":"no credit","code":"insufficient_credits"}}';
		break;
	default:
		http_response_code(404);
}
