<?php

/**
 * Append currency to an amount.
 *
 * @param float $amount The amount to append currency to.
 * @param array $options The options array.
 * @param bool $nbsp Whether to replace spaces with non-breaking spaces.
 * @param string $currency_key The currency key to use.
 * @return string The amount with currency appended.
 */
function append_currency( $amount, $options, $nbsp = true, $currency_key = false ) {
    $amount = floatval( $amount );

    $currencies = CampTix_Currency::get_currency_list();

    if ( ! $currency_key ) {
        if ( isset( $options['currency'] ) ) {
            $currency_key = $options['currency'];
        } else {
            $currency_key = 'USD';
        }
    }

    $currency = $currencies[ $currency_key ];

    if ( ! isset( $currency['decimal_point'] ) ) {
        $currency['decimal_point'] = 2;
    }

    if ( isset( $currency['locale'] ) ) {
        $formatter        = new NumberFormatter( $currency['locale'], NumberFormatter::CURRENCY );
        $formatted_amount = $formatter->format( $amount );
    } elseif ( isset( $currency['format'] ) && $currency['format'] ) {
        $formatted_amount = sprintf( $currency['format'], number_format( $amount, $currency['decimal_point'] ) );
    } else {
        $formatted_amount = $currency_key . ' ' . number_format( $amount, $currency['decimal_point'] );
    }

    $formatted_amount = apply_filters( 'tix_append_currency', $formatted_amount, $currency, $amount );

    if ( $nbsp ) {
        $formatted_amount = str_replace( ' ', '&nbsp;', $formatted_amount );
    }

    return $formatted_amount;
}
