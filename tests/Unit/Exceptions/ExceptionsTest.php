<?php
/**
 * Exceptions unit tests.
 *
 * Tests all exception classes in the NetterTechEvents\Exceptions namespace.
 *
 * @package NetterTechEvents\Tests\Unit\Exceptions
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Exceptions;

use NetterTechEvents\Exceptions\NetterTechEventsException;
use NetterTechEvents\Exceptions\ValidationException;
use NetterTechEvents\Exceptions\NotFoundException;
use NetterTechEvents\Exceptions\DatabaseException;
use NetterTechEvents\Exceptions\RRuleException;

/**
 * Test exception classes functionality.
 */
class ExceptionsTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// NetterTechEventsException tests
	// =========================================================================

	/**
	 * Test NetterTechEventsException can be instantiated.
	 *
	 * @return void
	 */
	public function test_nte_exception_can_be_instantiated(): void {
		$exception = new NetterTechEventsException( 'Test message' );

		$this->assertInstanceOf( NetterTechEventsException::class, $exception );
		$this->assertInstanceOf( \Exception::class, $exception );
	}

	/**
	 * Test NetterTechEventsException stores message.
	 *
	 * @return void
	 */
	public function test_nte_exception_stores_message(): void {
		$exception = new NetterTechEventsException( 'Test error message' );

		$this->assertSame( 'Test error message', $exception->getMessage() );
	}

	/**
	 * Test NetterTechEventsException stores code.
	 *
	 * @return void
	 */
	public function test_nte_exception_stores_code(): void {
		$exception = new NetterTechEventsException( 'Test', 42 );

		$this->assertSame( 42, $exception->getCode() );
	}

	/**
	 * Test NetterTechEventsException stores previous exception.
	 *
	 * @return void
	 */
	public function test_nte_exception_stores_previous(): void {
		$previous  = new \RuntimeException( 'Previous error' );
		$exception = new NetterTechEventsException( 'Test', 0, $previous );

		$this->assertSame( $previous, $exception->getPrevious() );
	}

	/**
	 * Test NetterTechEventsException stores context.
	 *
	 * @return void
	 */
	public function test_nte_exception_stores_context(): void {
		$context   = array( 'user_id' => 123, 'action' => 'create' );
		$exception = new NetterTechEventsException( 'Test', 0, null, $context );

		$this->assertSame( $context, $exception->getContext() );
	}

	/**
	 * Test getContextValue returns specific value.
	 *
	 * @return void
	 */
	public function test_get_context_value_returns_specific_value(): void {
		$context   = array( 'user_id' => 123, 'action' => 'create' );
		$exception = new NetterTechEventsException( 'Test', 0, null, $context );

		$this->assertSame( 123, $exception->getContextValue( 'user_id' ) );
		$this->assertSame( 'create', $exception->getContextValue( 'action' ) );
	}

	/**
	 * Test getContextValue returns default for missing key.
	 *
	 * @return void
	 */
	public function test_get_context_value_returns_default_for_missing_key(): void {
		$exception = new NetterTechEventsException( 'Test', 0, null, array() );

		$this->assertNull( $exception->getContextValue( 'missing' ) );
		$this->assertSame( 'default', $exception->getContextValue( 'missing', 'default' ) );
	}

	/**
	 * Test toArray returns proper structure.
	 *
	 * @return void
	 */
	public function test_nte_exception_to_array(): void {
		$context   = array( 'key' => 'value' );
		$exception = new NetterTechEventsException( 'Test message', 42, null, $context );

		$array = $exception->toArray();

		$this->assertSame( NetterTechEventsException::class, $array['type'] );
		$this->assertSame( 'Test message', $array['message'] );
		$this->assertSame( 42, $array['code'] );
		$this->assertSame( $context, $array['context'] );
	}

	// =========================================================================
	// ValidationException tests
	// =========================================================================

	/**
	 * Test ValidationException extends NetterTechEventsException.
	 *
	 * @return void
	 */
	public function test_validation_exception_extends_base(): void {
		$exception = ValidationException::fromErrors( array( 'Error' ) );

		$this->assertInstanceOf( NetterTechEventsException::class, $exception );
		$this->assertInstanceOf( ValidationException::class, $exception );
	}

	/**
	 * Test fromErrors creates exception with errors.
	 *
	 * @return void
	 */
	public function test_validation_exception_from_errors(): void {
		$errors    = array( 'Name is required.', 'Email is invalid.' );
		$exception = ValidationException::fromErrors( $errors );

		$this->assertSame( $errors, $exception->getErrors() );
		$this->assertSame( 'Name is required. Email is invalid.', $exception->getMessage() );
	}

	/**
	 * Test fromErrors accepts context.
	 *
	 * @return void
	 */
	public function test_validation_exception_from_errors_with_context(): void {
		$context   = array( 'form' => 'registration' );
		$exception = ValidationException::fromErrors( array( 'Error' ), $context );

		$this->assertSame( $context, $exception->getContext() );
	}

	/**
	 * Test requiredField creates proper exception.
	 *
	 * @return void
	 */
	public function test_validation_exception_required_field(): void {
		$exception = ValidationException::requiredField( 'email' );

		$this->assertStringContainsString( 'Email is required', $exception->getMessage() );
		$this->assertSame( 'email', $exception->getContextValue( 'field' ) );
	}

	/**
	 * Test invalidField creates proper exception.
	 *
	 * @return void
	 */
	public function test_validation_exception_invalid_field(): void {
		$exception = ValidationException::invalidField( 'age', -5, 'Must be positive.' );

		$this->assertStringContainsString( 'Invalid value for age', $exception->getMessage() );
		$this->assertStringContainsString( 'Must be positive', $exception->getMessage() );
		$this->assertSame( 'age', $exception->getContextValue( 'field' ) );
		$this->assertSame( -5, $exception->getContextValue( 'value' ) );
	}

	/**
	 * Test invalidField without reason.
	 *
	 * @return void
	 */
	public function test_validation_exception_invalid_field_without_reason(): void {
		$exception = ValidationException::invalidField( 'status', 'bad' );

		$this->assertStringContainsString( 'Invalid value for status', $exception->getMessage() );
		$this->assertSame( 'status', $exception->getContextValue( 'field' ) );
	}

	/**
	 * Test ValidationException toArray includes errors.
	 *
	 * @return void
	 */
	public function test_validation_exception_to_array(): void {
		$errors    = array( 'Error 1', 'Error 2' );
		$exception = ValidationException::fromErrors( $errors );

		$array = $exception->toArray();

		$this->assertSame( $errors, $array['errors'] );
		$this->assertSame( ValidationException::class, $array['type'] );
	}

	// =========================================================================
	// NotFoundException tests
	// =========================================================================

	/**
	 * Test NotFoundException extends NetterTechEventsException.
	 *
	 * @return void
	 */
	public function test_not_found_exception_extends_base(): void {
		$exception = NotFoundException::byId( 'event', 123 );

		$this->assertInstanceOf( NetterTechEventsException::class, $exception );
		$this->assertInstanceOf( NotFoundException::class, $exception );
	}

	/**
	 * Test byId creates proper exception.
	 *
	 * @return void
	 */
	public function test_not_found_exception_by_id(): void {
		$exception = NotFoundException::byId( 'event', 123 );

		$this->assertStringContainsString( 'Event not found', $exception->getMessage() );
		$this->assertStringContainsString( 'ID: 123', $exception->getMessage() );
		$this->assertSame( 'event', $exception->getEntityType() );
		$this->assertSame( 123, $exception->getIdentifier() );
	}

	/**
	 * Test bySlug creates proper exception.
	 *
	 * @return void
	 */
	public function test_not_found_exception_by_slug(): void {
		$exception = NotFoundException::bySlug( 'attendee', 'john-doe' );

		$this->assertStringContainsString( 'Attendee not found', $exception->getMessage() );
		$this->assertStringContainsString( 'slug: john-doe', $exception->getMessage() );
		$this->assertSame( 'attendee', $exception->getEntityType() );
		$this->assertSame( 'john-doe', $exception->getIdentifier() );
	}

	/**
	 * Test byCriteria creates proper exception.
	 *
	 * @return void
	 */
	public function test_not_found_exception_by_criteria(): void {
		$exception = NotFoundException::byCriteria( 'ticket', 'code', 'ABC123' );

		$this->assertStringContainsString( 'Ticket not found', $exception->getMessage() );
		$this->assertStringContainsString( 'code: ABC123', $exception->getMessage() );
		$this->assertSame( 'ticket', $exception->getEntityType() );
		$this->assertSame( 'ABC123', $exception->getIdentifier() );
	}

	/**
	 * Test byCriteria handles non-scalar values.
	 *
	 * @return void
	 */
	public function test_not_found_exception_by_criteria_non_scalar(): void {
		$exception = NotFoundException::byCriteria( 'item', 'data', array( 'complex' => 'value' ) );

		$this->assertStringContainsString( 'array', $exception->getMessage() );
		$this->assertSame( 0, $exception->getIdentifier() );
	}

	/**
	 * Test NotFoundException toArray includes entity data.
	 *
	 * @return void
	 */
	public function test_not_found_exception_to_array(): void {
		$exception = NotFoundException::byId( 'occurrence', 456 );

		$array = $exception->toArray();

		$this->assertSame( 'occurrence', $array['entity_type'] );
		$this->assertSame( 456, $array['identifier'] );
		$this->assertSame( NotFoundException::class, $array['type'] );
	}

	// =========================================================================
	// DatabaseException tests
	// =========================================================================

	/**
	 * Test DatabaseException extends NetterTechEventsException.
	 *
	 * @return void
	 */
	public function test_database_exception_extends_base(): void {
		$exception = DatabaseException::insertFailed( 'event' );

		$this->assertInstanceOf( NetterTechEventsException::class, $exception );
		$this->assertInstanceOf( DatabaseException::class, $exception );
	}

	/**
	 * Test insertFailed creates proper exception.
	 *
	 * @return void
	 */
	public function test_database_exception_insert_failed(): void {
		$exception = DatabaseException::insertFailed( 'event', 'Duplicate key' );

		$this->assertStringContainsString( 'Failed to insert event', $exception->getMessage() );
		$this->assertStringContainsString( 'Duplicate key', $exception->getMessage() );
		$this->assertSame( 'insert', $exception->getOperation() );
		$this->assertSame( 'event', $exception->getEntityType() );
	}

	/**
	 * Test insertFailed without error message.
	 *
	 * @return void
	 */
	public function test_database_exception_insert_failed_no_error(): void {
		$exception = DatabaseException::insertFailed( 'attendee' );

		$this->assertSame( 'Failed to insert attendee.', $exception->getMessage() );
	}

	/**
	 * Test updateFailed creates proper exception.
	 *
	 * @return void
	 */
	public function test_database_exception_update_failed(): void {
		$exception = DatabaseException::updateFailed( 'ticket', 123, 'Row not found' );

		$this->assertStringContainsString( 'Failed to update ticket', $exception->getMessage() );
		$this->assertStringContainsString( 'ID: 123', $exception->getMessage() );
		$this->assertSame( 'update', $exception->getOperation() );
		$this->assertSame( 'ticket', $exception->getEntityType() );
		$this->assertSame( 123, $exception->getContextValue( 'id' ) );
	}

	/**
	 * Test deleteFailed creates proper exception.
	 *
	 * @return void
	 */
	public function test_database_exception_delete_failed(): void {
		$exception = DatabaseException::deleteFailed( 'occurrence', 456 );

		$this->assertStringContainsString( 'Failed to delete occurrence', $exception->getMessage() );
		$this->assertStringContainsString( 'ID: 456', $exception->getMessage() );
		$this->assertSame( 'delete', $exception->getOperation() );
		$this->assertSame( 'occurrence', $exception->getEntityType() );
	}

	/**
	 * Test queryFailed creates proper exception.
	 *
	 * @return void
	 */
	public function test_database_exception_query_failed(): void {
		$exception = DatabaseException::queryFailed( 'Syntax error', 'SELECT * FROM invalid' );

		$this->assertStringContainsString( 'Database query failed', $exception->getMessage() );
		$this->assertStringContainsString( 'Syntax error', $exception->getMessage() );
		$this->assertSame( 'query', $exception->getOperation() );
		$this->assertSame( 'SELECT * FROM invalid', $exception->getContextValue( 'query' ) );
	}

	/**
	 * Test queryFailed without query string.
	 *
	 * @return void
	 */
	public function test_database_exception_query_failed_no_query(): void {
		$exception = DatabaseException::queryFailed( 'Connection lost' );

		$this->assertNull( $exception->getContextValue( 'query' ) );
	}

	/**
	 * Test DatabaseException toArray includes operation data.
	 *
	 * @return void
	 */
	public function test_database_exception_to_array(): void {
		$exception = DatabaseException::updateFailed( 'event', 789 );

		$array = $exception->toArray();

		$this->assertSame( 'update', $array['operation'] );
		$this->assertSame( 'event', $array['entity_type'] );
		$this->assertSame( DatabaseException::class, $array['type'] );
	}

	/**
	 * Test toSafeArray strips SQL-sensitive keys from context.
	 *
	 * Regression test for G-09: prevents SQL leakage in non-debug contexts.
	 *
	 * @return void
	 */
	public function test_database_exception_to_safe_array_strips_sql(): void {
		$exception = DatabaseException::queryFailed(
			'Syntax error',
			'SELECT * FROM wp_users WHERE id = 1'
		);

		$safe = $exception->toSafeArray();

		// query key should be stripped.
		$this->assertArrayNotHasKey( 'query', $safe['context'] );

		// Other data should remain.
		$this->assertSame( 'query', $safe['operation'] );
		$this->assertSame( DatabaseException::class, $safe['type'] );
	}

	// =========================================================================
	// RRuleException tests
	// =========================================================================

	/**
	 * Test RRuleException extends NetterTechEventsException.
	 *
	 * @return void
	 */
	public function test_rrule_exception_extends_base(): void {
		$exception = RRuleException::emptyRule();

		$this->assertInstanceOf( NetterTechEventsException::class, $exception );
		$this->assertInstanceOf( RRuleException::class, $exception );
	}

	/**
	 * Test emptyRule creates proper exception.
	 *
	 * @return void
	 */
	public function test_rrule_exception_empty_rule(): void {
		$exception = RRuleException::emptyRule();

		$this->assertStringContainsString( 'cannot be empty', $exception->getMessage() );
	}

	/**
	 * Test missingComponent creates proper exception.
	 *
	 * @return void
	 */
	public function test_rrule_exception_missing_component(): void {
		$exception = RRuleException::missingComponent( 'FREQ', 'INTERVAL=2' );

		$this->assertStringContainsString( 'must have a FREQ component', $exception->getMessage() );
		$this->assertSame( 'FREQ', $exception->getComponent() );
		$this->assertSame( 'INTERVAL=2', $exception->getRrule() );
	}

	/**
	 * Test invalidComponent creates proper exception.
	 *
	 * @return void
	 */
	public function test_rrule_exception_invalid_component(): void {
		$allowed   = array( 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY' );
		$exception = RRuleException::invalidComponent( 'FREQ', 'HOURLY', $allowed, 'FREQ=HOURLY' );

		$this->assertStringContainsString( 'Invalid FREQ value: HOURLY', $exception->getMessage() );
		$this->assertStringContainsString( 'Allowed values:', $exception->getMessage() );
		$this->assertSame( 'FREQ', $exception->getComponent() );
		$this->assertSame( 'FREQ=HOURLY', $exception->getRrule() );
	}

	/**
	 * Test invalidComponent without allowed values.
	 *
	 * @return void
	 */
	public function test_rrule_exception_invalid_component_no_allowed(): void {
		$exception = RRuleException::invalidComponent( 'COUNT', -1 );

		$this->assertStringNotContainsString( 'Allowed values:', $exception->getMessage() );
	}

	/**
	 * Test invalidComponent handles non-scalar values.
	 *
	 * @return void
	 */
	public function test_rrule_exception_invalid_component_non_scalar(): void {
		$exception = RRuleException::invalidComponent( 'BYDAY', array( 'bad' ) );

		$this->assertStringContainsString( 'array', $exception->getMessage() );
	}

	/**
	 * Test invalidByday creates proper exception.
	 *
	 * @return void
	 */
	public function test_rrule_exception_invalid_byday(): void {
		$exception = RRuleException::invalidByday( 'XY', 'FREQ=WEEKLY;BYDAY=XY' );

		$this->assertStringContainsString( 'Invalid BYDAY format: XY', $exception->getMessage() );
		$this->assertStringContainsString( 'Expected format', $exception->getMessage() );
		$this->assertSame( 'BYDAY', $exception->getComponent() );
		$this->assertSame( 'FREQ=WEEKLY;BYDAY=XY', $exception->getRrule() );
	}

	/**
	 * Test parseFailed creates proper exception.
	 *
	 * @return void
	 */
	public function test_rrule_exception_parse_failed(): void {
		$exception = RRuleException::parseFailed( 'INVALID_RRULE', 'Unknown format' );

		$this->assertStringContainsString( 'Failed to parse RRULE', $exception->getMessage() );
		$this->assertStringContainsString( 'Unknown format', $exception->getMessage() );
		$this->assertSame( 'INVALID_RRULE', $exception->getRrule() );
	}

	/**
	 * Test parseFailed without reason.
	 *
	 * @return void
	 */
	public function test_rrule_exception_parse_failed_no_reason(): void {
		$exception = RRuleException::parseFailed( 'BAD_RRULE' );

		$this->assertSame( 'Failed to parse RRULE.', $exception->getMessage() );
	}

	/**
	 * Test RRuleException toArray includes component data.
	 *
	 * @return void
	 */
	public function test_rrule_exception_to_array(): void {
		$exception = RRuleException::missingComponent( 'FREQ', 'INTERVAL=2' );

		$array = $exception->toArray();

		$this->assertSame( 'FREQ', $array['component'] );
		$this->assertSame( 'INTERVAL=2', $array['rrule'] );
		$this->assertSame( RRuleException::class, $array['type'] );
	}

	// =========================================================================
	// Exception hierarchy tests
	// =========================================================================

	/**
	 * Test all exceptions can be caught as NetterTechEventsException.
	 *
	 * @return void
	 */
	public function test_all_exceptions_catchable_as_base(): void {
		$exceptions = array(
			new NetterTechEventsException( 'Test' ),
			ValidationException::fromErrors( array( 'Error' ) ),
			NotFoundException::byId( 'item', 1 ),
			DatabaseException::insertFailed( 'item' ),
			RRuleException::emptyRule(),
		);

		foreach ( $exceptions as $exception ) {
			$caught = false;
			try {
				throw $exception;
			} catch ( NetterTechEventsException $e ) {
				$caught = true;
			}
			$this->assertTrue( $caught, 'Exception should be catchable as NetterTechEventsException' );
		}
	}

	/**
	 * Test all exceptions implement toArray.
	 *
	 * @return void
	 */
	public function test_all_exceptions_implement_to_array(): void {
		$exceptions = array(
			new NetterTechEventsException( 'Test', 1, null, array( 'key' => 'value' ) ),
			ValidationException::fromErrors( array( 'Error' ) ),
			NotFoundException::byId( 'item', 1 ),
			DatabaseException::insertFailed( 'item' ),
			RRuleException::emptyRule(),
		);

		foreach ( $exceptions as $exception ) {
			$array = $exception->toArray();

			$this->assertIsArray( $array );
			$this->assertArrayHasKey( 'type', $array );
			$this->assertArrayHasKey( 'message', $array );
			$this->assertArrayHasKey( 'code', $array );
			$this->assertArrayHasKey( 'context', $array );
		}
	}
}
