<?php
namespace Opencart\Admin\Model\Extension\Genmagic\Module;

require_once(__DIR__ . '/genmagic_exception.php');
/**
 * Class GenmagicClient
 *
 * The only code that talks to the GenMagic API (https://genmagic.co/api/v1). Errors carry GenMagic's own message,
 * because that message says what to do. It reads nothing from OpenCart: the caller passes the API key in, and the
 * HTTP transport can be replaced (the tests do that).
 *
 * @package Opencart\Admin\Model\Extension\Genmagic\Module
 */
class GenmagicClient {
	public const BASE = 'https://genmagic.co/api/v1';
	public const CLIENT_ID = 'opencart-product-photos/1.0.0';
	public const KEY_URL = 'https://genmagic.co/get-api-key';
	public const PRICING_URL = 'https://genmagic.co/pricing';
	public const PRICING_TEXT = 'genmagic.co/pricing';
	public const MAX_REDIRECTS = 3;
	public const MAX_API_BYTES = 5000000;

	/**
	 * Hosts GenMagic serves generated files from. Files are only ever downloaded from these.
	 *
	 * @var array<int, string>
	 */
	private const MEDIA_HOSTS = [
		'genmagic.co',
		'axrsv1b9sa2ny4qv.public.blob.vercel-storage.com',
		'tlwdqzfibvgektzgdfqa.supabase.co'
	];

	/**
	 * @var string
	 */
	private string $api_key;

	/**
	 * @var callable
	 */
	private $transport;

	/**
	 * Constructor
	 *
	 * @param string        $api_key
	 * @param callable|null $transport function(string $method, string $url, array $headers, ?string $body, int $timeout, int $max_bytes): array{status: int, headers: array<string, string>, body: string}
	 */
	public function __construct(string $api_key, ?callable $transport = null) {
		$this->api_key = $api_key;
		$this->transport = $transport ?? [$this, 'curlTransport'];
	}

	/**
	 * Get Editing Models
	 *
	 * The image models that work from a photo. The list needs no key.
	 *
	 * @throws \Opencart\Admin\Model\Extension\Genmagic\Module\GenmagicException
	 *
	 * @return array<int, array{id: string, name: string, est_usd: float|null}>
	 */
	public function getEditingModels(): array {
		$response = $this->request('GET', '/models?category=image', null, false, 20);

		$list = $response['data']['data'] ?? [];

		$models = [];

		if (is_array($list)) {
			foreach ($list as $raw) {
				$model = is_array($raw) ? $this->normalizeModel($raw) : null;

				if ($model !== null) {
					$models[] = $model;
				}
			}
		}

		if (!$models) {
			throw new GenmagicException('GenMagic returned no models. Try again in a moment.', 502);
		}

		return $models;
	}

	/**
	 * Create Image
	 *
	 * Generates one image from a picture. GenMagic answers when the image is ready, so this can take a while.
	 *
	 * @param string $prompt
	 * @param string $model  Model id.
	 * @param string $size
	 * @param string $image  A data:image URL of the product photo.
	 *
	 * @throws \Opencart\Admin\Model\Extension\Genmagic\Module\GenmagicException
	 *
	 * @return array{url: string, cost_cents: float|null, credits_cents: float|null}
	 */
	public function createImage(string $prompt, string $model, string $size, string $image): array {
		$response = $this->request('POST', '/images/generations', ['prompt' => $prompt, 'model' => $model, 'size' => $size, 'image' => $image], true, 120);

		$url = $response['data']['data'][0]['url'] ?? '';

		if (!is_string($url) || !$this->isAllowedMediaUrl($url)) {
			throw new GenmagicException('GenMagic did not return a usable image address.', 502);
		}

		return [
			'url'           => $url,
			'cost_cents'    => $response['cost_cents'],
			'credits_cents' => $response['credits_cents']
		];
	}

	/**
	 * Download
	 *
	 * Downloads a generated file from a GenMagic media host. Every address, including each redirect, must be on a
	 * GenMagic media host, and redirects are followed here by hand. The API key is never sent to it.
	 *
	 * @param string $url
	 * @param int    $max_bytes
	 *
	 * @throws \Opencart\Admin\Model\Extension\Genmagic\Module\GenmagicException
	 *
	 * @return string The file contents.
	 */
	public function download(string $url, int $max_bytes): string {
		$hops = 0;

		do {
			if (!$this->isAllowedMediaUrl($url)) {
				throw new GenmagicException('The file is not on a GenMagic address, so it was not downloaded.');
			}

			try {
				$reply = ($this->transport)('GET', $url, ['X-GenMagic-Client: ' . self::CLIENT_ID], null, 120, $max_bytes);
			} catch (\Throwable $e) {
				throw new GenmagicException('Could not download the result from GenMagic: ' . $this->clean($e->getMessage(), 200));
			}

			$status = (int)($reply['status'] ?? 0);

			$location = (string)(array_change_key_case((array)($reply['headers'] ?? []), CASE_LOWER)['location'] ?? '');

			$redirect = in_array($status, [301, 302, 303, 307, 308], true) && $location !== '';

			if ($redirect) {
				$url = $location;

				$hops++;
			}
		} while ($redirect && $hops <= self::MAX_REDIRECTS);

		if ($redirect) {
			throw new GenmagicException('Could not download the result from GenMagic (too many redirects).', $status);
		}

		if ($status !== 200) {
			throw new GenmagicException('Could not download the result from GenMagic (HTTP ' . $status . ').', $status);
		}

		$body = (string)($reply['body'] ?? '');

		if ($body === '' || strlen($body) > $max_bytes) {
			throw new GenmagicException('The downloaded file is empty or larger than this extension accepts.');
		}

		return $body;
	}

	/**
	 * Is Allowed Media Url
	 *
	 * Whether a URL points at a place GenMagic serves generated files from (https, no credentials, standard port).
	 *
	 * @param string $url
	 *
	 * @return bool
	 */
	public function isAllowedMediaUrl(string $url): bool {
		// Spaces, control characters, backslashes and non-ASCII bytes are how parsers get tricked into disagreeing.
		if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F-\xFF\\\\]/', $url)) {
			return false;
		}

		$parts = parse_url($url);

		if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
			return false;
		}

		if (strtolower($parts['scheme']) !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
			return false;
		}

		if (isset($parts['port']) && (int)$parts['port'] !== 443) {
			return false;
		}

		return in_array(strtolower($parts['host']), self::MEDIA_HOSTS, true);
	}

	/**
	 * Request
	 *
	 * Sends one request to the GenMagic API. Redirects are never followed: the key is only ever sent to the address
	 * built here, never to one a reply points to.
	 *
	 * @param string                    $method  GET or POST.
	 * @param string                    $path    Path under the API base, starting with a slash.
	 * @param array<string, mixed>|null $body    JSON body for POST.
	 * @param bool                      $auth    Whether to send the API key.
	 * @param int                       $timeout Seconds to wait.
	 *
	 * @throws \Opencart\Admin\Model\Extension\Genmagic\Module\GenmagicException
	 *
	 * @return array{data: array<mixed>, cost_cents: float|null, credits_cents: float|null}
	 */
	private function request(string $method, string $path, ?array $body, bool $auth, int $timeout): array {
		$headers = [
			'Accept: application/json',
			'X-GenMagic-Client: ' . self::CLIENT_ID
		];

		if ($auth) {
			if ($this->api_key === '') {
				throw new GenmagicException('Add your GenMagic API key in the extension settings before making photos. Get a key at ' . self::KEY_URL . '.');
			}

			$headers[] = 'Authorization: Bearer ' . $this->api_key;
		}

		$payload = null;

		if ($method === 'POST') {
			$headers[] = 'Content-Type: application/json';

			$payload = json_encode($body ?? new \stdClass());

			if ($payload === false) {
				throw new GenmagicException('The request could not be prepared.');
			}
		}

		try {
			$reply = ($this->transport)($method, self::BASE . $path, $headers, $payload, $timeout, self::MAX_API_BYTES);
		} catch (\Throwable $e) {
			throw new GenmagicException('Could not reach GenMagic: ' . $this->clean($e->getMessage(), 200), 502);
		}

		$status = (int)($reply['status'] ?? 0);

		$data = json_decode((string)($reply['body'] ?? ''), true);

		if (!is_array($data)) {
			$data = [];
		}

		if ($status < 200 || $status >= 300) {
			[$message, $code] = $this->errorDetails($data);

			if ($status === 402 || str_contains($code, 'insufficient')) {
				throw new GenmagicException('Your GenMagic balance is too low for this photo. Add credit at ' . self::PRICING_TEXT . ' and try again.', 402, $code);
			}

			if ($message === '') {
				$message = 'GenMagic answered with HTTP ' . $status . '.';
			}

			throw new GenmagicException($message, $status, $code);
		}

		$response_headers = array_change_key_case((array)($reply['headers'] ?? []), CASE_LOWER);

		return [
			'data'          => $data,
			'cost_cents'    => $this->numberHeader($response_headers, 'x-cost-cents'),
			'credits_cents' => $this->numberHeader($response_headers, 'x-credits-remaining')
		];
	}

	/**
	 * Error Details
	 *
	 * GenMagic's own error message and code from an error reply.
	 *
	 * @param array<mixed> $data
	 *
	 * @return array{0: string, 1: string}
	 */
	private function errorDetails(array $data): array {
		$message = '';
		$code = '';

		$error = $data['error'] ?? null;

		if (is_array($error)) {
			$message = is_string($error['message'] ?? null) ? $error['message'] : '';

			$raw_code = is_string($error['code'] ?? null) ? $error['code'] : '';

			$code = strtolower((string)preg_replace('/[^A-Za-z0-9_-]/', '', $raw_code));
		} elseif (is_string($error)) {
			$message = $error;
		}

		return [$this->clean($message, 600), $code];
	}

	/**
	 * Clean
	 *
	 * Plain single-line text, cut to a length. The text is shown to the merchant, so no tags and no control
	 * characters pass.
	 *
	 * @param string $text
	 * @param int    $length
	 *
	 * @return string
	 */
	private function clean(string $text, int $length): string {
		$text = strip_tags($text);
		$text = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

		return mb_substr(trim($text), 0, $length);
	}

	/**
	 * Number Header
	 *
	 * @param array<string, mixed> $headers Lowercase header names.
	 * @param string               $name
	 *
	 * @return float|null
	 */
	private function numberHeader(array $headers, string $name): ?float {
		$value = $headers[$name] ?? null;

		return (is_string($value) || is_numeric($value)) && is_numeric($value) ? (float)$value : null;
	}

	/**
	 * Normalize Model
	 *
	 * Keeps the fields this extension uses from one model of GET /models. Only models that take a picture as input
	 * are kept, so they can restyle a product photo.
	 *
	 * @param array<mixed> $raw
	 *
	 * @return array{id: string, name: string, est_usd: float|null}|null
	 */
	private function normalizeModel(array $raw): ?array {
		$id = is_string($raw['id'] ?? null) ? $raw['id'] : '';

		if (!preg_match('/^[A-Za-z0-9._:\/@+-]{1,120}$/', $id)) {
			return null;
		}

		$inputs = is_array($raw['input_modalities'] ?? null) ? $raw['input_modalities'] : [];

		if (!in_array('image', $inputs, true)) {
			return null;
		}

		$pricing = is_array($raw['pricing'] ?? null) ? $raw['pricing'] : [];

		$name = is_string($raw['name'] ?? null) && $raw['name'] !== '' ? $raw['name'] : $id;

		$estimate = $pricing['est_per_generation_usd'] ?? null;

		return [
			'id'      => $id,
			'name'    => $this->clean($name, 120) ?: $id,
			'est_usd' => (is_int($estimate) || is_float($estimate) || is_string($estimate)) && is_numeric($estimate) ? (float)$estimate : null
		];
	}

	/**
	 * Curl Transport
	 *
	 * The real HTTP call. No redirects are followed, and a body over the limit is cut off.
	 *
	 * @param string             $method
	 * @param string             $url
	 * @param array<int, string> $headers   Header lines.
	 * @param string|null        $body
	 * @param int                $timeout
	 * @param int                $max_bytes
	 *
	 * @throws \RuntimeException
	 *
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public function curlTransport(string $method, string $url, array $headers, ?string $body, int $timeout, int $max_bytes): array {
		$buffer = '';
		$too_big = false;
		$reply_headers = [];

		$curl = curl_init($url);

		if ($curl === false) {
			throw new \RuntimeException('curl is not available');
		}

		$options = [
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_WRITEFUNCTION  => function ($handle, string $chunk) use (&$buffer, &$too_big, $max_bytes): int {
				if (strlen($buffer) + strlen($chunk) > $max_bytes) {
					$too_big = true;

					return 0;
				}

				$buffer .= $chunk;

				return strlen($chunk);
			},
			CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$reply_headers): int {
				$parts = explode(':', $line, 2);

				if (count($parts) === 2) {
					$reply_headers[strtolower(trim($parts[0]))] = trim($parts[1]);
				}

				return strlen($line);
			}
		];

		if ($body !== null) {
			$options[CURLOPT_POSTFIELDS] = $body;
		}

		curl_setopt_array($curl, $options);

		$ok = curl_exec($curl);

		$error = curl_error($curl);

		$status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

		// The handle is released when it goes out of scope (curl_close() does nothing and is deprecated in PHP 8.5).
		unset($curl);

		if ($too_big) {
			throw new \RuntimeException('the reply is larger than this extension accepts');
		}

		if ($ok === false) {
			throw new \RuntimeException($error !== '' ? $error : 'the request failed');
		}

		return ['status' => $status, 'headers' => $reply_headers, 'body' => $buffer];
	}
}
