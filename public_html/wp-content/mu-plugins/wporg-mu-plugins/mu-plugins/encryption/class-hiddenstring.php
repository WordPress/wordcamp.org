<?php
namespace WordPressdotorg\MU_Plugins\Encryption;
/**
 * Class HiddenString. This is a copy of https://github.com/paragonie/hidden-string without the additional dependencies.
 *
 * The purpose of this class is to encapsulate strings and hide their contents
 * from stack traces should an unhandled exception occur.
 *
 * The only things that should be protected:
 * - Passwords
 * - Plaintext (before encryption)
 * - Plaintext (after decryption)
 *
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at http://mozilla.org/MPL/2.0/.
 */
final class HiddenString
{
	/**
	 * @var string
	 */
	protected $internalStringValue = '';

	/**
	 * Disallow the contents from being accessed via __toString()?
	 *
	 * @var bool
	 */
	protected $disallowInline = false;

	/**
	 * Disallow the contents from being accessed via __sleep()?
	 *
	 * @var bool
	 */
	protected $disallowSerialization = false;

	/**
	 * HiddenString constructor.
	 * @param string $value
	 * @param bool $disallowInline
	 * @param bool $disallowSerialization
	 *
	 * @throws \TypeError
	 */
	public function __construct(
		#[\SensitiveParameter]
		string $value,
		bool $disallowInline = true,
		bool $disallowSerialization = true
	) {
		$this->internalStringValue = self::safeStrcpy($value);
		$this->disallowInline = $disallowInline;
		$this->disallowSerialization = $disallowSerialization;
	}

	/**
	 * @param HiddenString $other
	 * @return bool
	 * @throws \TypeError
	 */
	public function equals(HiddenString $other)
	{
		return \hash_equals(
			$this->getString(),
			$other->getString()
		);
	}

	/**
	 * Hide its internal state from var_dump()
	 *
	 * @return array
	 */
	public function __debugInfo()
	{
		return [
			'internalStringValue' =>
				'*',
			'attention' =>
				'If you need the value of a HiddenString, ' .
				'invoke getString() instead of dumping it.'
		];
	}

	/**
	 * Wipe it from memory after it's been used.
	 * @return void
	 */
	public function __destruct()
	{
		if (\is_callable('\sodium_memzero')) {
			try {
				\sodium_memzero($this->internalStringValue);
				return;
			} catch (\Throwable $ex) {
			}
		}
	}

	/**
	 * Explicit invocation -- get the raw string value
	 *
	 * @return string
	 * @throws \TypeError
	 */
	public function getString(): string
	{
		return self::safeStrcpy($this->internalStringValue);
	}

	/**
	 * Returns a copy of the string's internal value, which should be zeroed.
	 * Optionally, it can return an empty string.
	 *
	 * @return string
	 * @throws \TypeError
	 */
	public function __toString(): string
	{
		if (!$this->disallowInline) {
			return self::safeStrcpy($this->internalStringValue);
		}
		return '';
	}

	/**
	 * @return array
	 */
	public function __sleep(): array
	{
		if (!$this->disallowSerialization) {
			return [
				'internalStringValue',
				'disallowInline',
				'disallowSerialization'
			];
		}
		return [];
	}

	/**
	 * PHP 7 uses interned strings. We don't want altering this one to alter
	 * the original string.
	 *
	 * @param string $string
	 * @return string
	 * @throws \TypeError
	 */
	public static function safeStrcpy(string $string): string
	{
		$length = mb_strlen($string, '8bit');
		$return = '';
		/** @var int $chunk */
		$chunk = $length >> 1;
		if ($chunk < 1) {
			$chunk = 1;
		}
		for ($i = 0; $i < $length; $i += $chunk) {
			$return .= mb_substr($string, $i, $chunk, '8bit');
		}
		return $return;
	}
}