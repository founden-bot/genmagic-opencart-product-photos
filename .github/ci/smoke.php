<?php
/**
 * Server-side smoke test of the GenMagic extension inside a real, installed OpenCart.
 *
 *   php smoke.php <opencart-root>
 *
 * Boots OpenCart's own classes (autoloader, config, event, loader, database, language, template) against the
 * installed database, installs the extension the way the Extension Installer and the Extensions page do, then drives
 * the extension's controller routes through OpenCart's loader. GenMagic is mocked at the HTTP transport, so no
 * request leaves the machine. Prints "PASS: ..." or "FAIL: ..." lines and exits 1 when anything failed.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

if ($argc < 2) {
	fwrite(STDERR, "usage: php smoke.php <opencart-root>\n");
	exit(2);
}

$root = rtrim($argv[1], '/') . '/';

$failures = 0;
$checks = 0;

function check(bool $ok, string $label): void {
	global $failures, $checks;

	$checks++;

	if (!$ok) {
		$failures++;
	}

	echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
}

function note(string $text): void {
	echo 'NOTE: ' . $text . "\n";
}

// ---------------------------------------------------------------- OpenCart bootstrap (as system/framework.php does)

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['PHP_SELF'] = $_SERVER['PHP_SELF'] ?? '/admin/index.php';

require_once($root . 'admin/config.php');
require_once(DIR_SYSTEM . 'startup.php');

$autoloader = new \Opencart\System\Engine\Autoloader();
$autoloader->register('Opencart\\' . APPLICATION, DIR_APPLICATION);
$autoloader->register('Opencart\Extension', DIR_EXTENSION);
$autoloader->register('Opencart\System', DIR_SYSTEM);

if (is_file(DIR_STORAGE . 'vendor/autoload.php')) {
	require_once(DIR_STORAGE . 'vendor/autoload.php');
}

$registry = new \Opencart\System\Engine\Registry();
$registry->set('autoloader', $autoloader);

$config = new \Opencart\System\Engine\Config();
$config->addPath(DIR_CONFIG);
$config->load('default');
$config->load(strtolower(APPLICATION));
$config->set('application', APPLICATION);
$registry->set('config', $config);

date_default_timezone_set($config->get('date_timezone'));

$registry->set('log', new \Opencart\System\Library\Log('smoke.log'));

$event = new \Opencart\System\Engine\Event($registry);
$registry->set('event', $event);

if (class_exists('Opencart\System\Engine\Factory')) {
	$registry->set('factory', new \Opencart\System\Engine\Factory($registry));
}

$loader = new \Opencart\System\Engine\Loader($registry);
$registry->set('load', $loader);

$request = new \Opencart\System\Library\Request();
$registry->set('request', $request);

$response = new \Opencart\System\Library\Response();
$registry->set('response', $response);

$db = new \Opencart\System\Library\DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, DB_PORT);
$registry->set('db', $db);

$registry->set('cache', new \Opencart\System\Library\Cache($config->get('cache_engine'), $config->get('cache_expire')));

$template = new \Opencart\System\Library\Template($config->get('template_engine'));
$template->addPath(DIR_TEMPLATE);
$registry->set('template', $template);

$language = new \Opencart\System\Library\Language($config->get('language_code'));
$language->addPath(DIR_LANGUAGE);
$language->load('default');
$registry->set('language', $language);

$registry->set('url', new \Opencart\System\Library\Url(HTTP_SERVER));
$registry->set('document', new \Opencart\System\Library\Document());

/**
 * The signed-in admin. Permissions are read from the Administrator user group in the database, so what the install
 * step grants is what the routes see.
 */
final class SmokeUser {
	public bool $deny = false;

	public int $id = 1;

	public function __construct(private \Opencart\System\Library\DB $db) {
	}

	public function hasPermission(string $key, string $value): bool {
		if ($this->deny) {
			return false;
		}

		$query = $this->db->query("SELECT `permission` FROM `" . DB_PREFIX . "user_group` WHERE `user_group_id` = '1'");

		$permission = json_decode((string)($query->row['permission'] ?? ''), true) ?: [];

		return in_array($value, $permission[$key] ?? [], true);
	}

	public function getId(): int {
		return $this->id;
	}

	public function getGroupId(): int {
		return 1;
	}

	public function isLogged(): bool {
		return true;
	}
}

final class SmokeSession {
	public array $data = ['user_token' => 'smoketoken0123456789abcdef01234567'];

	public function getId(): string {
		return 'smoke';
	}
}

$user = new SmokeUser($db);
$registry->set('user', $user);

$session = new SmokeSession();
$registry->set('session', $session);

$token = $session->data['user_token'];

// ---------------------------------------------------------------- GenMagic mock (the HTTP transport)

/**
 * Answers like GenMagic: model list, image generation (or a 402), a redirecting download.
 */
final class GenmagicMock {
	public array $calls = [];

	public string $mode = 'ok';

	public string $png = '';

	public function __invoke(string $method, string $url, array $headers, ?string $body, int $timeout, int $max_bytes): array {
		$this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

		if ($method === 'GET' && str_ends_with($url, '/api/v1/models?category=image')) {
			return ['status' => 200, 'headers' => [], 'body' => json_encode(['data' => [
				['id' => 'google/gemini-nano-banana-2.1', 'name' => 'Photo model', 'input_modalities' => ['text', 'image'], 'pricing' => ['est_per_generation_usd' => 0.04]],
				['id' => 'text/only-model', 'name' => 'Text only', 'input_modalities' => ['text']]
			]])];
		}

		if ($method === 'POST' && str_ends_with($url, '/api/v1/images/generations')) {
			if ($this->mode === '402') {
				return ['status' => 402, 'headers' => [], 'body' => '{"error":{"message":"Not enough credit","code":"insufficient_credits"}}'];
			}

			return ['status' => 200, 'headers' => ['x-cost-cents' => '4', 'x-credits-remaining' => '1234'], 'body' => '{"data":[{"url":"https://genmagic.co/out/result.png"}]}'];
		}

		if ($method === 'GET' && $url === 'https://genmagic.co/out/result.png') {
			return ['status' => 302, 'headers' => ['Location' => 'https://axrsv1b9sa2ny4qv.public.blob.vercel-storage.com/result.png'], 'body' => ''];
		}

		if ($method === 'GET' && $url === 'https://axrsv1b9sa2ny4qv.public.blob.vercel-storage.com/result.png') {
			return ['status' => 200, 'headers' => [], 'body' => $this->png];
		}

		throw new \RuntimeException('unexpected request ' . $method . ' ' . $url);
	}

	public function calls(string $method, string $needle): array {
		return array_values(array_filter($this->calls, fn(array $call): bool => $call['method'] === $method && str_contains($call['url'], $needle)));
	}
}

$mock = new GenmagicMock();

ob_start();
$image = imagecreatetruecolor(48, 48);
imagefill($image, 0, 0, imagecolorallocate($image, 200, 40, 40));
imagepng($image);
$mock->png = (string)ob_get_clean();

// ---------------------------------------------------------------- helpers

const ROUTE = 'extension/genmagic/module/genmagic_photos';
const MODEL_KEY = 'model_extension_genmagic_module_genmagic_photos';

use Opencart\Admin\Model\Extension\Genmagic\Module\GenmagicClient;

/**
 * Settings as the admin startup puts them into the config.
 */
function reload_settings(): void {
	global $db, $config, $registry, $mock, $service;

	foreach (['status', 'consent', 'api_key'] as $name) {
		$config->set('module_genmagic_photos_' . $name, '');
	}

	foreach ($db->query("SELECT * FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '0' AND `code` = 'module_genmagic_photos'")->rows as $row) {
		$config->set($row['key'], !empty($row['serialized']) ? json_decode($row['value'], true) : $row['value']);
	}

	if (isset($service)) {
		$service->setClient(new GenmagicClient(trim((string)$config->get('module_genmagic_photos_api_key')), $mock));
	}
}

/**
 * Runs one of the extension's routes through OpenCart's loader and returns the JSON it answered.
 *
 * @param array<string, mixed> $post
 */
function call(string $action, array $post = [], string $method = 'POST', ?string $user_token = null): array {
	global $registry, $request, $response, $loader, $token;

	$request->get = ['route' => ROUTE . '.' . $action, 'user_token' => $user_token ?? $token];
	$request->post = $post;
	$request->server['REQUEST_METHOD'] = $method;

	$response->setOutput('');

	$loader->controller(ROUTE . '.' . $action);

	$decoded = json_decode($response->getOutput(), true);

	return is_array($decoded) ? $decoded : ['_raw' => $response->getOutput()];
}

function count_images(int $product_id): int {
	global $db;

	return (int)$db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "product_image` WHERE `product_id` = '" . $product_id . "'")->row['total'];
}

function main_image(int $product_id): string {
	global $db;

	return (string)$db->query("SELECT `image` FROM `" . DB_PREFIX . "product` WHERE `product_id` = '" . $product_id . "'")->row['image'];
}

function tmp_files(): array {
	return glob(DIR_IMAGE . 'catalog/genmagic/tmp/*') ?: [];
}

try {
	// ------------------------------------------------------------ the package as installed

	$info = json_decode((string)file_get_contents(DIR_EXTENSION . 'genmagic/install.json'), true);

	check(is_array($info) && ($info['name'] ?? '') !== '' && ($info['version'] ?? '') === '1.0.0' && ($info['author'] ?? '') === 'GenMagic' && ($info['link'] ?? '') === 'https://genmagic.co', 'install.json has name, version 1.0.0, author GenMagic and link');
	check(is_file(DIR_EXTENSION . 'genmagic/admin/controller/module/genmagic_photos.php'), 'the extension controller sits where the Extensions page looks for modules');

	// ------------------------------------------------------------ install (what the Extension Installer and the Extensions page do)

	$autoloader->register('Opencart\Admin\Controller\Extension\Genmagic', DIR_EXTENSION . 'genmagic/admin/controller/');
	$autoloader->register('Opencart\Admin\Model\Extension\Genmagic', DIR_EXTENSION . 'genmagic/admin/model/');
	$template->addPath('extension/genmagic', DIR_EXTENSION . 'genmagic/admin/view/template/');
	$language->addPath('extension/genmagic', DIR_EXTENSION . 'genmagic/admin/language/');

	$extension_model = new \Opencart\Admin\Model\Setting\Extension($registry);

	$install_id = $extension_model->addInstall([
		'extension_id'          => 0,
		'extension_download_id' => 0,
		'name'                  => $info['name'],
		'description'           => $info['description'] ?? '',
		'code'                  => 'genmagic',
		'version'               => $info['version'],
		'author'                => $info['author'],
		'link'                  => $info['link']
	]);

	$extension_model->editStatus($install_id, true);

	check($extension_model->getInstall($install_id)['code'] === 'genmagic', 'the extension is registered as installed');

	$extension_model->install('module', 'genmagic', 'genmagic_photos');

	$modules = array_column($extension_model->getExtensionsByType('module'), 'code');

	check(in_array('genmagic_photos', $modules, true), 'the module is installed under Extensions > Modules');

	$group_model = new \Opencart\Admin\Model\User\UserGroup($registry);
	$group_model->addPermission(1, 'access', ROUTE);
	$group_model->addPermission(1, 'modify', ROUTE);

	check($user->hasPermission('access', ROUTE) && $user->hasPermission('modify', ROUTE), 'the Administrator group may open and modify the module');

	$loader->controller(ROUTE . '.install');
	$loader->controller(ROUTE . '.install');

	$events = $db->query("SELECT * FROM `" . DB_PREFIX . "event` WHERE `code` = 'genmagic_photos_panel'")->rows;

	check(count($events) === 1, 'installing twice leaves exactly one event');
	check(($events[0]['trigger'] ?? '') === 'admin/view/catalog/product_form/after' && ($events[0]['action'] ?? '') === ROUTE . '.panel' && (int)($events[0]['status'] ?? 0) === 1, 'the event hooks the product form view and is on');

	// Events are registered as the admin startup does it.
	foreach ($events as $row) {
		$parts = explode('/', $row['trigger']);

		array_shift($parts);

		$event->register(implode('/', $parts), new \Opencart\System\Engine\Action($row['action']), (int)$row['sort_order']);
	}

	// The service, with the mocked transport put in place (both registry keys, so any 4.x loader uses it).
	$service = new \Opencart\Admin\Model\Extension\Genmagic\Module\GenmagicPhotos($registry);
	$registry->set(MODEL_KEY, $service);
	$registry->set('fallback_' . MODEL_KEY, $service);

	reload_settings();

	// ------------------------------------------------------------ settings

	$result = call('save', ['module_genmagic_photos_status' => '1', 'module_genmagic_photos_consent' => '0', 'module_genmagic_photos_api_key' => '']);
	check(isset($result['error']['api_key']), 'settings: switching on without any key is refused');

	$result = call('save', ['module_genmagic_photos_status' => '1', 'module_genmagic_photos_consent' => '0', 'module_genmagic_photos_api_key' => 'short']);
	check(isset($result['error']['api_key']), 'settings: a too short key is refused');

	$result = call('save', ['module_genmagic_photos_status' => '1', 'module_genmagic_photos_consent' => '0', 'module_genmagic_photos_api_key' => 'has some spaces in it']);
	check(isset($result['error']['api_key']), 'settings: a key with spaces is refused');

	$user->deny = true;
	$result = call('save', ['module_genmagic_photos_status' => '1', 'module_genmagic_photos_consent' => '0', 'module_genmagic_photos_api_key' => 'gm_test_key_0123456789']);
	check(isset($result['error']['warning']), 'settings: a user without modify permission cannot save');
	$user->deny = false;

	$saved = $db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "setting` WHERE `code` = 'module_genmagic_photos'")->row['total'];
	check((int)$saved === 0, 'settings: nothing was written by the refused saves');

	$result = call('save', ['module_genmagic_photos_status' => '1', 'module_genmagic_photos_consent' => '0', 'module_genmagic_photos_api_key' => 'gm_test_key_0123456789']);
	check(isset($result['success']), 'settings: a valid save succeeds');

	$rows = [];

	foreach ($db->query("SELECT `key`, `value` FROM `" . DB_PREFIX . "setting` WHERE `code` = 'module_genmagic_photos'")->rows as $row) {
		$rows[$row['key']] = $row['value'];
	}

	check(($rows['module_genmagic_photos_status'] ?? '') === '1' && ($rows['module_genmagic_photos_consent'] ?? '') === '0' && ($rows['module_genmagic_photos_api_key'] ?? '') === 'gm_test_key_0123456789', 'settings: status, consent and key are stored');

	reload_settings();

	// ------------------------------------------------------------ nothing is sent before consent

	$product = $db->query("SELECT `product_id`, `image` FROM `" . DB_PREFIX . "product` WHERE `image` != '' ORDER BY `product_id` LIMIT 1")->row;

	check(isset($product['product_id']) && is_file(DIR_IMAGE . $product['image']), 'the demo store has a product with a main image file');

	$product_id = (int)$product['product_id'];
	$main_before = main_image($product_id);
	$images_before = count_images($product_id);

	$result = call('generate', ['product_id' => $product_id, 'style' => 'studio', 'extra' => '', 'model' => '']);
	check(isset($result['error']) && str_contains($result['error'], 'Nothing has been sent'), 'consent off: a photo is refused with a clear message');
	$result = call('models', [], 'GET');
	check(isset($result['error']) && !isset($result['models']), 'consent off: the model list is not fetched either');
	check($mock->calls === [], 'consent off: no request was made to GenMagic');

	$result = call('save', ['module_genmagic_photos_status' => '1', 'module_genmagic_photos_consent' => '1', 'module_genmagic_photos_api_key' => '']);
	check(isset($result['success']), 'settings: consent given, key left empty');

	reload_settings();

	check($config->get('module_genmagic_photos_api_key') === 'gm_test_key_0123456789', 'settings: leaving the key empty keeps the saved key');

	// ------------------------------------------------------------ models

	$registry->get('cache')->delete(\Opencart\Admin\Model\Extension\Genmagic\Module\GenmagicPhotos::MODELS_CACHE_KEY);

	$result = call('models', [], 'GET');
	check(($result['success'] ?? false) === true && count($result['models']) === 1 && $result['default'] === 'google/gemini-nano-banana-2.1', 'models: only photo-capable models, with the same default model as the other integrations');
	check(count($mock->calls('GET', '/api/v1/models?category=image')) === 1, 'models: GET /models?category=image');

	$result = call('models', [], 'GET', 'wrongtoken');
	check(isset($result['error']) && !isset($result['models']), 'models: a wrong user token is refused');

	// ------------------------------------------------------------ make a photo

	$old = DIR_IMAGE . 'catalog/genmagic/tmp/1-1-' . str_repeat('a', 32) . '.png';

	if (!is_dir(dirname($old))) {
		mkdir(dirname($old), 0755, true);
	}

	file_put_contents($old, 'old');
	touch($old, time() - 3 * 86400);

	$result = call('generate', ['product_id' => $product_id, 'style' => 'studio', 'extra' => 'on a wooden table &amp; plant', 'model' => '']);

	check(($result['success'] ?? false) === true, 'generate: the product photo action makes a result' . (isset($result['error']) ? ' (' . $result['error'] . ')' : ''));
	check(preg_match('/^' . $product_id . '-1-[a-f0-9]{32}\.png$/', (string)($result['token'] ?? '')) === 1, 'generate: the result is named after the product, the user and a random part');
	check(isset($result['preview_url']) && str_starts_with($result['preview_url'], HTTP_CATALOG . 'image/catalog/genmagic/tmp/') && is_file(DIR_IMAGE . 'catalog/genmagic/tmp/' . $result['token']), 'generate: the result is saved under image/catalog/genmagic and shown from there');
	check(($result['cost_usd'] ?? '') === '0.04' && ($result['balance_usd'] ?? '') === '12.34', 'generate: cost and balance come from x-cost-cents and x-credits-remaining');
	check(!is_file($old), 'generate: temporary results older than a day are cleaned up');

	$posts = $mock->calls('POST', '/api/v1/images/generations');
	$sent = json_decode((string)($posts[0]['body'] ?? ''), true) ?: [];

	check(count($posts) === 1 && in_array('Authorization: Bearer gm_test_key_0123456789', $posts[0]['headers'], true), 'generate: one request, with the Bearer key');
	check(($sent['model'] ?? '') === 'google/gemini-nano-banana-2.1' && ($sent['size'] ?? '') === '1024x1024', 'generate: the default model and a 1024x1024 size');
	check(str_contains($sent['prompt'] ?? '', 'clean white seamless background') && str_contains($sent['prompt'] ?? '', 'Keep the product itself exactly as in the photo') && str_ends_with($sent['prompt'] ?? '', 'on a wooden table & plant'), 'generate: the studio prompt with the keep-the-product rule and the merchant wishes');
	check(($sent['image'] ?? '') === 'data:image/' . (str_ends_with($product['image'], '.png') ? 'png' : 'jpeg') . ';base64,' . base64_encode((string)file_get_contents(DIR_IMAGE . $product['image'])), 'generate: the product main image is sent as a data:image URL');

	$downloads = array_values(array_filter($mock->calls, fn(array $call): bool => $call['method'] === 'GET' && str_contains($call['url'], '/out/') || str_contains($call['url'], 'blob.vercel-storage.com')));
	$key_leaked = false;

	foreach ($downloads as $call) {
		foreach ($call['headers'] as $line) {
			if (stripos($line, 'authorization') === 0 || str_contains($line, 'gm_test_key')) {
				$key_leaked = true;
			}
		}
	}

	check(count($downloads) === 2 && !$key_leaked, 'generate: the redirected download went to the two GenMagic hosts and the key was not sent with it');
	check(main_image($product_id) === $main_before && count_images($product_id) === $images_before, 'generate: the product is untouched until the merchant adds the photo');

	// ------------------------------------------------------------ add it

	$made = $result;

	$result = call('add', ['product_id' => $product_id, 'token' => $made['token'], 'use_as_main' => '']);

	check(($result['success'] ?? false) === true, 'add: the photo is added' . (isset($result['error']) ? ' (' . $result['error'] . ')' : ''));
	check(count_images($product_id) === $images_before + 1, 'add: a product_image row was created');

	$row = $db->query("SELECT * FROM `" . DB_PREFIX . "product_image` WHERE `product_id` = '" . $product_id . "' ORDER BY `product_image_id` DESC LIMIT 1")->row;

	check(($row['image'] ?? '') === ($result['image'] ?? '-') && str_starts_with($row['image'] ?? '', 'catalog/genmagic/' . $product_id . '-') && is_file(DIR_IMAGE . $row['image']), 'add: the row points at a file in image/catalog/genmagic');
	check(main_image($product_id) === $main_before, 'add: the product main image is unchanged');
	check(!is_file(DIR_IMAGE . 'catalog/genmagic/tmp/' . $made['token']), 'add: the temporary result is gone');

	$result = call('add', ['product_id' => $product_id, 'token' => $made['token'], 'use_as_main' => '']);
	check(isset($result['error']) && count_images($product_id) === $images_before + 1, 'add: the same result cannot be added twice');

	// ------------------------------------------------------------ main image only when ticked

	$second = call('generate', ['product_id' => $product_id, 'style' => 'flatlay', 'extra' => '', 'model' => 'google/gemini-nano-banana-2.1']);

	check(($second['success'] ?? false) === true, 'generate: a second photo in another style');

	$user->id = 2;
	$result = call('add', ['product_id' => $product_id, 'token' => (string)$second['token'], 'use_as_main' => '1']);
	check(isset($result['error']) && count_images($product_id) === $images_before + 1 && main_image($product_id) === $main_before, 'add: another admin user cannot add this photo');
	$user->id = 1;

	$result = call('add', ['product_id' => $product_id + 1, 'token' => (string)$second['token'], 'use_as_main' => '1']);
	check(isset($result['error']) && main_image($product_id) === $main_before, 'add: a photo cannot be added to another product');

	foreach (['../../../config.php', $second['token'] . '/../x.png', '1-1-xyz.png', ''] as $bad) {
		$result = call('add', ['product_id' => $product_id, 'token' => $bad, 'use_as_main' => '1']);
		check(isset($result['error']) && !isset($result['success']), 'add: a forged token is refused (' . var_export($bad, true) . ')');
	}

	$result = call('add', ['product_id' => $product_id, 'token' => (string)$second['token'], 'use_as_main' => '1']);
	check(($result['success'] ?? false) === true && main_image($product_id) === ($result['image'] ?? '-') && count_images($product_id) === $images_before + 2, 'add: ticking the box makes it the main image as well');

	$db->query("UPDATE `" . DB_PREFIX . "product` SET `image` = '" . $db->escape($main_before) . "' WHERE `product_id` = '" . $product_id . "'");

	// ------------------------------------------------------------ guards

	$calls_before = count($mock->calls);

	$result = call('generate', ['product_id' => $product_id, 'style' => 'studio', 'extra' => '', 'model' => ''], 'POST', 'wrongtoken');
	check(isset($result['error']) && !isset($result['success']), 'guard: generate with a wrong user token is refused');

	$result = call('generate', ['product_id' => $product_id, 'style' => 'studio', 'extra' => '', 'model' => ''], 'GET');
	check(isset($result['error']) && !isset($result['success']), 'guard: generate only answers POST');

	$result = call('add', ['product_id' => $product_id, 'token' => 'x'], 'GET');
	check(isset($result['error']) && !isset($result['success']), 'guard: add only answers POST');

	$user->deny = true;
	$result = call('generate', ['product_id' => $product_id, 'style' => 'studio', 'extra' => '', 'model' => '']);
	check(isset($result['error']) && !isset($result['success']), 'guard: generate without modify permission is refused');
	$result = call('models', [], 'GET');
	check(isset($result['error']) && !isset($result['models']), 'guard: models without modify permission is refused');
	$user->deny = false;

	$result = call('generate', ['product_id' => $product_id, 'style' => 'nope', 'extra' => '', 'model' => '']);
	check(isset($result['error']), 'guard: an unknown style is refused');

	$result = call('generate', ['product_id' => $product_id, 'style' => 'studio', 'extra' => '', 'model' => 'text/only-model']);
	check(isset($result['error']) && str_contains($result['error'], 'cannot make a photo'), 'guard: a model that takes no photo is refused, never swapped');

	$result = call('generate', ['product_id' => 99999999, 'style' => 'studio', 'extra' => '', 'model' => '']);
	check(isset($result['error']) && str_contains($result['error'], 'Save the product first'), 'guard: an unknown product asks to save it first');

	check(count($mock->calls('POST', '/images/generations')) === 2 && count($mock->calls) === $calls_before, 'guard: none of the refused calls reached GenMagic');

	// ------------------------------------------------------------ out of balance (HTTP 402)

	$mock->mode = '402';
	$files_before = count(tmp_files());

	$result = call('generate', ['product_id' => $product_id, 'style' => 'studio', 'extra' => '', 'model' => '']);
	check(isset($result['error']) && str_contains($result['error'], 'balance is too low') && str_contains($result['error'], 'genmagic.co/pricing') && ($result['pricing_url'] ?? '') === 'https://genmagic.co/pricing', 'HTTP 402: a plain balance-too-low message that writes genmagic.co/pricing');
	check(!str_contains($result['error'] ?? '', 'Not enough credit') && count(tmp_files()) === $files_before, 'HTTP 402: no raw reply shown and no file left behind');

	$mock->mode = 'ok';

	// ------------------------------------------------------------ the panel on the product form (event handler)

	$form = (string)file_get_contents(DIR_APPLICATION . 'view/template/catalog/product_form.twig');

	$controller = new \Opencart\Admin\Controller\Extension\Genmagic\Module\GenmagicPhotos($registry);

	$route = 'catalog/product_form';
	$data = ['product_id' => $product_id];
	$output = $form;
	$controller->panel($route, $data, $output);

	$form_end = strpos($output, '</form>', (int)strpos($output, 'id="form-product"'));

	check(substr_count($output, 'id="genmagic-photos"') === 1 && strpos($output, 'id="genmagic-photos"') > $form_end, 'panel: injected once, after the product form');
	check(str_contains($output, 'data-ready="1"') && str_contains($output, 'data-product-id="' . $product_id . '"') && str_contains($output, 'user_token=' . $token), 'panel: ready for this product, with the admin user token in its routes');
	check(str_contains($output, 'White studio') && str_contains($output, 'Flat lay') && str_contains($output, 'Seasonal'), 'panel: the five styles are offered');
	check(substr($output, 0, $form_end) === substr($form, 0, $form_end), 'panel: the core template text before the panel is unchanged');

	$data = ['product_id' => 0];
	$output = $form;
	$controller->panel($route, $data, $output);
	check(str_contains($output, 'id="genmagic-photos"') && str_contains($output, 'data-ready="0"') && str_contains($output, 'Save the product first'), 'panel: a new product is asked to be saved first');

	$user->deny = true;
	$data = ['product_id' => $product_id];
	$output = $form;
	$controller->panel($route, $data, $output);
	check($output === $form, 'panel: a user without modify permission sees nothing');
	$user->deny = false;

	$config->set('module_genmagic_photos_status', '0');
	$output = $form;
	$controller->panel($route, $data, $output);
	check($output === $form, 'panel: nothing is added while the extension is off');
	$config->set('module_genmagic_photos_status', '1');

	$config->set('module_genmagic_photos_consent', '0');
	$output = $form;
	$controller->panel($route, $data, $output);
	check(str_contains($output, 'data-ready="0"') && str_contains($output, 'Nothing has been sent to GenMagic yet'), 'panel: without consent it says so and shows no form');
	$config->set('module_genmagic_photos_consent', '1');

	// The same through OpenCart's own view loader, so the registered event is what fires.
	try {
		$html = $loader->view('catalog/product_form', ['product_id' => $product_id]);

		check(str_contains($html, 'id="genmagic-photos"'), 'event: rendering catalog/product_form through the loader fires the registered event');
	} catch (\Throwable $e) {
		note('the real product form could not be rendered outside a web request (' . $e->getMessage() . '); the web check covers it');
	}

	// ------------------------------------------------------------ the settings page template

	$strings = $language->all();

	$html = $loader->view(ROUTE, $strings + [
		'header' => '', 'column_left' => '', 'footer' => '', 'back' => '#', 'save' => '#', 'breadcrumbs' => [],
		'module_genmagic_photos_status' => true, 'module_genmagic_photos_consent' => true, 'has_key' => true,
		'key_url' => 'https://genmagic.co/get-api-key', 'pricing_url' => 'https://genmagic.co/pricing'
	]);

	check(str_contains($html, 'GenMagic AI Product Photos') && str_contains($html, 'module_genmagic_photos_consent'), 'settings page: renders with its status, key and consent fields');

	// ------------------------------------------------------------ uninstall and install again

	$loader->controller(ROUTE . '.uninstall');

	$left = $db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "event` WHERE `code` = 'genmagic_photos_panel'")->row['total'];
	$settings_left = $db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "setting` WHERE `code` = 'module_genmagic_photos'")->row['total'];

	check((int)$left === 0 && (int)$settings_left === 0, 'uninstall: the event and the settings are removed');

	$loader->controller(ROUTE . '.install');

	$again = $db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "event` WHERE `code` = 'genmagic_photos_panel'")->row['total'];

	check((int)$again === 1, 'install again: the event is back (left in place for the web check)');
} catch (\Throwable $e) {
	check(false, 'the smoke test crashed: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine());

	foreach (array_slice(explode("\n", $e->getTraceAsString()), 0, 6) as $line) {
		echo '      ' . $line . "\n";
	}
}

echo "\n" . ($checks - $failures) . '/' . $checks . " checks passed\n";

exit($failures ? 1 : 0);
