<?php
/**
 * Offline checks of the GenMagic extension code that need no database and no web server.
 *
 *   php unit.php <opencart-root> <extension-folder> [local-echo-server-url]
 *
 * <opencart-root> is an unpacked OpenCart "upload" folder (for its bundled Twig and its product form template),
 * <extension-folder> is the extension/genmagic folder of this repository. GenMagic is mocked: no request leaves
 * the machine. Prints one "PASS" or "FAIL" line per check and exits 1 when anything failed.
 */
error_reporting(E_ALL);

if ($argc < 3) {
	fwrite(STDERR, "usage: php unit.php <opencart-root> <extension-folder>\n");
	exit(2);
}

$root = rtrim($argv[1], '/') . '/';
$ext = rtrim($argv[2], '/') . '/';

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

// Classes under test
require_once($root . 'system/engine/registry.php');
require_once($root . 'system/engine/model.php');
require_once($ext . 'admin/model/module/genmagic_exception.php');
require_once($ext . 'admin/model/module/genmagic_client.php');
require_once($ext . 'admin/model/module/genmagic_styles.php');
require_once($ext . 'admin/model/module/genmagic_photos.php');

use Opencart\Admin\Model\Extension\Genmagic\Module\GenmagicClient;
use Opencart\Admin\Model\Extension\Genmagic\Module\GenmagicException;
use Opencart\Admin\Model\Extension\Genmagic\Module\GenmagicPhotos;
use Opencart\Admin\Model\Extension\Genmagic\Module\GenmagicStyles;

/**
 * A scripted transport: records every request and answers from a list of canned replies.
 */
final class FakeTransport {
	/** @var array<int, array<string, mixed>> */
	public array $calls = [];

	/** @var array<int, array<string, mixed>> */
	private array $replies;

	public function __construct(array $replies) {
		$this->replies = $replies;
	}

	public function __invoke(string $method, string $url, array $headers, ?string $body, int $timeout, int $max_bytes): array {
		$this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeout];

		$reply = array_shift($this->replies);

		if ($reply === null) {
			throw new \RuntimeException('no reply scripted');
		}

		if ($reply instanceof \Throwable) {
			throw $reply;
		}

		return $reply + ['status' => 200, 'headers' => [], 'body' => ''];
	}

	public function authorizationSeen(int $index): bool {
		foreach ($this->calls[$index]['headers'] as $line) {
			if (stripos($line, 'authorization:') === 0) {
				return true;
			}
		}

		return false;
	}
}

function throws(callable $fn): ?GenmagicException {
	try {
		$fn();
	} catch (GenmagicException $e) {
		return $e;
	}

	return null;
}

// ---------------------------------------------------------------- styles

$styles = new GenmagicStyles();

check($styles->getIds() === ['studio', 'lifestyle', 'model', 'flatlay', 'seasonal'], 'five styles in order');

foreach ($styles->getIds() as $id) {
	check(str_contains($styles->buildPrompt($id, ''), 'Keep the product itself exactly as in the photo: same shape, colors, materials, label, logo and proportions.'), 'style ' . $id . ' keeps the product exactly');
}

check(!$styles->isValid('bogus') && $styles->isValid('flatlay'), 'only the five styles are valid');
check(mb_strlen($styles->cleanExtra(str_repeat('x', 500))) === 300, 'extra wishes cut to 300 characters');
check($styles->cleanExtra('on a &lt;b&gt;wooden&lt;/b&gt; table &amp; plant') === 'on a wooden table & plant', 'extra wishes: request escaping undone, tags removed');
check($styles->cleanExtra("a\x00b\x07c") === 'abc', 'extra wishes: control characters removed');
check(str_ends_with($styles->buildPrompt('studio', 'soft shadow'), ' soft shadow'), 'extra wishes appended to the prompt');

// ---------------------------------------------------------------- allowed media hosts

$client = new GenmagicClient('k_test_12345678', new FakeTransport([]));

foreach ([
	'https://genmagic.co/a.png' => true,
	'https://GENMAGIC.CO/a.png' => true,
	'https://axrsv1b9sa2ny4qv.public.blob.vercel-storage.com/x/y.webp' => true,
	'https://tlwdqzfibvgektzgdfqa.supabase.co/storage/v1/object/public/a.jpg' => true,
	'https://genmagic.co:443/a.png' => true,
	'http://genmagic.co/a.png' => false,
	'https://genmagic.co:8443/a.png' => false,
	'https://evil.example/a.png' => false,
	'https://genmagic.co.evil.example/a.png' => false,
	'https://evilgenmagic.co/a.png' => false,
	'https://user:pw@genmagic.co/a.png' => false,
	'https://genmagic.co@evil.example/a.png' => false,
	'https://evil.example\\@genmagic.co/a.png' => false,
	'https://genmagic.co /a.png' => false,
	"https://genmagic.co/a\n.png" => false,
	'//genmagic.co/a.png' => false,
	'/a.png' => false,
	'ftp://genmagic.co/a.png' => false,
	'' => false
] as $url => $expected) {
	check($client->isAllowedMediaUrl((string)$url) === $expected, 'media url ' . var_export((string)$url, true) . ' is ' . ($expected ? 'allowed' : 'refused'));
}

// ---------------------------------------------------------------- models

$models_body = json_encode(['data' => [
	['id' => 'a/photo-model', 'name' => '<b>Photo</b> model', 'input_modalities' => ['text', 'image'], 'pricing' => ['est_per_generation_usd' => 0.04]],
	['id' => 'b/text-only', 'name' => 'Text only', 'input_modalities' => ['text']],
	['id' => 'bad id with spaces', 'input_modalities' => ['image']],
	['id' => 'c/no-price', 'input_modalities' => ['image']]
]]);

$t = new FakeTransport([['body' => $models_body]]);
$models = (new GenmagicClient('', $t))->getEditingModels();

check(count($models) === 2 && $models[0]['id'] === 'a/photo-model' && $models[1]['id'] === 'c/no-price', 'models: only photo-capable models with a valid id are kept');
check($models[0]['name'] === 'Photo model' && $models[0]['est_usd'] === 0.04 && $models[1]['est_usd'] === null, 'models: name cleaned, price kept');
check($t->calls[0]['method'] === 'GET' && $t->calls[0]['url'] === 'https://genmagic.co/api/v1/models?category=image', 'models: GET /models?category=image on the GenMagic base');
check(!$t->authorizationSeen(0), 'models: no key sent');

check(throws(fn() => (new GenmagicClient('', new FakeTransport([['body' => '{"data":[]}']])))->getEditingModels()) !== null, 'models: an empty list is an error');

// ---------------------------------------------------------------- create image

$ok_reply = ['status' => 200, 'headers' => ['X-Cost-Cents' => '4', 'X-Credits-Remaining' => '1234.5'], 'body' => '{"data":[{"url":"https://genmagic.co/out/a.png"}]}'];

$t = new FakeTransport([$ok_reply]);
$made = (new GenmagicClient('k_test_12345678', $t))->createImage('the prompt', 'a/photo-model', '1024x1024', 'data:image/png;base64,AAAA');
$sent = json_decode((string)$t->calls[0]['body'], true);

check($t->calls[0]['method'] === 'POST' && $t->calls[0]['url'] === 'https://genmagic.co/api/v1/images/generations', 'create: POST /images/generations');
check($sent === ['prompt' => 'the prompt', 'model' => 'a/photo-model', 'size' => '1024x1024', 'image' => 'data:image/png;base64,AAAA'], 'create: body has prompt, model, size and the data:image URL');
check(in_array('Authorization: Bearer k_test_12345678', $t->calls[0]['headers'], true), 'create: Bearer key sent');
check(in_array('Content-Type: application/json', $t->calls[0]['headers'], true), 'create: JSON content type');
check($made['url'] === 'https://genmagic.co/out/a.png' && $made['cost_cents'] === 4.0 && $made['credits_cents'] === 1234.5, 'create: url, cost and balance read from the reply and its x-cost-cents and x-credits-remaining headers');

check(throws(fn() => (new GenmagicClient('', new FakeTransport([])))->createImage('p', 'm', 's', 'i')) !== null, 'create: no key, no request');

$e = throws(fn() => (new GenmagicClient('k_test_12345678', new FakeTransport([['status' => 200, 'body' => '{"data":[{"url":"https://evil.example/a.png"}]}']])))->createImage('p', 'm', 's', 'i'));
check($e !== null, 'create: a result on a foreign host is refused');

$t = new FakeTransport([['status' => 402, 'body' => '{"error":{"message":"Insufficient credits: add some","code":"insufficient_credits"}}']]);
$e = throws(fn() => (new GenmagicClient('k_test_12345678', $t))->createImage('p', 'm', 's', 'i'));
check($e !== null && $e->isInsufficientCredit() && str_contains($e->getMessage(), 'balance is too low') && str_contains($e->getMessage(), 'genmagic.co/pricing'), 'create: HTTP 402 gives the plain balance-too-low message with genmagic.co/pricing');
check($e !== null && !str_contains($e->getMessage(), 'Insufficient credits: add some'), 'create: 402 shows the plain message, not the raw reply');

$e = throws(fn() => (new GenmagicClient('k_test_12345678', new FakeTransport([['status' => 400, 'body' => '{"error":{"message":"The prompt <b>was</b> refused","code":"content_policy"}}']])))->createImage('p', 'm', 's', 'i'));
check($e !== null && $e->getMessage() === 'The prompt was refused' && !$e->isInsufficientCredit(), 'create: GenMagic own error text shown (tags stripped)');

$e = throws(fn() => (new GenmagicClient('k_test_12345678', new FakeTransport([['status' => 500, 'body' => 'oops']])))->createImage('p', 'm', 's', 'i'));
check($e !== null && str_contains($e->getMessage(), 'HTTP 500'), 'create: an error without text names the HTTP status');

$e = throws(fn() => (new GenmagicClient('k_test_12345678', new FakeTransport([new \RuntimeException('connection timed out')])))->createImage('p', 'm', 's', 'i'));
check($e !== null && str_contains($e->getMessage(), 'Could not reach GenMagic'), 'create: a network failure is reported');

// ---------------------------------------------------------------- download

$png = "\x89PNG\r\n\x1A\n" . str_repeat('x', 100);

$t = new FakeTransport([
	['status' => 302, 'headers' => ['Location' => 'https://axrsv1b9sa2ny4qv.public.blob.vercel-storage.com/x.png']],
	['status' => 200, 'body' => $png]
]);
$got = (new GenmagicClient('k_test_12345678', $t))->download('https://genmagic.co/out/a.png', 1000000);

check($got === $png && count($t->calls) === 2, 'download: a redirect between GenMagic hosts is followed by hand');
check(!$t->authorizationSeen(0) && !$t->authorizationSeen(1), 'download: the key is never sent');
check($t->calls[1]['url'] === 'https://axrsv1b9sa2ny4qv.public.blob.vercel-storage.com/x.png', 'download: second hop went to the redirect target');

$t = new FakeTransport([['status' => 302, 'headers' => ['location' => 'https://evil.example/steal']], ['status' => 200, 'body' => $png]]);
$e = throws(fn() => (new GenmagicClient('k_test_12345678', $t))->download('https://genmagic.co/out/a.png', 1000000));
check($e !== null && count($t->calls) === 1, 'download: a redirect to a foreign host is refused before any request');

$t = new FakeTransport(array_fill(0, 6, ['status' => 302, 'headers' => ['Location' => 'https://genmagic.co/loop']]));
check(throws(fn() => (new GenmagicClient('k_test_12345678', $t))->download('https://genmagic.co/out/a.png', 1000000)) !== null && count($t->calls) <= 4, 'download: redirect loops stop after three hops');

check(throws(fn() => (new GenmagicClient('k', new FakeTransport([['status' => 404]])))->download('https://genmagic.co/a.png', 1000)) !== null, 'download: HTTP 404 is an error');
check(throws(fn() => (new GenmagicClient('k', new FakeTransport([['status' => 200, 'body' => str_repeat('a', 50)]])))->download('https://genmagic.co/a.png', 10)) !== null, 'download: a body over the limit is refused');
check(throws(fn() => (new GenmagicClient('k', new FakeTransport([])))->download('https://evil.example/a.png', 1000)) !== null, 'download: a foreign host is refused at once');

// ---------------------------------------------------------------- the real curl transport (only when a local echo server is up)

$echo = $argv[3] ?? '';

if ($echo !== '') {
	$echo = rtrim($echo, '/');

	$real = new GenmagicClient('k');

	$reply = $real->curlTransport('GET', $echo . '/json', ['Accept: application/json'], null, 10, 100000);
	check($reply['status'] === 200 && $reply['body'] === '{"ok":true}' && ($reply['headers']['x-cost-cents'] ?? '') === '7' && ($reply['headers']['x-credits-remaining'] ?? '') === '993', 'curl transport: status, body and lowercase headers');

	$reply = $real->curlTransport('GET', $echo . '/redirect', [], null, 10, 100000);
	check($reply['status'] === 302 && str_ends_with($reply['headers']['location'] ?? '', '/json') && $reply['body'] === '', 'curl transport: a redirect is reported, never followed');

	$reply = $real->curlTransport('POST', $echo . '/echo', ['Authorization: Bearer k_test', 'Content-Type: application/json', 'X-GenMagic-Client: ' . GenmagicClient::CLIENT_ID], '{"a":1}', 10, 100000);
	$seen = json_decode($reply['body'], true) ?: [];
	check(($seen['method'] ?? '') === 'POST' && ($seen['body'] ?? '') === '{"a":1}' && ($seen['authorization'] ?? '') === 'Bearer k_test' && ($seen['client'] ?? '') === 'opencart-product-photos/1.0.0' && ($seen['type'] ?? '') === 'application/json', 'curl transport: POST body, Bearer key and client id reach the server');

	$cut = false;

	try {
		$real->curlTransport('GET', $echo . '/big', [], null, 10, 1000000);
	} catch (\RuntimeException $e) {
		$cut = true;
	}

	check($cut, 'curl transport: a reply over the size limit is cut off');

	$reply = $real->curlTransport('GET', $echo . '/error', [], null, 10, 100000);
	check($reply['status'] === 402, 'curl transport: an error status is returned, not thrown');

	$gone = false;

	try {
		$real->curlTransport('GET', 'http://127.0.0.1:1/', [], null, 3, 1000);
	} catch (\RuntimeException $e) {
		$gone = true;
	}

	check($gone, 'curl transport: an unreachable host throws');
}

// ---------------------------------------------------------------- photo service helpers (no database)

$registry = new \Opencart\System\Engine\Registry();
$service = new GenmagicPhotos($registry);

check($service->detectMime("\xFF\xD8\xFF\xE0abc") === 'image/jpeg' && $service->detectMime("\x89PNG\r\n\x1A\nabc") === 'image/png' && $service->detectMime('RIFF1234WEBPabc') === 'image/webp' && $service->detectMime('GIF89aabc') === 'image/gif' && $service->detectMime('<?php echo 1;') === '', 'image types are told by their first bytes');

$form = file_get_contents($root . 'admin/view/template/catalog/product_form.twig');

check(is_string($form) && str_contains($form, 'id="form-product"'), 'the OpenCart product form still has the form-product marker');

$panel = '<fieldset id="genmagic-photos">PANEL</fieldset>';
$injected = $service->injectPanel((string)$form, $panel);
$form_end = strpos($injected, '</form>', (int)strpos($injected, 'id="form-product"'));

check(substr_count($injected, 'id="genmagic-photos"') === 1 && strpos($injected, 'id="genmagic-photos"') > $form_end, 'the panel is put right after the product form');
check(substr($injected, 0, $form_end) === substr((string)$form, 0, $form_end), 'nothing before the end of the form is changed');
check($service->injectPanel('<div></div><footer>f</footer>', $panel) === '<div></div>' . $panel . "\n" . '<footer>f</footer>', 'without the form the panel goes before the footer');
check($service->injectPanel('<div>nothing</div>', $panel) === '<div>nothing</div>', 'without form or footer the page is left alone');

// ---------------------------------------------------------------- templates (rendered with the Twig OpenCart ships)

require_once($root . 'system/storage/vendor/autoload.php');

$twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader($ext . 'admin/view/template/module'), ['autoescape' => false, 'debug' => true]);

$strings = [];

$_ = [];

require($ext . 'admin/language/en-gb/module/genmagic_photos.php');

$strings = $_;

$text = [];

foreach ($strings as $key => $value) {
	if (str_starts_with($key, 'panel_')) {
		$text['text_' . substr($key, 6)] = $value;
	}
}

$base = $text + [
	'product_id'    => 28,
	'problem'       => '',
	'ready'         => true,
	'settings_url'  => 'https://shop.test/admin/index.php?route=x&user_token=abc',
	'models_url'    => 'https://shop.test/admin/index.php?route=x.models&user_token=abc',
	'generate_url'  => 'https://shop.test/admin/index.php?route=x.generate&user_token=abc',
	'add_url'       => 'https://shop.test/admin/index.php?route=x.add&user_token=abc',
	'styles'        => [['id' => 'studio', 'label' => 'White studio'], ['id' => 'lifestyle', 'label' => 'Lifestyle scene']],
	'default_style' => 'studio',
	'max_extra'     => 300
];

$html = $twig->render('genmagic_photos_panel.twig', $base);

check(str_contains($html, 'id="genmagic-photos"') && str_contains($html, 'id="genmagic-make"') && str_contains($html, 'data-ready="1"'), 'panel renders the form when ready');
check(str_contains($html, 'data-generate-url="https://shop.test/admin/index.php?route=x.generate&amp;user_token=abc"'), 'panel urls are escaped for the attribute');
check(str_contains($html, 'maxlength="300"') && str_contains($html, '<option value="studio" selected>'), 'panel: 300 character limit and default style');
check(!str_contains($html, '{{') && !str_contains($html, '{%'), 'panel has no leftover template syntax');

$hostile = $twig->render('genmagic_photos_panel.twig', ['problem' => '<img src=x onerror=alert(1)>', 'ready' => false, 'text_title' => '<script>alert(2)</script>'] + $base);

check(!str_contains($hostile, '<img src=x') && !str_contains($hostile, '<script>alert(2)') && str_contains($hostile, '&lt;img src=x'), 'panel: text from the server is escaped');
check(str_contains($hostile, 'data-ready="0"') && !str_contains($hostile, 'id="genmagic-make"'), 'panel: a setup problem shows no form');

$settings = $twig->render('genmagic_photos.twig', $strings + [
	'header' => '', 'column_left' => '', 'footer' => '', 'back' => '#', 'save' => 'https://shop.test/save', 'breadcrumbs' => [['text' => 'Home', 'href' => '#']],
	'button_save' => 'Save', 'button_back' => 'Back',
	'module_genmagic_photos_status' => true, 'module_genmagic_photos_consent' => false, 'has_key' => true,
	'key_url' => 'https://genmagic.co/get-api-key', 'pricing_url' => 'https://genmagic.co/pricing'
]);

check(str_contains($settings, 'name="module_genmagic_photos_consent"') && str_contains($settings, 'name="module_genmagic_photos_api_key"') && str_contains($settings, 'name="module_genmagic_photos_status"'), 'settings: status, key and consent fields');
check(!preg_match('/<input[^>]*name="module_genmagic_photos_consent"[^>]*type="checkbox"[^>]*checked/', $settings), 'settings: consent starts unticked when not given');
check(!str_contains($settings, 'value="k_') && str_contains($settings, 'type="password"'), 'settings: the key field is a password field and never holds the saved key');

// ---------------------------------------------------------------- language file and source hygiene

$source = '';

foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($ext . 'admin', \FilesystemIterator::SKIP_DOTS)) as $file) {
	if (in_array($file->getExtension(), ['php', 'twig'], true) && !str_contains($file->getPathname(), '/language/')) {
		$source .= file_get_contents($file->getPathname()) . "\n";
	}
}

$missing = [];

preg_match_all('/(?:language->get|text)\(\'([a-z_]+)\'\)/', $source, $found);

foreach (array_unique($found[1]) as $key) {
	// text_home comes from OpenCart's own admin language file.
	if (!isset($strings[$key]) && $key !== 'text_home') {
		$missing[] = $key;
	}
}

foreach (['title', 'intro', 'style', 'extra', 'extra_help', 'model', 'make', 'working', 'wait', 'cost', 'balance', 'about', 'use_main', 'add', 'discard', 'added', 'settings', 'pricing', 'reload_note', 'preview_alt', 'error'] as $key) {
	if (!isset($strings['panel_' . $key])) {
		$missing[] = 'panel_' . $key;
	}
}

foreach (['studio', 'lifestyle', 'model', 'flatlay', 'seasonal'] as $key) {
	if (!isset($strings['style_' . $key])) {
		$missing[] = 'style_' . $key;
	}
}

check(!$missing, 'every language key the code asks for exists' . ($missing ? ' (missing: ' . implode(', ', $missing) . ')' : ''));
check(!str_contains($source . implode('', $strings), "\u{2014}"), 'no em dash in the extension');
check(!preg_match('/\b(openai|anthropic|google|gemini|nano banana|midjourney|stability|flux|ideogram|recraft|bytedance|seedream|imagen|dall-?e)\b/i', implode(' ', $strings)), 'no AI vendor or provider named in merchant-facing language text');

echo "\n" . ($checks - $failures) . '/' . $checks . " checks passed\n";

exit($failures ? 1 : 0);
