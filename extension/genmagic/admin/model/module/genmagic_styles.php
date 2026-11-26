<?php
namespace Opencart\Admin\Model\Extension\Genmagic\Module;
/**
 * Class GenmagicStyles
 *
 * The photo styles and the prompt each one sends. Every style tells the service to keep the product exactly as it is.
 *
 * @package Opencart\Admin\Model\Extension\Genmagic\Module
 */
class GenmagicStyles {
	public const MAX_EXTRA_LENGTH = 300;
	public const DEFAULT_STYLE = 'studio';

	private const KEEP = 'Keep the product itself exactly as in the photo: same shape, colors, materials, label, logo and proportions.';

	/**
	 * Prompts
	 *
	 * @return array<string, string>
	 */
	private function prompts(): array {
		return [
			'studio'    => 'Professional e-commerce product photo on a clean white seamless background, soft studio lighting, gentle shadow. ' . self::KEEP,
			'lifestyle' => 'Lifestyle product photo of this product in a tasteful real-life setting where it is used, natural light, shallow depth of field. ' . self::KEEP,
			'model'     => 'A person naturally using or wearing this product in a bright, modern setting, editorial photo. ' . self::KEEP,
			'flatlay'   => 'Top-down flat lay product photo with complementary props on a styled surface, even soft light. ' . self::KEEP,
			'seasonal'  => 'Seasonal holiday product photo with festive but uncluttered decoration around the product, warm light. ' . self::KEEP
		];
	}

	/**
	 * Get Ids
	 *
	 * @return array<int, string>
	 */
	public function getIds(): array {
		return array_keys($this->prompts());
	}

	/**
	 * Is Valid
	 *
	 * @param string $style
	 *
	 * @return bool
	 */
	public function isValid(string $style): bool {
		return array_key_exists($style, $this->prompts());
	}

	/**
	 * Build Prompt
	 *
	 * The prompt for a style plus the merchant's own words (at most 300 characters).
	 *
	 * @param string $style
	 * @param string $extra
	 *
	 * @return string
	 */
	public function buildPrompt(string $style, string $extra): string {
		$prompts = $this->prompts();

		$base = $prompts[$style] ?? $prompts[self::DEFAULT_STYLE];

		$more = $this->cleanExtra($extra);

		return $more !== '' ? $base . ' ' . $more : $base;
	}

	/**
	 * Clean Extra
	 *
	 * Plain text of the merchant's own words, cut to the allowed length. OpenCart HTML-escapes everything it reads
	 * from a request, so the text is decoded first.
	 *
	 * @param string $extra
	 *
	 * @return string
	 */
	public function cleanExtra(string $extra): string {
		$text = strip_tags(html_entity_decode($extra, ENT_QUOTES, 'UTF-8'));
		$text = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

		return mb_substr(trim($text), 0, self::MAX_EXTRA_LENGTH);
	}
}
