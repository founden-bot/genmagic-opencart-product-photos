<?php
namespace Opencart\Admin\Model\Extension\Genmagic\Module;

require_once(__DIR__ . '/genmagic_exception.php');
require_once(__DIR__ . '/genmagic_client.php');
require_once(__DIR__ . '/genmagic_styles.php');
/**
 * Class GenmagicPhotos
 *
 * Makes a new photo of a product from its main image and adds a chosen result to the product's additional images.
 *
 * A result is kept as a temporary file in image/catalog/genmagic/tmp until the merchant adds it (or it is cleaned up
 * after a day). It is named after the product and the admin user, so only the same user can add it, to the same
 * product. Nothing is sent to GenMagic unless the extension is switched on, the consent box is ticked and a key is
 * saved.
 *
 * Can be loaded using $this->load->model('extension/genmagic/module/genmagic_photos');
 *
 * @package Opencart\Admin\Model\Extension\Genmagic\Module
 */
class GenmagicPhotos extends \Opencart\System\Engine\Model {
	public const LANGUAGE = 'extension/genmagic/module/genmagic_photos';
	public const DEFAULT_MODEL = 'google/gemini-nano-banana-2.1';
	public const IMAGE_SIZE = '1024x1024';
	// GenMagic takes an inline picture under 4,000,000 characters, and base64 makes a file a third larger.
	public const MAX_SOURCE_BYTES = 2900000;
	public const MAX_RESULT_BYTES = 15000000;
	public const MODELS_CACHE_KEY = 'genmagic_photos_models_v2_image';
	public const MODELS_TTL = 21600;
	public const DIR = 'catalog/genmagic/';
	public const TMP_DIR = 'catalog/genmagic/tmp/';
	public const TMP_MAX_AGE = 86400;

	private const TOKEN_PATTERN = '/^(\d+)-(\d+)-[a-f0-9]{32}\.(jpg|png|webp)$/';

	/**
	 * @var \Opencart\Admin\Model\Extension\Genmagic\Module\GenmagicClient|null
	 */
	private ?GenmagicClient $client = null;

	/**
	 * @var bool
	 */
	private bool $language_loaded = false;

	/**
	 * Set Client
	 *
	 * Replaces the GenMagic client. Used by the tests to put a mocked HTTP transport in place.
	 *
	 * @param \Opencart\Admin\Model\Extension\Genmagic\Module\GenmagicClient $client
	 *
	 * @return void
	 */
	public function setClient(GenmagicClient $client): void {
		$this->client = $client;
	}

	/**
	 * Is Enabled
	 *
	 * @return bool
	 */
	public function isEnabled(): bool {
		return (bool)$this->config->get('module_genmagic_photos_status');
	}

	/**
	 * Has Consent
	 *
	 * @return bool
	 */
	public function hasConsent(): bool {
		return (bool)$this->config->get('module_genmagic_photos_consent');
	}

	/**
	 * Get Api Key
	 *
	 * @return string
	 */
	public function getApiKey(): string {
		return trim((string)$this->config->get('module_genmagic_photos_api_key'));
	}

	/**
	 * Get Setup Problem
	 *
	 * What stops photos from being made, as a sentence for the merchant. An empty string when all is set.
	 *
	 * @return string
	 */
	public function getSetupProblem(): string {
		if (!$this->isEnabled()) {
			return $this->text('error_disabled');
		}

		if (!$this->hasConsent()) {
			return $this->text('error_consent');
		}

		if ($this->getApiKey() === '') {
			return sprintf($this->text('error_key'), GenmagicClient::KEY_URL);
		}

		return '';
	}

	/**
	 * Get Models
	 *
	 * The image models that work from a photo, with their estimated price, and the default pick. The list is kept
	 * for six hours.
	 *
	 * @throws \Opencart\Admin\Model\Extension\Genmagic\Module\GenmagicException
	 *
	 * @return array{models: array<int, array<string, mixed>>, default: string}
	 */
	public function getModels(): array {
		$this->requireSetup(false);

		$models = $this->loadModels();

		$picked = $this->pickModel($models, '');

		return ['models' => $models, 'default' => $picked['id']];
	}

	/**
	 * Generate
	 *
	 * Makes one photo of the product and keeps it as a temporary result.
	 *
	 * @param int    $product_id
	 * @param string $style
	 * @param string $extra      The merchant's own words, optional.
	 * @param string $model_id   Model id, optional.
	 * @param int    $user_id    The admin user making the photo.
	 *
	 * @throws \Opencart\Admin\Model\Extension\Genmagic\Module\GenmagicException
	 *
	 * @return array<string, mixed>
	 */
	public function generate(int $product_id, string $style, string $extra, string $model_id, int $user_id): array {
		$this->requireSetup(true);

		$styles = new GenmagicStyles();

		if (!$styles->isValid($style)) {
			throw new GenmagicException($this->text('error_style'));
		}

		$product = $this->getProduct($product_id);

		$image = $this->getSourceDataUrl((string)$product['image']);

		$model = $this->pickModel($this->loadModels(), html_entity_decode($model_id, ENT_QUOTES, 'UTF-8'));

		$lock = $this->lock($user_id);

		try {
			$generated = $this->client()->createImage($styles->buildPrompt($style, $extra), $model['id'], self::IMAGE_SIZE, $image);

			$bytes = $this->client()->download($generated['url'], self::MAX_RESULT_BYTES);

			$token = $this->storeResult($product_id, $user_id, $bytes);
		} finally {
			$this->unlock($lock);
		}

		return [
			'token'      => $token,
			'path'       => self::TMP_DIR . $token,
			'model_name' => $model['name'],
			'cost_usd'   => $this->centsToUsd($generated['cost_cents']),
			'balance_usd' => $this->centsToUsd($generated['credits_cents'])
		];
	}

	/**
	 * Add To Product
	 *
	 * Adds a result to the product's additional images (a product_image row). The main image is only replaced when
	 * asked for.
	 *
	 * @param int    $product_id
	 * @param string $token       The name of the temporary result.
	 * @param bool   $use_as_main Also use it as the main image.
	 * @param int    $user_id
	 *
	 * @throws \Opencart\Admin\Model\Extension\Genmagic\Module\GenmagicException
	 *
	 * @return array{image: string, main: bool}
	 */
	public function addToProduct(int $product_id, string $token, bool $use_as_main, int $user_id): array {
		$matches = [];

		if (!preg_match(self::TOKEN_PATTERN, $token, $matches) || (int)$matches[1] !== $product_id || (int)$matches[2] !== $user_id) {
			throw new GenmagicException($this->text('error_gone'));
		}

		$source = DIR_IMAGE . self::TMP_DIR . $token;

		if (!is_file($source)) {
			throw new GenmagicException($this->text('error_gone'));
		}

		$this->getProduct($product_id);

		$bytes = (string)file_get_contents($source);

		if (!in_array($this->detectMime($bytes), ['image/jpeg', 'image/png', 'image/webp'], true)) {
			throw new GenmagicException($this->text('error_result_type'));
		}

		$this->makeDirectory(DIR_IMAGE . self::DIR);

		$image = self::DIR . $product_id . '-' . bin2hex(random_bytes(6)) . '.' . $matches[3];

		if (!copy($source, DIR_IMAGE . $image)) {
			throw new GenmagicException($this->text('error_add'));
		}

		try {
			$query = $this->db->query("SELECT MAX(`sort_order`) AS `sort_order` FROM `" . DB_PREFIX . "product_image` WHERE `product_id` = '" . (int)$product_id . "'");

			$sort_order = (int)($query->row['sort_order'] ?? 0) + 1;

			$this->db->query("INSERT INTO `" . DB_PREFIX . "product_image` SET `product_id` = '" . (int)$product_id . "', `image` = '" . $this->db->escape($image) . "', `sort_order` = '" . (int)$sort_order . "'");

			if ($use_as_main) {
				$this->db->query("UPDATE `" . DB_PREFIX . "product` SET `image` = '" . $this->db->escape($image) . "', `date_modified` = NOW() WHERE `product_id` = '" . (int)$product_id . "'");
			}
		} catch (\Throwable $e) {
			unlink(DIR_IMAGE . $image);

			throw new GenmagicException($this->text('error_add'));
		}

		unlink($source);

		return ['image' => $image, 'main' => $use_as_main];
	}

	/**
	 * Inject Panel
	 *
	 * Puts the panel into the rendered product form, right after the product form. If the form cannot be found the
	 * panel goes in front of the page footer, and if that is missing too the page is returned unchanged.
	 *
	 * @param string $output The rendered product form.
	 * @param string $panel
	 *
	 * @return string
	 */
	public function injectPanel(string $output, string $panel): string {
		$start = strpos($output, 'id="form-product"');

		if ($start !== false) {
			$end = strpos($output, '</form>', $start);

			if ($end !== false) {
				$end += strlen('</form>');

				return substr($output, 0, $end) . "\n" . $panel . substr($output, $end);
			}
		}

		$footer = strripos($output, '<footer');

		if ($footer !== false) {
			return substr($output, 0, $footer) . $panel . "\n" . substr($output, $footer);
		}

		return $output;
	}

	/**
	 * Detect Mime
	 *
	 * The image type from the first bytes of a file, an empty string when it is not a supported image.
	 *
	 * @param string $bytes
	 *
	 * @return string
	 */
	public function detectMime(string $bytes): string {
		if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
			return 'image/jpeg';
		}

		if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
			return 'image/png';
		}

		if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
			return 'image/gif';
		}

		if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
			return 'image/webp';
		}

		return '';
	}

	/**
	 * Text
	 *
	 * A line from the extension's language file.
	 *
	 * @param string $key
	 *
	 * @return string
	 */
	private function text(string $key): string {
		if (!$this->language_loaded) {
			$this->load->language(self::LANGUAGE);

			$this->language_loaded = true;
		}

		return $this->language->get($key);
	}

	/**
	 * Client
	 *
	 * @return \Opencart\Admin\Model\Extension\Genmagic\Module\GenmagicClient
	 */
	private function client(): GenmagicClient {
		if ($this->client === null) {
			$this->client = new GenmagicClient($this->getApiKey());
		}

		return $this->client;
	}

	/**
	 * Require Setup
	 *
	 * Nothing leaves the store while the extension is off, the consent box is empty or (for a photo) no key is saved.
	 *
	 * @param bool $needs_key
	 *
	 * @throws \Opencart\Admin\Model\Extension\Genmagic\Module\GenmagicException
	 *
	 * @return void
	 */
	private function requireSetup(bool $needs_key): void {
		if (!$this->isEnabled()) {
			throw new GenmagicException($this->text('error_disabled'));
		}

		if (!$this->hasConsent()) {
			throw new GenmagicException($this->text('error_consent'));
		}

		if ($needs_key && $this->getApiKey() === '') {
			throw new GenmagicException(sprintf($this->text('error_key'), GenmagicClient::KEY_URL));
		}
	}

	/**
	 * Load Models
	 *
	 * @throws \Opencart\Admin\Model\Extension\Genmagic\Module\GenmagicException
	 *
	 * @return array<int, array{id: string, name: string, est_usd: float|null}>
	 */
	private function loadModels(): array {
		$cached = $this->cache->get(self::MODELS_CACHE_KEY);

		if (is_array($cached) && $cached) {
			return $cached;
		}

		$models = $this->client()->getEditingModels();

		$this->cache->set(self::MODELS_CACHE_KEY, $models, self::MODELS_TTL);

		return $models;
	}

	/**
	 * Pick Model
	 *
	 * The requested model when it works from a photo, else the default, else the first that does.
	 *
	 * @param array<int, array{id: string, name: string, est_usd: float|null}> $models
	 * @param string                                                           $requested
	 *
	 * @throws \Opencart\Admin\Model\Extension\Genmagic\Module\GenmagicException
	 *
	 * @return array{id: string, name: string, est_usd: float|null}
	 */
	private function pickModel(array $models, string $requested): array {
		if (!$models) {
			throw new GenmagicException($this->text('error_no_models'));
		}

		foreach ([$requested, self::DEFAULT_MODEL] as $id) {
			foreach ($models as $model) {
				if ($id !== '' && $model['id'] === $id) {
					return $model;
				}
			}

			// A model the merchant picked that cannot work from a photo is refused, never swapped silently.
			if ($id !== '' && $id === $requested) {
				throw new GenmagicException($this->text('error_model'));
			}
		}

		return $models[0];
	}

	/**
	 * Get Product
	 *
	 * @param int $product_id
	 *
	 * @throws \Opencart\Admin\Model\Extension\Genmagic\Module\GenmagicException
	 *
	 * @return array<string, mixed>
	 */
	private function getProduct(int $product_id): array {
		$query = $this->db->query("SELECT `product_id`, `image` FROM `" . DB_PREFIX . "product` WHERE `product_id` = '" . (int)$product_id . "'");

		if ($product_id < 1 || !$query->num_rows) {
			throw new GenmagicException($this->text('error_product'));
		}

		return $query->row;
	}

	/**
	 * Get Source Data Url
	 *
	 * Reads a product's main image file from the image directory and turns it into the data:image URL that is sent to
	 * GenMagic as the picture to work from (so it also works on stores that are not public, such as staging).
	 *
	 * @param string $image Path of the image relative to the image directory.
	 *
	 * @throws \Opencart\Admin\Model\Extension\Genmagic\Module\GenmagicException
	 *
	 * @return string
	 */
	private function getSourceDataUrl(string $image): string {
		$image = html_entity_decode($image, ENT_QUOTES, 'UTF-8');

		if ($image === '' || str_contains($image, '..')) {
			throw new GenmagicException($this->text('error_no_image'));
		}

		$base = realpath(DIR_IMAGE);

		$path = realpath(DIR_IMAGE . $image);

		if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path) || !is_readable($path)) {
			throw new GenmagicException($this->text('error_image_read'));
		}

		$size = (int)filesize($path);

		if ($size <= 0 || $size > self::MAX_SOURCE_BYTES) {
			throw new GenmagicException($this->text('error_image_big'));
		}

		$bytes = (string)file_get_contents($path);

		$mime = $this->detectMime($bytes);

		if ($mime === '') {
			throw new GenmagicException($this->text('error_image_type'));
		}

		return 'data:' . $mime . ';base64,' . base64_encode($bytes);
	}

	/**
	 * Store Result
	 *
	 * Writes a downloaded result to the temporary folder and returns its name.
	 *
	 * @param int    $product_id
	 * @param int    $user_id
	 * @param string $bytes
	 *
	 * @throws \Opencart\Admin\Model\Extension\Genmagic\Module\GenmagicException
	 *
	 * @return string
	 */
	private function storeResult(int $product_id, int $user_id, string $bytes): string {
		$extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$this->detectMime($bytes)] ?? '';

		if ($extension === '') {
			throw new GenmagicException($this->text('error_result_type'));
		}

		$this->makeDirectory(DIR_IMAGE . self::TMP_DIR);

		$this->cleanTmp();

		$token = $product_id . '-' . $user_id . '-' . bin2hex(random_bytes(16)) . '.' . $extension;

		if (file_put_contents(DIR_IMAGE . self::TMP_DIR . $token, $bytes, LOCK_EX) === false) {
			throw new GenmagicException($this->text('error_add'));
		}

		return $token;
	}

	/**
	 * Clean Tmp
	 *
	 * Deletes temporary results older than a day.
	 *
	 * @return void
	 */
	private function cleanTmp(): void {
		foreach (glob(DIR_IMAGE . self::TMP_DIR . '*') ?: [] as $file) {
			if (is_file($file) && (int)filemtime($file) < time() - self::TMP_MAX_AGE) {
				unlink($file);
			}
		}
	}

	/**
	 * Make Directory
	 *
	 * @param string $directory
	 *
	 * @throws \Opencart\Admin\Model\Extension\Genmagic\Module\GenmagicException
	 *
	 * @return void
	 */
	private function makeDirectory(string $directory): void {
		if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
			throw new GenmagicException($this->text('error_add'));
		}
	}

	/**
	 * Lock
	 *
	 * One photo at a time per admin user, so a double click does not pay twice.
	 *
	 * @param int $user_id
	 *
	 * @throws \Opencart\Admin\Model\Extension\Genmagic\Module\GenmagicException
	 *
	 * @return resource|null
	 */
	private function lock(int $user_id) {
		if (!defined('DIR_CACHE') || !is_dir(DIR_CACHE) || !is_writable(DIR_CACHE)) {
			return null;
		}

		$handle = fopen(DIR_CACHE . 'genmagic_photos_' . $user_id . '.lock', 'c');

		if ($handle === false) {
			return null;
		}

		if (!flock($handle, LOCK_EX | LOCK_NB)) {
			fclose($handle);

			throw new GenmagicException($this->text('error_busy'));
		}

		return $handle;
	}

	/**
	 * Unlock
	 *
	 * @param resource|null $handle
	 *
	 * @return void
	 */
	private function unlock($handle): void {
		if (is_resource($handle)) {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}

	/**
	 * Cents To Usd
	 *
	 * Cents as a plain dollar amount, or null when GenMagic did not say.
	 *
	 * @param float|null $cents
	 *
	 * @return string|null
	 */
	private function centsToUsd(?float $cents): ?string {
		return $cents === null ? null : number_format($cents / 100, 2, '.', '');
	}
}
