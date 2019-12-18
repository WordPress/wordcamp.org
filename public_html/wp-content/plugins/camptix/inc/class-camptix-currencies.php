<?php

class CampTix_Currency {
	/**
	 * @var int
	 */
	public $version = 20180627;

	/**
	 * Generate a canonical list of currencies and their properties.
	 *
	 * TODO: Decide on the format of currency, if we want to show localized format or a standard format, and
	 * then sort the currencies in alphabetical order so that they are easier to find.
	 *
	 * @return array An associative array of currencies.
	 *               Key = ISO 4217 currency code. Value = Array of currency properties.
	 */
	public static function get_currency_list() {
		return array(
			'AED' => array(
				'label'         => __( 'United Arab Emirates Dirham', 'wordcamporg' ),
				'format'        => '%s AED',
				'decimal_point' => 2,
			),
			'AFN' => array(
				'label'         => __( 'Afghan Afghani', 'wordcamporg' ),
				'format'        => 'AFN %s',
				'decimal_point' => 2,
			),
			'ALL' => array(
				'label'         => __( 'Albanian Lek', 'wordcamporg' ),
				'format'        => 'L %s',
				'decimal_point' => 2,
			),
			'AMD' => array(
				'label'         => __( 'Armenian Dram', 'wordcamporg' ),
				'format'        => 'AMD %s',
				'decimal_point' => 2,
			),
			'ANG' => array(
				'label'         => __( 'Netherlands Antillean Guilder', 'wordcamporg' ),
				'format'        => 'ANG %s',
				'decimal_point' => 2,
			),
			'AOA' => array(
				'label'         => __( 'Angolan Kwanza', 'wordcamporg' ),
				'format'        => 'Kz %s',
				'decimal_point' => 2,
			),
			'ARS' => array(
				'label'         => __( 'Argentine Peso', 'wordcamporg' ),
				'format'        => 'ARS %s',
				'decimal_point' => 2,
			),
			'AUD' => array(
				'label'         => __( 'Australian Dollar', 'wordcamporg' ),
				'locale'        => 'en_AU.UTF-8',
				'decimal_point' => 2,
			),
			'AWG' => array(
				'label'         => __( 'Aruban Florin', 'wordcamporg' ),
				'format'        => 'AWG %s',
				'decimal_point' => 2,
			),
			'AZN' => array(
				'label'         => __( 'Azerbaijan Manat', 'wordcamporg' ),
				'format'        => 'AZN %s',
				'decimal_point' => 2,
			),
			'BAM' => array(
				'label'         => __( 'Convertible Mark', 'wordcamporg' ),
				'format'        => 'BAM %s',
				'decimal_point' => 2,
			),
			'BBD' => array(
				'label'         => __( 'Barbados Dollar', 'wordcamporg' ),
				'format'        => 'BBD %s',
				'decimal_point' => 2,
			),
			'BDT' => array(
				'label'         => __( 'Taka', 'wordcamporg' ),
				'format'        => 'BDT %s',
				'decimal_point' => 2,
			),
			'BGN' => array(
				'label'         => __( 'Bulgarian Lev', 'wordcamporg' ),
				'format'        => 'BGN %s',
				'decimal_point' => 2,
			),
			'BIF' => array(
				'label'         => __( 'Burundi Franc', 'wordcamporg' ),
				'format'        => 'BIF %s',
				'decimal_point' => 0,
			),
			'BMD' => array(
				'label'         => __( 'Bermudian Dollar', 'wordcamporg' ),
				'format'        => 'BMD %s',
				'decimal_point' => 2,
			),
			'BND' => array(
				'label'         => __( 'Brunei Dollar', 'wordcamporg' ),
				'format'        => 'BND %s',
				'decimal_point' => 2,
			),
			'BOB' => array(
				'label'         => __( 'Boliviano', 'wordcamporg' ),
				'format'        => 'BOB %s',
				'decimal_point' => 2,
			),
			'BRL' => array(
				'label'         => __( 'Brazilian Real', 'wordcamporg' ),
				'locale'        => 'pt_BR.UTF-8',
				'decimal_point' => 2,
			),
			'BSD' => array(
				'label'         => __( 'Bahamian Dollar', 'wordcamporg' ),
				'format'        => 'BSD %s',
				'decimal_point' => 2,
			),
			'BWP' => array(
				'label'         => __( 'Pula', 'wordcamporg' ),
				'format'        => 'BWP %s',
				'decimal_point' => 2,
			),
			'BZD' => array(
				'label'         => __( 'Belize Dollar', 'wordcamporg' ),
				'format'        => 'BZD %s',
				'decimal_point' => 2,
			),
			'CAD' => array(
				'label'         => __( 'Canadian Dollar', 'wordcamporg' ),
				'locale'        => 'en_CA.UTF-8',
				'decimal_point' => 2,
			),
			'CDF' => array(
				'label'         => __( 'Congolese Franc', 'wordcamporg' ),
				'format'        => 'CDF %s',
				'decimal_point' => 2,
			),
			'CHF' => array(
				'label'         => __( 'Swiss Franc', 'wordcamporg' ),
				'locale'        => 'fr_CH.UTF-8',
				'decimal_point' => 2,
			),
			'CLP' => array(
				'label'         => __( 'Chilean Peso', 'wordcamporg' ),
				'format'        => 'CLP %s',
				'decimal_point' => 0,
			),
			'CNY' => array(
				'label'         => __( 'Yuan Renminbi', 'wordcamporg' ),
				'format'        => 'CNY %s',
				'decimal_point' => 2,
			),
			'COP' => array(
				'label'         => __( 'Colombian Peso', 'wordcamporg' ),
				'format'        => 'COP %s',
				'decimal_point' => 2,
			),
			'CRC' => array(
				'label'         => __( 'Costa Rican Colon', 'wordcamporg' ),
				'format'        => 'CRC %s',
				'decimal_point' => 2,
			),
			'CVE' => array(
				'label'         => __( 'Cabo Verde Escudo', 'wordcamporg' ),
				'format'        => 'CVE %s',
				'decimal_point' => 2,
			),
			'CZK' => array(
				'label'         => __( 'Czech Koruna', 'wordcamporg' ),
				'locale'        => 'hcs_CZ.UTF-8',
				'decimal_point' => 2,
			),
			'DJF' => array(
				'label'         => __( 'Djibouti Franc', 'wordcamporg' ),
				'format'        => 'DJF %s',
				'decimal_point' => 0,
			),
			'DKK' => array(
				'label'         => __( 'Danish Krone', 'wordcamporg' ),
				'locale'        => 'da_DK.UTF-8',
				'decimal_point' => 2,
			),
			'DOP' => array(
				'label'         => __( 'Dominican Peso', 'wordcamporg' ),
				'format'        => 'DOP %s',
				'decimal_point' => 2,
			),
			'DZD' => array(
				'label'         => __( 'Algerian Dinar', 'wordcamporg' ),
				'format'        => 'DZD %s',
				'decimal_point' => 2,
			),
			'EGP' => array(
				'label'         => __( 'Egyptian Pound', 'wordcamporg' ),
				'format'        => 'EGP %s',
				'decimal_point' => 2,
			),
			'ETB' => array(
				'label'         => __( 'Ethiopian Birr', 'wordcamporg' ),
				'format'        => 'ETB %s',
				'decimal_point' => 2,
			),
			'EUR' => array(
				'label'         => __( 'Euro', 'wordcamporg' ),
				'format'        => '€ %s',
				'decimal_point' => 2,
			),
			'FJD' => array(
				'label'         => __( 'Fiji Dollar', 'wordcamporg' ),
				'format'        => 'FJD %s',
				'decimal_point' => 2,
			),
			'FKP' => array(
				'label'         => __( 'Falkland Islands Pound', 'wordcamporg' ),
				'format'        => 'FKP %s',
				'decimal_point' => 2,
			),
			'GBP' => array(
				'label'         => __( 'Pound Sterling', 'wordcamporg' ),
				'locale'        => 'en_GB.UTF-8',
				'decimal_point' => 2,
			),
			'GEL' => array(
				'label'         => __( 'Lari', 'wordcamporg' ),
				'format'        => 'GEL %s',
				'decimal_point' => 2,
			),
			'GIP' => array(
				'label'         => __( 'Gibraltar Pound', 'wordcamporg' ),
				'format'        => 'GIP %s',
				'decimal_point' => 2,
			),
			'GMD' => array(
				'label'         => __( 'Dalasi', 'wordcamporg' ),
				'format'        => 'GMD %s',
				'decimal_point' => 2,
			),
			'GNF' => array(
				'label'         => __( 'Guinean Franc', 'wordcamporg' ),
				'format'        => 'GNF %s',
				'decimal_point' => 0,
			),
			'GTQ' => array(
				'label'         => __( 'Quetzal', 'wordcamporg' ),
				'format'        => 'GTQ %s',
				'decimal_point' => 2,
			),
			'GYD' => array(
				'label'         => __( 'Guyana Dollar', 'wordcamporg' ),
				'format'        => 'GYD %s',
				'decimal_point' => 2,
			),
			'HKD' => array(
				'label'         => __( 'Hong Kong Dollar', 'wordcamporg' ),
				'locale'        => 'zh_HK.UTF-8',
				'decimal_point' => 2,
			),
			'HNL' => array(
				'label'         => __( 'Lempira', 'wordcamporg' ),
				'format'        => 'HNL %s',
				'decimal_point' => 2,
			),
			'HRK' => array(
				'label'         => __( 'Kuna', 'wordcamporg' ),
				'format'        => 'HRK %s',
				'decimal_point' => 2,
			),
			'HTG' => array(
				'label'         => __( 'Gourde', 'wordcamporg' ),
				'format'        => 'HTG %s',
				'decimal_point' => 2,
			),
			'HUF' => array(
				'label'         => __( 'Hungarian Forint', 'wordcamporg' ),
				'locale'        => 'hu_HU.UTF-8',
				'decimal_point' => 2,
			),
			'IDR' => array(
				'label'         => __( 'Rupiah', 'wordcamporg' ),
				'format'        => 'IDR %s',
				'decimal_point' => 2,
			),
			'ILS' => array(
				'label'         => __( 'Israeli New Sheqel', 'wordcamporg' ),
				'locale'        => 'he_IL.UTF-8',
				'decimal_point' => 2,
			),
			'INR' => array(
				'label'         => __( 'Indian Rupee', 'wordcamporg' ),
				'format'        => '₹ %s',
				'decimal_point' => 2,
			),
			'ISK' => array(
				'label'         => __( 'Iceland Krona', 'wordcamporg' ),
				'format'        => 'ISK %s',
				'decimal_point' => 0,
			),
			'JMD' => array(
				'label'         => __( 'Jamaican Dollar', 'wordcamporg' ),
				'format'        => 'JMD %s',
				'decimal_point' => 2,
			),
			'JPY' => array(
				'label'         => __( 'Japanese Yen', 'wordcamporg' ),
				'locale'        => 'ja_JP.UTF-8',
				'decimal_point' => 0,
			),
			'KES' => array(
				'label'         => __( 'Kenyan Shilling', 'wordcamporg' ),
				'format'        => 'KES %s',
				'decimal_point' => 2,
			),
			'KGS' => array(
				'label'         => __( 'Som', 'wordcamporg' ),
				'format'        => 'KGS %s',
				'decimal_point' => 2,
			),
			'KHR' => array(
				'label'         => __( 'Riel', 'wordcamporg' ),
				'format'        => 'KHR %s',
				'decimal_point' => 2,
			),
			'KMF' => array(
				'label'         => __( 'Comorian Franc', 'wordcamporg' ),
				'format'        => 'KMF %s',
				'decimal_point' => 0,
			),
			'KRW' => array(
				'label'         => __( 'Won', 'wordcamporg' ),
				'format'        => 'KRW %s',
				'decimal_point' => 0,
			),
			'KYD' => array(
				'label'         => __( 'Cayman Islands Dollar', 'wordcamporg' ),
				'format'        => 'KYD %s',
				'decimal_point' => 2,
			),
			'KZT' => array(
				'label'         => __( 'Tenge', 'wordcamporg' ),
				'format'        => 'KZT %s',
				'decimal_point' => 2,
			),
			'LAK' => array(
				'label'         => __( 'Lao Kip', 'wordcamporg' ),
				'format'        => 'LAK %s',
				'decimal_point' => 2,
			),
			'LBP' => array(
				'label'         => __( 'Lebanese Pound', 'wordcamporg' ),
				'format'        => 'LBP %s',
				'decimal_point' => 2,
			),
			'LKR' => array(
				'label'         => __( 'Sri Lanka Rupee', 'wordcamporg' ),
				'format'        => 'LKR %s',
				'decimal_point' => 2,
			),
			'LRD' => array(
				'label'         => __( 'Liberian Dollar', 'wordcamporg' ),
				'format'        => 'LRD %s',
				'decimal_point' => 2,
			),
			'LSL' => array(
				'label'         => __( 'Loti', 'wordcamporg' ),
				'format'        => 'LSL %s',
				'decimal_point' => 2,
			),
			'MAD' => array(
				'label'         => __( 'Moroccan Dirham', 'wordcamporg' ),
				'format'        => 'MAD %s',
				'decimal_point' => 2,
			),
			'MDL' => array(
				'label'         => __( 'Moldovan Leu', 'wordcamporg' ),
				'format'        => 'MDL %s',
				'decimal_point' => 2,
			),
			'MGA' => array(
				'label'         => __( 'Malagasy Ariary', 'wordcamporg' ),
				'format'        => 'MGA %s',
				'decimal_point' => 2,
			),
			'MKD' => array(
				'label'         => __( 'Denar', 'wordcamporg' ),
				'format'        => 'MKD %s',
				'decimal_point' => 2,
			),
			'MMK' => array(
				'label'         => __( 'Kyat', 'wordcamporg' ),
				'format'        => 'MMK %s',
				'decimal_point' => 2,
			),
			'MNT' => array(
				'label'         => __( 'Tugrik', 'wordcamporg' ),
				'format'        => 'MNT %s',
				'decimal_point' => 2,
			),
			'MOP' => array(
				'label'         => __( 'Pataca', 'wordcamporg' ),
				'format'        => 'MOP %s',
				'decimal_point' => 2,
			),
			'MRO' => array(
				'label'         => __( 'Mauritanian Ouguiya', 'wordcamporg' ),
				'format'        => 'MRO %s',
				'decimal_point' => 2,
			),
			'MUR' => array(
				'label'         => __( 'Mauritius Rupee', 'wordcamporg' ),
				'format'        => 'MUR %s',
				'decimal_point' => 2,
			),
			'MVR' => array(
				'label'         => __( 'Rufiyaa', 'wordcamporg' ),
				'format'        => 'MVR %s',
				'decimal_point' => 2,
			),
			'MWK' => array(
				'label'         => __( 'Malawi Kwacha', 'wordcamporg' ),
				'format'        => 'MWK %s',
				'decimal_point' => 2,
			),
			'MXN' => array(
				'label'         => __( 'Mexican Peso', 'wordcamporg' ),
				'format'        => '$ %s',
				'decimal_point' => 2,
			),
			'MYR' => array(
				'label'         => __( 'Malaysian Ringgit', 'wordcamporg' ),
				'format'        => 'RM %s',
				'decimal_point' => 2,
			),
			'MZN' => array(
				'label'         => __( 'Mozambique Metical', 'wordcamporg' ),
				'format'        => 'MZN %s',
				'decimal_point' => 2,
			),
			'NAD' => array(
				'label'         => __( 'Namibia Dollar', 'wordcamporg' ),
				'format'        => 'NAD %s',
				'decimal_point' => 2,
			),
			'NGN' => array(
				'label'         => __( 'Naira', 'wordcamporg' ),
				'format'        => 'NGN %s',
				'decimal_point' => 2,
			),
			'NIO' => array(
				'label'         => __( 'Cordoba Oro', 'wordcamporg' ),
				'format'        => 'NIO %s',
				'decimal_point' => 2,
			),
			'NOK' => array(
				'label'         => __( 'Norwegian Krone', 'wordcamporg' ),
				'locale'        => 'no_NO.UTF-8',
				'decimal_point' => 2,
			),
			'NPR' => array(
				'label'         => __( 'Nepalese Rupee', 'wordcamporg' ),
				'format'        => 'NPR %s',
				'decimal_point' => 2,
			),
			'NZD' => array(
				'label'         => __( 'N.Z. Dollar', 'wordcamporg' ),
				'locale'        => 'en_NZ.UTF-8',
				'decimal_point' => 2,
			),
			'PAB' => array(
				'label'         => __( 'Balboa', 'wordcamporg' ),
				'format'        => 'PAB %s',
				'decimal_point' => 2,
			),
			'PEN' => array(
				'label'         => __( 'Sol', 'wordcamporg' ),
				'format'        => 'PEN %s',
				'decimal_point' => 2,
			),
			'PGK' => array(
				'label'         => __( 'Kina', 'wordcamporg' ),
				'format'        => 'PGK %s',
				'decimal_point' => 2,
			),
			'PHP' => array(
				'label'         => __( 'Philippine Peso', 'wordcamporg' ),
				'format'        => '₱ %s',
				'decimal_point' => 2,
			),
			'PKR' => array(
				'label'         => __( 'Pakistani Rupee', 'wordcamporg' ),
				'format'        => '₨ %s',
				'decimal_point' => 2,
			),
			'PLN' => array(
				'label'         => __( 'Polish Zloty', 'wordcamporg' ),
				'locale'        => 'pl_PL.UTF-8',
				'decimal_point' => 2,
			),
			'PYG' => array(
				'label'         => __( 'Guarani', 'wordcamporg' ),
				'format'        => 'PYG %s',
				'decimal_point' => 0,
			),
			'QAR' => array(
				'label'         => __( 'Qatari Rial', 'wordcamporg' ),
				'format'        => 'QAR %s',
				'decimal_point' => 2,
			),
			'RON' => array(
				'label'         => __( 'Romanian Leu', 'wordcamporg' ),
				'format'        => 'RON %s',
				'decimal_point' => 2,
			),
			'RSD' => array(
				'label'         => __( 'Serbian Dinar', 'wordcamporg' ),
				'format'        => 'RSD %s',
				'decimal_point' => 2,
			),
			'RUB' => array(
				'label'         => __( 'Russian Ruble', 'wordcamporg' ),
				'format'        => 'RUB %s',
				'decimal_point' => 2,
			),
			'RWF' => array(
				'label'         => __( 'Rwanda Franc', 'wordcamporg' ),
				'format'        => 'RWF %s',
				'decimal_point' => 0,
			),
			'SAR' => array(
				'label'         => __( 'Saudi Riyal', 'wordcamporg' ),
				'format'        => 'SAR %s',
				'decimal_point' => 2,
			),
			'SBD' => array(
				'label'         => __( 'Solomon Islands Dollar', 'wordcamporg' ),
				'format'        => 'SBD %s',
				'decimal_point' => 2,
			),
			'SCR' => array(
				'label'         => __( 'Seychelles Rupee', 'wordcamporg' ),
				'format'        => 'SCR %s',
				'decimal_point' => 2,
			),
			'SEK' => array(
				'label'         => __( 'Swedish Krona', 'wordcamporg' ),
				'locale'        => 'sv_SE.UTF-8',
				'decimal_point' => 2,
			),
			'SGD' => array(
				'label'         => __( 'Singapore Dollar', 'wordcamporg' ),
				'format'        => '$ %s',
				'decimal_point' => 2,
			),
			'SHP' => array(
				'label'         => __( 'Saint Helena Pound', 'wordcamporg' ),
				'format'        => 'SHP %s',
				'decimal_point' => 2,
			),
			'SLL' => array(
				'label'         => __( 'Leone', 'wordcamporg' ),
				'format'        => 'SLL %s',
				'decimal_point' => 2,
			),
			'SOS' => array(
				'label'         => __( 'Somali Shilling', 'wordcamporg' ),
				'format'        => 'SOS %s',
				'decimal_point' => 2,
			),
			'SRD' => array(
				'label'         => __( 'Surinam Dollar', 'wordcamporg' ),
				'format'        => 'SRD %s',
				'decimal_point' => 2,
			),
			'SZL' => array(
				'label'         => __( 'Lilangeni', 'wordcamporg' ),
				'format'        => 'SZL %s',
				'decimal_point' => 2,
			),
			'THB' => array(
				'label'         => __( 'Thai Baht', 'wordcamporg' ),
				'format'        => '฿ %s',
				'decimal_point' => 2,
			),
			'TJS' => array(
				'label'         => __( 'Somoni', 'wordcamporg' ),
				'format'        => 'TJS %s',
				'decimal_point' => 2,
			),
			'TOP' => array(
				'label'         => __( 'Pa’anga', 'wordcamporg' ),
				'format'        => 'TOP %s',
				'decimal_point' => 2,
			),
			'TRY' => array(
				'label'         => __( 'Turkish Lira', 'wordcamporg' ),
				'locale'        => 'tr_TR.UTF-8',
				'decimal_point' => 2,
			),
			'TTD' => array(
				'label'         => __( 'Trinidad and Tobago Dollar', 'wordcamporg' ),
				'format'        => 'TTD %s',
				'decimal_point' => 2,
			),
			'TWD' => array(
				'label'         => __( 'New Taiwan Dollar', 'wordcamporg' ),
				'locale'        => 'zh_TW.UTF-8',
				'decimal_point' => 2,
			),
			'TZS' => array(
				'label'         => __( 'Tanzanian Shilling', 'wordcamporg' ),
				'format'        => 'TZS %s',
				'decimal_point' => 2,
			),
			'UAH' => array(
				'label'         => __( 'Hryvnia', 'wordcamporg' ),
				'format'        => 'UAH %s',
				'decimal_point' => 2,
			),
			'UGX' => array(
				'label'         => __( 'Uganda Shilling', 'wordcamporg' ),
				'format'        => 'UGX %s',
				'decimal_point' => 0,
			),
			'USD' => array(
				'label'         => __( 'U.S. Dollar', 'wordcamporg' ),
				'locale'        => 'en_US.UTF-8',
				'decimal_point' => 2,
			),
			'UYU' => array(
				'label'         => __( 'Peso Uruguayo', 'wordcamporg' ),
				'format'        => 'UYU %s',
				'decimal_point' => 2,
			),
			'UZS' => array(
				'label'         => __( 'Uzbekistan Sum', 'wordcamporg' ),
				'format'        => 'UZS %s',
				'decimal_point' => 2,
			),
			'VND' => array(
				'label'         => __( 'Dong', 'wordcamporg' ),
				'format'        => 'VND %s',
				'decimal_point' => 0,
			),
			'VUV' => array(
				'label'         => __( 'Vatu', 'wordcamporg' ),
				'format'        => 'VUV %s',
				'decimal_point' => 0,
			),
			'WST' => array(
				'label'         => __( 'Tala', 'wordcamporg' ),
				'format'        => 'WST %s',
				'decimal_point' => 2,
			),
			'XAF' => array(
				'label'         => __( 'CFA Franc BEAC', 'wordcamporg' ),
				'format'        => 'XAF %s',
				'decimal_point' => 0,
			),
			'XCD' => array(
				'label'         => __( 'East Caribbean Dollar', 'wordcamporg' ),
				'format'        => 'XCD %s',
				'decimal_point' => 2,
			),
			'XOF' => array(
				'label'         => __( 'CFA Franc BCEAO', 'wordcamporg' ),
				'format'        => 'XOF %s',
				'decimal_point' => 0,
			),
			'XPF' => array(
				'label'         => __( 'CFP Franc', 'wordcamporg' ),
				'format'        => 'XPF %s',
				'decimal_point' => 0,
			),
			'YER' => array(
				'label'         => __( 'Yemeni Rial', 'wordcamporg' ),
				'format'        => 'YER %s',
				'decimal_point' => 2,
			),
			'ZAR' => array(
				'label'         => __( 'South African Rand', 'wordcamporg' ),
				'format'        => 'R %s',
				'decimal_point' => 2,
			),
			'ZMW' => array(
				'label'         => __( 'Zambian Kwacha', 'wordcamporg' ),
				'format'        => 'ZMW %s',
				'decimal_point' => 2,
			),
		);
	}

	/**
	 * Get the list of ISO currency codes supported by currently-enabled payment methods.
	 *
	 * Supported currencies are added via filter by different payment gateway addons/plugins. Addons should have
	 * `$supported_currencies` variable defined with list of currencies that they support in ISO format.
	 *
	 * @return array The list of currency codes.
	 */
	protected static function get_supported_currency_list() {
		return apply_filters( 'camptix_supported_currencies', array() );
	}

	/**
	 * Get an associative array of currencies and their properties that are supported by currently-enabled payment gateways.
	 *
	 * Returns all the currencies that are supported by loaded payment addons, which are also defined
	 * in `get_currency_list` method above.
	 *
	 * @return array The list of currencies, with their properties, which are currently supported.
	 */
	public static function get_currencies() {
		// from https://stackoverflow.com/a/4260168/1845153
		$supported_currencies = array_intersect_key(
			self::get_currency_list(),
			array_flip( self::get_supported_currency_list() )
		);

		$currencies = apply_filters( 'camptix_currencies', $supported_currencies );

		return $currencies;
	}
}
