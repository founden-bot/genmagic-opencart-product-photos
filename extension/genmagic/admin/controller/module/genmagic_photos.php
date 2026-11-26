<?php
namespace Opencart\Admin\Controller\Extension\Genmagic\Module;

require_once(DIR_EXTENSION . 'genmagic/admin/model/module/genmagic_exception.php');
/**
 * Class GenmagicPhotos
 *
 * The settings page of GenMagic AI Product Photos, the admin routes the product page panel calls, and the event
 * handler that puts the panel on the product edit form (an OpenCart 4 event, so no core file is changed).
 *
 * Every route that spends money or reads a model list checks the admin user token and the modify permission.
 *
 * @package Opencart\Admin\Controller\Extension\Genmagic\Module
 */
class GenmagicPhotos extends \Opencart\System\Engine\Controller {
	public const ROUTE = 'extension/genmagic/module/genmagic_photos';
	public const EVENT_CODE = 'genmagic_photos_panel';
	public const EVENT_TRIGGER = 'admin/view/catalog/product_form/after';

	/**
	 * Index
	 *
	 * The settings page.
	 *
	 * @return void
	 */
	public function index(): void {
		$this->load->language(self::ROUTE);

		$this->document->setTitle($this->language->get('heading_title'));

		$data['breadcrumbs'] = [];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module')
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link(self::ROUTE, 'user_token=' . $this->session->data['user_token'])
		];

		$data['save'] = $this->url->link(self::ROUTE . '.save', 'user_token=' . $this->session->data['user_token']);
		$data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module');

		$data['module_genmagic_photos_status'] = (bool)$this->config->get('module_genmagic_photos_status');
		$data['module_genmagic_photos_consent'] = (bool)$this->config->get('module_genmagic_photos_consent');

		// The saved key is never sent back to the browser.
		$data['has_key'] = trim((string)$this->config->get('module_genmagic_photos_api_key')) !== '';

		$data['key_url'] = 'https://genmagic.co/get-api-key';
		$data['pricing_url'] = 'https://genmagic.co/pricing';

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view(self::ROUTE, $data));
	}

	/**
	 * Save
	 *
	 * @return void
	 */
	public function save(): void {
		$this->load->language(self::ROUTE);

		$json = [];

		if (!$this->user->hasPermission('modify', self::ROUTE)) {
			$json['error']['warning'] = $this->language->get('error_permission');
		}

		$required = [
			'module_genmagic_photos_status'  => 0,
			'module_genmagic_photos_consent' => 0,
			'module_genmagic_photos_api_key' => ''
		];

		$post_info = $this->request->post + $required;

		// OpenCart HTML-escapes what it reads from a request.
		$key = trim(html_entity_decode((string)$post_info['module_genmagic_photos_api_key'], ENT_QUOTES, 'UTF-8'));

		if ($key === '') {
			// Left empty: the saved key stays.
			$key = trim((string)$this->config->get('module_genmagic_photos_api_key'));
		} elseif (!preg_match('/^[\x21-\x7E]{8,200}$/', $key)) {
			$json['error']['api_key'] = $this->language->get('error_api_key');
		}

		if ($post_info['module_genmagic_photos_status'] && $key === '') {
			$json['error']['api_key'] = $this->language->get('error_api_key_required');
		}

		if (!$json) {
			$this->load->model('setting/setting');

			$this->model_setting_setting->editSetting('module_genmagic_photos', [
				'module_genmagic_photos_status'  => (int)(bool)$post_info['module_genmagic_photos_status'],
				'module_genmagic_photos_consent' => (int)(bool)$post_info['module_genmagic_photos_consent'],
				'module_genmagic_photos_api_key' => $key
			]);

			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Install
	 *
	 * Called when the merchant installs the module under Extensions. Registers the event that adds the panel to the
	 * product edit form.
	 *
	 * @return void
	 */
	public function install(): void {
		if ($this->user->hasPermission('modify', 'extension/module')) {
			$this->load->model('setting/event');

			$this->model_setting_event->deleteEventByCode(self::EVENT_CODE);

			$this->model_setting_event->addEvent([
				'code'        => self::EVENT_CODE,
				'description' => 'Adds the GenMagic photos panel to the product edit form.',
				'trigger'     => self::EVENT_TRIGGER,
				'action'      => self::ROUTE . '.panel',
				'status'      => true,
				'sort_order'  => 0
			]);
		}
	}

	/**
	 * Uninstall
	 *
	 * @return void
	 */
	public function uninstall(): void {
		if ($this->user->hasPermission('modify', 'extension/module')) {
			$this->load->model('setting/event');

			$this->model_setting_event->deleteEventByCode(self::EVENT_CODE);

			$this->load->model('setting/setting');

			$this->model_setting_setting->deleteSetting('module_genmagic_photos');
		}
	}

	/**
	 * Panel
	 *
	 * Event handler for admin/view/catalog/product_form/after. Adds the GenMagic photos panel after the product form.
	 * Only users who may change this extension see it.
	 *
	 * @param string $route
	 * @param array<string, mixed> $data
	 * @param mixed $output
	 *
	 * @return void
	 */
	public function panel(string &$route, array &$data, &$output): void {
		if (!is_string($output) || $output === '' || !$this->config->get('module_genmagic_photos_status')) {
			return;
		}

		if (!$this->user->hasPermission('modify', self::ROUTE)) {
			return;
		}

		// The page is already rendered, but the language data is shared, so it is put back as it was.
		$backup = $this->language->all();

		$this->load->language(self::ROUTE);

		$this->load->model(self::ROUTE);

		$token = 'user_token=' . $this->session->data['user_token'];

		$product_id = (int)($data['product_id'] ?? 0);

		$panel = [];

		$panel['product_id'] = $product_id;

		if ($product_id < 1) {
			$panel['problem'] = $this->language->get('panel_save_first');
		} else {
			$panel['problem'] = $this->model_extension_genmagic_module_genmagic_photos->getSetupProblem();
		}

		$panel['ready'] = $panel['problem'] === '';

		$panel['settings_url'] = $this->url->link(self::ROUTE, $token, true);
		$panel['models_url'] = $this->url->link(self::ROUTE . '.models', $token, true);
		$panel['generate_url'] = $this->url->link(self::ROUTE . '.generate', $token, true);
		$panel['add_url'] = $this->url->link(self::ROUTE . '.add', $token, true);

		$panel['styles'] = [];

		$styles = new \Opencart\Admin\Model\Extension\Genmagic\Module\GenmagicStyles();

		foreach ($styles->getIds() as $style) {
			$panel['styles'][] = [
				'id'    => $style,
				'label' => $this->language->get('style_' . $style)
			];
		}

		$panel['default_style'] = $styles::DEFAULT_STYLE;
		$panel['max_extra'] = $styles::MAX_EXTRA_LENGTH;

		foreach (['title', 'intro', 'style', 'extra', 'extra_help', 'model', 'make', 'working', 'wait', 'cost', 'balance', 'about', 'use_main', 'add', 'discard', 'added', 'settings', 'pricing', 'reload_note', 'preview_alt', 'error'] as $key) {
			$panel['text_' . $key] = $this->language->get('panel_' . $key);
		}

		$output = $this->model_extension_genmagic_module_genmagic_photos->injectPanel($output, $this->load->view(self::ROUTE . '_panel', $panel));

		$this->language->clear();

		foreach ($backup as $key => $value) {
			$this->language->set($key, $value);
		}
	}

	/**
	 * Models
	 *
	 * The image models that work from a photo. Admin route for the panel.
	 *
	 * @return void
	 */
	public function models(): void {
		$this->load->language(self::ROUTE);

		$json = [];

		$error = $this->authorize(false);

		if ($error !== '') {
			$json['error'] = $error;
		}

		if (!$json) {
			$this->load->model(self::ROUTE);

			try {
				$json = ['success' => true] + $this->model_extension_genmagic_module_genmagic_photos->getModels();
			} catch (\Opencart\Admin\Model\Extension\Genmagic\Module\GenmagicException $e) {
				$json['error'] = $e->getMessage();
			} catch (\Throwable $e) {
				$this->log->write('GenMagic product photos (models): ' . $e->getMessage());

				$json['error'] = $this->language->get('error_unexpected');
			}
		}

		$this->respond($json);
	}

	/**
	 * Generate
	 *
	 * Makes one photo of a product from its main image. POST only.
	 *
	 * @return void
	 */
	public function generate(): void {
		$this->load->language(self::ROUTE);

		$json = [];

		$error = $this->authorize(true);

		if ($error !== '') {
			$json['error'] = $error;
		}

		if (!$json) {
			$this->load->model(self::ROUTE);

			try {
				$result = $this->model_extension_genmagic_module_genmagic_photos->generate(
					(int)($this->request->post['product_id'] ?? 0),
					(string)($this->request->post['style'] ?? ''),
					(string)($this->request->post['extra'] ?? ''),
					(string)($this->request->post['model'] ?? ''),
					(int)$this->user->getId()
				);

				$json = [
					'success'     => true,
					'token'       => $result['token'],
					'preview_url' => HTTP_CATALOG . 'image/' . $result['path'],
					'model_name'  => $result['model_name'],
					'cost_usd'    => $result['cost_usd'],
					'balance_usd' => $result['balance_usd']
				];
			} catch (\Opencart\Admin\Model\Extension\Genmagic\Module\GenmagicException $e) {
				$json['error'] = $e->getMessage();

				if ($e->isInsufficientCredit()) {
					$json['pricing_url'] = 'https://genmagic.co/pricing';
				}
			} catch (\Throwable $e) {
				$this->log->write('GenMagic product photos (generate): ' . $e->getMessage());

				$json['error'] = $this->language->get('error_unexpected');
			}
		}

		$this->respond($json);
	}

	/**
	 * Add
	 *
	 * Adds a result to the product's additional images. POST only.
	 *
	 * @return void
	 */
	public function add(): void {
		$this->load->language(self::ROUTE);

		$json = [];

		$error = $this->authorize(true);

		if ($error !== '') {
			$json['error'] = $error;
		}

		if (!$json) {
			$this->load->model(self::ROUTE);

			try {
				$result = $this->model_extension_genmagic_module_genmagic_photos->addToProduct(
					(int)($this->request->post['product_id'] ?? 0),
					(string)($this->request->post['token'] ?? ''),
					!empty($this->request->post['use_as_main']),
					(int)$this->user->getId()
				);

				$json = [
					'success' => true,
					'image'   => $result['image'],
					'url'     => HTTP_CATALOG . 'image/' . $result['image'],
					'main'    => $result['main']
				];
			} catch (\Opencart\Admin\Model\Extension\Genmagic\Module\GenmagicException $e) {
				$json['error'] = $e->getMessage();
			} catch (\Throwable $e) {
				$this->log->write('GenMagic product photos (add): ' . $e->getMessage());

				$json['error'] = $this->language->get('error_unexpected');
			}
		}

		$this->respond($json);
	}

	/**
	 * Authorize
	 *
	 * The admin user token in the address must match the session, the user must be allowed to modify this extension,
	 * and the routes that change something must be called with POST.
	 *
	 * @param bool $post
	 *
	 * @return string An error message, empty when the call may go on.
	 */
	private function authorize(bool $post): string {
		$token = (string)($this->request->get['user_token'] ?? '');

		if ($token === '' || !isset($this->session->data['user_token']) || !hash_equals((string)$this->session->data['user_token'], $token)) {
			return $this->language->get('error_token');
		}

		if (!$this->user->hasPermission('modify', self::ROUTE)) {
			return $this->language->get('error_permission');
		}

		if ($post && ($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
			return $this->language->get('error_method');
		}

		return '';
	}

	/**
	 * Respond
	 *
	 * JSON the panel reads. The characters that matter inside HTML are escaped as well.
	 *
	 * @param array<string, mixed> $json
	 *
	 * @return void
	 */
	private function respond(array $json): void {
		$this->response->addHeader('Content-Type: application/json; charset=utf-8');
		$this->response->setOutput(json_encode($json, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
	}
}
