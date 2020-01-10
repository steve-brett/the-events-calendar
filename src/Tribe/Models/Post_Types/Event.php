<?php
/**
 * Models an Event.
 *
 * @since   4.9.9
 *
 * @package Tribe\Events\Models\Post_Types
 */

namespace Tribe\Events\Models\Post_Types;

use DateInterval;
use DatePeriod;
use DateTimeZone;
use Tribe\Events\Collections\Lazy_Post_Collection;
use Tribe\Models\Post_Types\Base;
use Tribe\Utils\Lazy_Collection;
use Tribe\Utils\Lazy_String;
use Tribe\Utils\Post_Thumbnail;
use Tribe__Date_Utils as Dates;
use Tribe__Events__Featured_Events as Featured;
use Tribe__Events__Organizer as Organizer;
use Tribe__Events__Timezones as Timezones;
use Tribe__Events__Venue as Venue;

/**
 * Class Event
 *
 * @since   4.9.9
 *
 * @package Tribe\Events\Post_Types
 */
class Event extends Base {


	/**
	 * {@inheritDoc}
	 */
	protected function build_properties( $filter ) {
		try {
			$cache_this = $this->get_caching_callback( $filter );

			$now        = Dates::build_date_object( 'now' );
			$one_second = new DateInterval( 'PT1S' );
			$one_day    = new DateInterval( 'P1D' );

			$post_id = $this->post->ID;

			$post_meta = get_post_meta( $post_id );

			$start_date            = isset( $post_meta['_EventStartDate'][0] ) ? $post_meta['_EventStartDate'][0] : null;
			$start_date_utc        = isset( $post_meta['_EventStartDateUTC'][0] ) ? $post_meta['_EventStartDateUTC'][0] : null;
			$end_date              = isset( $post_meta['_EventEndDate'][0] ) ? $post_meta['_EventEndDate'][0] : null;
			$end_date_utc          = isset( $post_meta['_EventEndDateUTC'][0] ) ? $post_meta['_EventEndDateUTC'][0] : null;
			$duration              = (int) isset( $post_meta['_EventDuration'][0] ) ? $post_meta['_EventDuration'][0] : null;
			$timezone_string       = Timezones::get_event_timezone_string( $post_id );
			$all_day               = tribe_is_truthy( isset( $post_meta['_EventAllDay'][0] ) ? $post_meta['_EventAllDay'][0] : null );
			$end_of_day            = tribe_end_of_day( $start_date );
			$timezone              = Timezones::build_timezone_object( $timezone_string );
			$site_timezone         = Timezones::build_timezone_object();
			$utc_timezone          = new DateTimezone( 'UTC' );
			$start_date_object     = Dates::immutable( $start_date, $timezone );
			$end_date_object       = Dates::immutable( $end_date, $timezone );
			$start_date_utc_object = Dates::immutable( $start_date_utc, $utc_timezone );
			$end_date_utc_object   = Dates::immutable( $end_date_utc, $utc_timezone );
			$end_of_day_object     = Dates::immutable( $end_of_day, $timezone );

			if ( empty( $duration ) ) {
				// This is really an edge case, but here we have the information to rebuild it.
				$duration = $end_date_utc_object->getTimestamp() - $start_date_utc_object->getTimestamp();
			}

			/*
			 * An event is multi-day if its end date is after the end-of-day cutoff of the start date.
			 * We add one second to make sure events ending at end-of-day, same day, cutoff are not marked as multi-day.
			 */
			$is_multiday = $end_of_day_object->add( $one_second ) < $end_date_object;
			$multiday    = false;

			// Without a context these values will not make sense; we'll set them if the `$filter` argument is a date.
			$starts_this_week   = null;
			$ends_this_week     = null;
			$happens_this_week  = null;
			$this_week_duration = null;

			// Multi-day events will span at least two days: the day they start on and the following one.
			if ( $is_multiday ) {
				/*
				 * Count the number of cut-offs happening before the end date and add 1.
				 * Do not add 1 for all-day events as they span cut-off to cut-off.
				 */
				$multiday = $all_day ? 0 : 1;

				// The end date should be inclusive, since it's not in the DatePeriod we work-around it adding a second.
				$period = new DatePeriod( $end_of_day_object, $one_day, $end_date_object );
				foreach ( $period as $date ) {
					++ $multiday;
				};
			}

			$date_references = $this->build_date_references( $filter, $start_date_object, $end_date_object, $is_multiday );
			$featured        = tribe_is_truthy( isset( $post_meta[ Featured::FEATURED_EVENT_KEY ][0] ) ? $post_meta[ Featured::FEATURED_EVENT_KEY ][0] : null );
			$sticky          = get_post_field( 'menu_order', $post_id ) === -1;
			$organizer_fetch = Organizer::get_fetch_callback( $post_id );
			$venue_fetch     = Venue::get_fetch_callback( $post_id );

			$start_site         = $start_date_object->setTimezone( $site_timezone );
			$end_site           = $end_date_object->setTimezone( $site_timezone );
			$use_event_timezone = Timezones::is_mode( Timezones::EVENT_TIMEZONE );

			$properties = [
				'start_date'             => $start_date,
				'start_date_utc'         => $start_date_utc,
				'end_date'               => $end_date,
				'end_date_utc'           => $end_date_utc,
				'dates'                  => (object) [
					'start'         => $start_date_object,
					'start_utc'     => $start_date_utc_object,
					'start_site'    => $start_site,
					'start_display' => $use_event_timezone ? $start_date_object : $start_site,
					'end'           => $end_date_object,
					'end_utc'       => $end_date_utc_object,
					'end_site'      => $end_site,
					'end_display'   => $use_event_timezone ? $end_date_object : $end_site,
				],
				'timezone'               => $timezone_string,
				'duration'               => $duration,
				'multiday'               => $multiday,
				'is_past'                => $start_date_object < $now,
				'all_day'                => $all_day,
				'starts_this_week'       => $date_references['starts_this_week'],
				'ends_this_week'         => $date_references['ends_this_week'],
				'this_week_duration'     => $date_references['this_week_duration'],
				'happens_this_week'      => $date_references['happens_this_week'],
				'featured'               => $featured,
				'sticky'                 => $sticky,
				'cost'                   =>  tribe_get_cost( $post_id, true ),
				'excerpt'                => ( new Lazy_String(
					static function () use ( $post_id ) {
						return tribe_events_get_the_excerpt( $post_id, wp_kses_allowed_html( 'post' ) );
					},
					false
				) )->on_resolve( $cache_this ),
				'organizers'             => ( new Lazy_Collection( $organizer_fetch ) )->on_resolve( $cache_this ),
				'venues'                 => ( new Lazy_Post_Collection(
					$venue_fetch,
					'tribe_get_venue_object' )
				)->on_resolve( $cache_this ),
				'thumbnail'              => ( new Post_Thumbnail( $post_id ) )->on_resolve( $cache_this ),
				'permalink'              => ( new Lazy_String(
					static function () use ( $post_id ) {
						return get_permalink( $post_id );
					},
					false
				) )->on_resolve( $cache_this ),
				'schedule_details'       => ( new Lazy_String(
					static function () use ( $post_id ) {
						return tribe_events_event_schedule_details( $post_id );
					},
					false
				) )->on_resolve( $cache_this ),
				'plain_schedule_details' => ( new Lazy_String(
					static function () use ( $post_id ) {
						return tribe_events_event_schedule_details( $post_id, '', '', false );
					},
					false
				) )->on_resolve( $cache_this ),
				'title'                  => ( new Lazy_String(
					static function () use ( $post_id ) {
						return get_the_title( $post_id );
					},
					false
				) )->on_resolve( $cache_this ),
				'model'                  => $this,
			];
		} catch ( \Exception $e ) {
			return [];
		}

		return $properties;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_cache_slug() {
		return 'events';
	}

	/**
	 * Builds relevant date references based on passed in date.
	 *
	 * This method, given a date, computes this event's placement in a given week using the following values:
	 *   $this_week_duration
	 *   $starts_this_week
	 *   $ends_this_week
	 *   $happens_this_week
	 *
	 * @since 5.0.0
	 *
	 * @param $date
	 *
	 * @return array
	 */
	protected function build_date_references( $date, $start_date, $end_date, $is_multiday ) {
		$this_week_duration = null;
		$starts_this_week   = null;
		$ends_this_week     = null;
		$happens_this_week  = null;

		if ( Dates::is_valid_date( $date ) ) {
			list( $week_start, $week_end ) = Dates::get_week_start_end( $date );

			$the_start     = Dates::build_date_object( $start_date );
			$the_end       = Dates::build_date_object( $end_date );
			$site_timezone = Timezones::build_timezone_object();

			// Take into account the timezone settings.
			if ( Timezones::is_mode( Timezones::SITE_TIMEZONE ) ) {
				// Move the event to the site timezone.
				$the_start = $the_start->setTimezone( $site_timezone );
				$the_end   = $the_end->setTimezone( $site_timezone );
			}

			$week_start_ymd = (int) $week_start->format( 'Ymd' );
			$week_end_ymd   = (int) $week_end->format( 'Ymd' );
			$the_start_ymd  = (int) $the_start->format( 'Ymd' );
			$the_end_ymd    = (int) $the_end->format( 'Ymd' );

			$starts_this_week  = $week_start_ymd <= $the_start_ymd && $the_start_ymd <= $week_end_ymd;
			$ends_this_week    = $week_start_ymd <= $the_end_ymd && $the_end_ymd <= $week_end_ymd;
			$happens_this_week = $week_start_ymd <= $the_end_ymd && $the_start_ymd <= $week_end_ymd;

			/*
			 * A day "crosses the EOD cutoff time" if the end is after the EOD cutoff of the start.
			 * Here we look just for a boolean.
			 */
			$cross_day = tribe_end_of_day( $the_start->format( 'Y-m-d' ) ) < $the_end->format( 'Y-m-d H:i:s' );

			if ( $happens_this_week ) {
				$this_week_duration = 1;
				if ( $is_multiday ) {
					if ( $starts_this_week && $ends_this_week ) {
						$this_week_duration = min( 7, max( 1, Dates::date_diff( $the_end_ymd, $the_start_ymd ) ) + $cross_day );
					} elseif ( $ends_this_week ) {
						$this_week_duration = Dates::date_diff( $the_end_ymd, $week_start_ymd ) + $cross_day;
					} elseif ( $starts_this_week ) {
						$this_week_duration = Dates::date_diff( $week_end_ymd, $the_start_ymd ) + $cross_day;
					} else {
						// If it happens this week and it doesn't start or end this week, then it spans the week.
						$this_week_duration = 7;
					}
				}
			}
		}

		return [
			'this_week_duration' => $this_week_duration,
			'starts_this_week'   => $starts_this_week,
			'ends_this_week'     => $ends_this_week,
			'happens_this_week'  => $happens_this_week,
		];
	}

	/**
	 * Sets relevant date references based on passed in date.
	 *
	 * This method, given a date, sets this event's placement in a given week using the following values:
	 *   $this_week_duration
	 *   $starts_this_week
	 *   $ends_this_week
	 *   $happens_this_week
	 *
	 * @since 5.0.0
	 *
	 * @param $date
	 */
	public function set_date_references( $event, $date ) {
		$date_references = $this->build_date_references( $date, $event->dates->start, $event->dates->end, (bool) $event->multiday );

		if ( 6294 === $event->ID ) {
			$a = 1;
		}
		foreach ( $date_references as $key => $value ) {
			$event->$key = $value;
		}

		return $event;
	}
}
