<?php

namespace Tribe\Events\Aggregator\Record;

use Tribe__Events__Aggregator__Record__Abstract as Record;
use Tribe__Events__Aggregator__Record__Queue_Processor as Processor;
use Tribe__Events__Aggregator__Record__Queue_Realtime as Realtime;
use Tribe__Events__Ajax__Operations as Ajax;

class Queue_RealtimeTest extends \Codeception\TestCase\WPTestCase {
	/**
	 * It should correctly handle a void queue due to non-existing record
	 *
	 * @test
	 */
	public function should_correctly_handle_a_void_queue_due_to_non_existing_record() {
		$this->setup_request( 23 );
		$ajax = $this->make_ajax_operations();

		$realtime = new Realtime( null, $ajax, new Processor() );
		$realtime->ajax();

		$output_data = json_decode( $ajax->data, true );
		$this->assertArrayHasKey( 'error', $output_data );
		$this->assertTrue( $output_data['error'] );
		$this->assertArrayHasKey( 'counts', $output_data );
		$this->assertEmpty( array_sum( $output_data['counts'] ) );
		$this->assertArrayHasKey( 'error_text', $output_data );
		$expected_error = tribe_error( 'core:aggregator:invalid-record-object' )->get_error_message();
		$this->assertEquals( '<p>' . $expected_error . '</p>', $output_data['error_text'] );
	}

	protected function setup_request( $record_id ) {
		$_POST['record'] = $record_id;
		$_POST['check']  = 'spoofed-by-anonymous-ajax-class';
	}

	protected function make_ajax_operations() {
		$ajax = new class extends Ajax {
			public $data;

			public function verify_or_exit( $nonce, $action, $exit_data = array() ) {
				return true;
			}

			public function exit_data( $data = array() ) {
				$this->data = $data;
			}
		};

		return $ajax;
	}

	/**
	 * It should correctly handle a valid queue
	 *
	 * @test
	 */
	public function should_correctly_handle_a_valid_queue() {
		$record = $this->make_record();
		$this->setup_request( $record->id );
		$ajax = $this->make_ajax_operations();

		$realtime = new Realtime( null, $ajax, new Processor() );
		$realtime->ajax();

		$output_data = json_decode( $ajax->data, true );
		$this->assertArrayHasKey( 'error', $output_data );
		$this->assertFalse( $output_data['error'] );
		$this->assertArrayHasKey( 'complete', $output_data );
		$this->assertTrue( $output_data['complete'] );
	}

	protected function make_record() {
		$id = $this->factory()->post->create( [
			'post_type'      => 'tribe-ea-record',
			'post_mime_type' => 'ea/gcal',
			'meta_input'     => [
				Record::$meta_key_prefix . 'origin'       => 'gcal',
				Record::$meta_key_prefix . 'content_type' => 'tribe_events',
			],
			'ping_status'    => 'manual',
		] );

		$record = new class( $id ) extends Record {
			public $origin = 'gcal';

			public function __construct( $id ) {
				$this->id = $id;
			}

			public function get_label() {
				return 'test';
			}
		};

		return $record;
	}
}
