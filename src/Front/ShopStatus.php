<?php
/**
 * "Open today" block: opening status computed from the shop hours.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Front;

use WaxStore\Catalog\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The server renders a first status; shop-status.js recomputes it in the
 * browser every minute, so a cached page never shows a stale "Open".
 */
final class ShopStatus {

	/**
	 * Weekly schedule: day key => list of [open, close] ("HH:MM").
	 *
	 * @return array<string,array<int,array{0:string,1:string}>>
	 */
	public static function schedule(): array {
		$hours    = Settings::get( 'contact', 'hours' );
		$schedule = array();

		foreach ( array_keys( Settings::DAYS ) as $day ) {
			$schedule[ $day ] = array();
			$value            = is_array( $hours ) ? (string) ( $hours[ $day ] ?? '' ) : '';

			foreach ( array_filter( explode( ',', $value ) ) as $range ) {
				$parts = explode( '-', $range );

				if ( 2 === count( $parts ) ) {
					$schedule[ $day ][] = array( $parts[0], $parts[1] );
				}
			}
		}

		return $schedule;
	}

	/**
	 * Exceptional closure, empty array when none.
	 *
	 * @return array{from?:string,until?:string,note?:string}
	 */
	public static function closure(): array {
		$from  = (string) Settings::get( 'contact', 'closed_from' );
		$until = (string) Settings::get( 'contact', 'closed_until' );

		if ( '' === $from && '' === $until ) {
			return array();
		}

		return array(
			'from'  => '' !== $from ? $from : $until,
			'until' => '' !== $until ? $until : $from,
			'note'  => (string) Settings::get( 'contact', 'closed_note' ),
		);
	}

	/**
	 * Status at a given moment.
	 *
	 * @param \DateTimeImmutable $now Moment, in the shop time zone.
	 * @return array{state:string,text:string}
	 */
	public static function status( \DateTimeImmutable $now ): array {
		$schedule = self::schedule();
		$closure  = self::closure();
		$keys     = array_keys( Settings::DAYS );
		$today    = $now->format( 'Y-m-d' );
		$minutes  = (int) $now->format( 'G' ) * 60 + (int) $now->format( 'i' );
		$day      = $keys[ (int) $now->format( 'N' ) - 1 ];

		if ( $closure && $today >= $closure['from'] && $today <= $closure['until'] ) {
			return self::closure_status( $closure, $now );
		}

		foreach ( $schedule[ $day ] as $index => $range ) {
			$open  = self::to_minutes( $range[0] );
			$close = self::to_minutes( $range[1] );

			if ( $minutes < $open ) {
				return array(
					'state' => 'closed',
					/* translators: %s: opening time. */
					'text'  => sprintf( 0 === $index ? __( 'Opens today at %s', 'wax-store-catalog' ) : __( 'Closed now, reopens at %s', 'wax-store-catalog' ), self::time_label( $range[0] ) ),
				);
			}

			if ( $minutes < $close ) {
				return array(
					'state' => 'open',
					/* translators: %s: closing time. */
					'text'  => sprintf( __( 'Open today until %s', 'wax-store-catalog' ), self::time_label( $range[1] ) ),
				);
			}
		}

		for ( $offset = 1; $offset <= 7; $offset++ ) {
			$date = $now->modify( '+' . $offset . ' day' );
			$key  = $keys[ (int) $date->format( 'N' ) - 1 ];
			$iso  = $date->format( 'Y-m-d' );

			if ( ! $schedule[ $key ] || ( $closure && $iso >= $closure['from'] && $iso <= $closure['until'] ) ) {
				continue;
			}

			$time = self::time_label( $schedule[ $key ][0][0] );

			return array(
				'state' => 'closed',
				'text'  => 1 === $offset
					/* translators: %s: opening time. */
					? sprintf( __( 'Closed, opens tomorrow at %s', 'wax-store-catalog' ), $time )
					/* translators: 1: day name, 2: opening time. */
					: sprintf( __( 'Closed, opens %1$s at %2$s', 'wax-store-catalog' ), self::day_names()[ $key ], $time ),
			);
		}

		// Nothing within a week: a closure is about to start.
		if ( $closure && $closure['from'] > $today ) {
			return self::closure_status( $closure, $now );
		}

		return array(
			'state' => 'closed',
			'text'  => __( 'Closed', 'wax-store-catalog' ),
		);
	}

	/**
	 * "Closed, reopens on August 26 (summer holidays)".
	 *
	 * @param array              $closure Closure.
	 * @param \DateTimeImmutable $now     Now.
	 */
	private static function closure_status( array $closure, \DateTimeImmutable $now ): array {
		$reopen = new \DateTimeImmutable( $closure['until'] . ' +1 day', $now->getTimezone() );
		$text   = sprintf(
			/* translators: %s: reopening date, e.g. "August 26". */
			__( 'Closed, reopens on %s', 'wax-store-catalog' ),
			wp_date( _x( 'F j', 'reopening date', 'wax-store-catalog' ), $reopen->getTimestamp(), $now->getTimezone() )
		);

		return array(
			'state' => 'closed',
			'text'  => '' !== $closure['note'] ? $text . ' (' . $closure['note'] . ')' : $text,
		);
	}

	/**
	 * "10:30" => 630.
	 *
	 * @param string $time Time.
	 */
	private static function to_minutes( string $time ): int {
		list( $hours, $minutes ) = array_map( 'intval', explode( ':', $time ) + array( 0, 0 ) );

		return $hours * 60 + $minutes;
	}

	/**
	 * Localized time: "11 am" / "7:30 pm" in English, "11 h" / "19 h 30" in French.
	 *
	 * @param string $time "HH:MM".
	 */
	public static function time_label( string $time ): string {
		list( $hours, $minutes ) = array_map( 'intval', explode( ':', $time ) + array( 0, 0 ) );

		$format = 0 === $minutes
			? _x( 'g a', 'opening time, whole hour', 'wax-store-catalog' )
			: _x( 'g:i a', 'opening time with minutes', 'wax-store-catalog' );

		return wp_date( $format, mktime( $hours, $minutes, 0, 1, 1, 2024 ), new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Day names, lowercase as used inside a sentence.
	 */
	private static function day_names(): array {
		return array(
			'mon' => _x( 'Monday', 'in a sentence', 'wax-store-catalog' ),
			'tue' => _x( 'Tuesday', 'in a sentence', 'wax-store-catalog' ),
			'wed' => _x( 'Wednesday', 'in a sentence', 'wax-store-catalog' ),
			'thu' => _x( 'Thursday', 'in a sentence', 'wax-store-catalog' ),
			'fri' => _x( 'Friday', 'in a sentence', 'wax-store-catalog' ),
			'sat' => _x( 'Saturday', 'in a sentence', 'wax-store-catalog' ),
			'sun' => _x( 'Sunday', 'in a sentence', 'wax-store-catalog' ),
		);
	}

	/**
	 * Block rendering.
	 *
	 * @param array $attributes showAddress, showWeek.
	 */
	public static function render( array $attributes ): string {
		$schedule = self::schedule();

		if ( ! array_filter( $schedule ) ) {
			return '';
		}

		$now    = current_datetime();
		$status = self::status( $now );
		$times  = array();

		foreach ( $schedule as $ranges ) {
			foreach ( $ranges as $range ) {
				$times[ $range[0] ] = self::time_label( $range[0] );
				$times[ $range[1] ] = self::time_label( $range[1] );
			}
		}

		$data = array(
			'timezone' => wp_timezone_string(),
			'days'     => $schedule,
			'times'    => $times,
			'names'    => self::day_names(),
			'closure'  => self::closure(),
			'i18n'     => array(
				/* translators: %s: opening time. */
				'opensToday'    => __( 'Opens today at %s', 'wax-store-catalog' ),
				/* translators: %s: opening time. */
				'reopensAt'     => __( 'Closed now, reopens at %s', 'wax-store-catalog' ),
				/* translators: %s: closing time. */
				'openUntil'     => __( 'Open today until %s', 'wax-store-catalog' ),
				/* translators: %s: opening time. */
				'opensTomorrow' => __( 'Closed, opens tomorrow at %s', 'wax-store-catalog' ),
				/* translators: 1: day name, 2: opening time. */
				'opensOn'       => __( 'Closed, opens %1$s at %2$s', 'wax-store-catalog' ),
				'closed'        => __( 'Closed', 'wax-store-catalog' ),
			),
		);

		// During a closure the message holds a formatted date: the browser keeps the server text.
		$html = sprintf(
			'<p class="waxsc-status__now is-%1$s" data-waxsc-status="%2$s"><span class="waxsc-status__dot" aria-hidden="true"></span><span class="waxsc-status__text">%3$s</span></p>',
			esc_attr( $status['state'] ),
			esc_attr( (string) wp_json_encode( $data ) ),
			esc_html( $status['text'] )
		);

		if ( ! empty( $attributes['showAddress'] ) ) {
			$address = implode( ', ', array_filter( array( (string) Settings::get( 'contact', 'street' ), trim( Settings::get( 'contact', 'postal_code' ) . ' ' . Settings::get( 'contact', 'city' ) ) ) ) );
			$map     = (string) Settings::get( 'local', 'map_url' );

			if ( '' !== $address ) {
				$html .= '<p class="waxsc-status__address">' . ( '' !== $map ? '<a href="' . esc_url( $map ) . '">' . esc_html( $address ) . '</a>' : esc_html( $address ) ) . '</p>';
			}
		}

		if ( ! empty( $attributes['showWeek'] ) ) {
			$html .= self::week_table( $schedule, $now );
		}

		return '<div ' . get_block_wrapper_attributes( array( 'class' => 'waxsc-status' ) ) . '>' . $html . '</div>';
	}

	/**
	 * Weekly hours table, today highlighted.
	 *
	 * @param array              $schedule Schedule.
	 * @param \DateTimeImmutable $now      Now.
	 */
	private static function week_table( array $schedule, \DateTimeImmutable $now ): string {
		$labels = array(
			'mon' => __( 'Monday', 'wax-store-catalog' ),
			'tue' => __( 'Tuesday', 'wax-store-catalog' ),
			'wed' => __( 'Wednesday', 'wax-store-catalog' ),
			'thu' => __( 'Thursday', 'wax-store-catalog' ),
			'fri' => __( 'Friday', 'wax-store-catalog' ),
			'sat' => __( 'Saturday', 'wax-store-catalog' ),
			'sun' => __( 'Sunday', 'wax-store-catalog' ),
		);
		$today  = array_keys( Settings::DAYS )[ (int) $now->format( 'N' ) - 1 ];
		$rows   = '';

		foreach ( $schedule as $day => $ranges ) {
			$text = $ranges
				? implode(
					', ',
					array_map(
						static function ( $range ) {
							/* translators: 1: opening time, 2: closing time. */
							return sprintf( __( '%1$s to %2$s', 'wax-store-catalog' ), self::time_label( $range[0] ), self::time_label( $range[1] ) );
						},
						$ranges
					)
				)
				: __( 'Closed', 'wax-store-catalog' );

			$rows .= sprintf(
				'<tr%1$s><th scope="row">%2$s</th><td>%3$s</td></tr>',
				$day === $today ? ' class="is-today"' : '',
				esc_html( $labels[ $day ] ),
				esc_html( $text )
			);
		}

		return '<table class="waxsc-status__week"><caption class="screen-reader-text">' . esc_html__( 'Opening hours', 'wax-store-catalog' ) . '</caption><tbody>' . $rows . '</tbody></table>';
	}
}
