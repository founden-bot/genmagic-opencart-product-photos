<?php
namespace Opencart\Admin\Model\Extension\Genmagic\Module;
/**
 * Class GenmagicException
 *
 * A failure whose message is safe to show to the merchant. It is GenMagic's own text when the service gave one,
 * because that text says what to do.
 *
 * @package Opencart\Admin\Model\Extension\Genmagic\Module
 */
class GenmagicException extends \Exception {
	/**
	 * @var int
	 */
	private int $status;

	/**
	 * @var string
	 */
	private string $reason;

	/**
	 * Constructor
	 *
	 * @param string $message
	 * @param int    $status  HTTP status GenMagic answered with, 0 when it was not reached or it was a local check.
	 * @param string $reason  GenMagic's error code, when it gave one.
	 */
	public function __construct(string $message, int $status = 0, string $reason = '') {
		parent::__construct($message);

		$this->status = $status;
		$this->reason = $reason;
	}

	/**
	 * Get Status
	 *
	 * @return int
	 */
	public function getStatus(): int {
		return $this->status;
	}

	/**
	 * Whether GenMagic refused because the balance is too low.
	 *
	 * @return bool
	 */
	public function isInsufficientCredit(): bool {
		return $this->status === 402 || str_contains($this->reason, 'insufficient');
	}
}
