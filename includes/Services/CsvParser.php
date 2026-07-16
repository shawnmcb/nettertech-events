<?php
/**
 * CSV Parser.
 *
 * Parses CSV files into structured data with header detection,
 * encoding normalization, and delimiter auto-detection.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Parses CSV files into associative arrays keyed by header row.
 *
 * @since 2.1.0
 */
class CsvParser {

	/**
	 * Supported delimiters for auto-detection.
	 *
	 * @var array<string>
	 */
	private const DELIMITERS = array( ',', ';', "\t" );

	/**
	 * Maximum columns to accept (safety limit).
	 *
	 * @var int
	 */
	private const MAX_COLUMNS = 50;

	/**
	 * Parse a CSV file and return structured data.
	 *
	 * @param string $file_path Absolute path to the CSV file.
	 * @return array{headers: array<string>, rows: array<array<string, string>>, row_count: int, delimiter: string}
	 *
	 * @throws \InvalidArgumentException If the file cannot be read or is empty.
	 * @throws \RuntimeException         If headers cannot be parsed.
	 */
	public function parse( string $file_path ): array {
		if ( ! is_readable( $file_path ) ) {
			throw new \InvalidArgumentException(
				esc_html( sprintf( 'CSV file is not readable: %s', $file_path ) )
			);
		}

		$content = $this->read_and_normalize( $file_path );

		if ( '' === trim( $content ) ) {
			throw new \InvalidArgumentException( 'CSV file is empty.' );
		}

		$delimiter = $this->detect_delimiter( $content );
		$lines     = $this->content_to_lines( $content, $delimiter );

		if ( count( $lines ) < 1 ) {
			throw new \RuntimeException( 'CSV file contains no parseable rows.' );
		}

		$headers = array_shift( $lines );
		$headers = $this->normalize_headers( $headers );

		if ( count( $headers ) < 1 || count( $headers ) > self::MAX_COLUMNS ) {
			throw new \RuntimeException(
				esc_html( sprintf( 'Invalid column count: %d (expected 1-%d).', count( $headers ), self::MAX_COLUMNS ) )
			);
		}

		$rows         = array();
		$header_count = count( $headers );

		foreach ( $lines as $line ) {
			if ( $this->is_empty_row( $line ) ) {
				continue;
			}

			$row    = $this->align_row( $line, $header_count );
			$rows[] = array_combine( $headers, $row );
		}

		return array(
			'headers'   => $headers,
			'rows'      => $rows,
			'row_count' => count( $rows ),
			'delimiter' => $delimiter,
		);
	}

	/**
	 * Parse only the first N rows for preview purposes.
	 *
	 * @param string $file_path Absolute path to the CSV file.
	 * @param int    $limit     Number of data rows to return.
	 * @return array{headers: array<string>, rows: array<array<string, string>>, row_count: int, delimiter: string}
	 */
	public function preview( string $file_path, int $limit = 5 ): array {
		$result         = $this->parse( $file_path );
		$result['rows'] = array_slice( $result['rows'], 0, $limit );

		return $result;
	}

	/**
	 * Read file content and normalize encoding.
	 *
	 * Strips UTF-8 BOM, converts common encodings to UTF-8.
	 *
	 * @param string $file_path File path.
	 * @return string Normalized content.
	 *
	 * @throws \InvalidArgumentException If the file cannot be read.
	 */
	private function read_and_normalize( string $file_path ): string {
		$content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- WP_Filesystem requires prior instantiation not available in this parsing context.

		if ( false === $content ) {
			throw new \InvalidArgumentException(
				esc_html( sprintf( 'Failed to read CSV file: %s', $file_path ) )
			);
		}

		// Strip UTF-8 BOM.
		if ( str_starts_with( $content, "\xEF\xBB\xBF" ) ) {
			$content = substr( $content, 3 );
		}

		// Strip UTF-16 LE BOM.
		if ( str_starts_with( $content, "\xFF\xFE" ) ) {
			$converted = mb_convert_encoding( substr( $content, 2 ), 'UTF-8', 'UTF-16LE' );
			$content   = is_string( $converted ) ? $converted : $content;
		}

		// Strip UTF-16 BE BOM.
		if ( str_starts_with( $content, "\xFE\xFF" ) ) {
			$converted = mb_convert_encoding( substr( $content, 2 ), 'UTF-8', 'UTF-16BE' );
			$content   = is_string( $converted ) ? $converted : $content;
		}

		// Detect and convert non-UTF-8 encodings.
		if ( ! mb_check_encoding( $content, 'UTF-8' ) ) {
			$detected = mb_detect_encoding( $content, array( 'Windows-1252', 'ISO-8859-1', 'ASCII' ), true );
			if ( false !== $detected ) {
				$converted = mb_convert_encoding( $content, 'UTF-8', $detected );
				$content   = is_string( $converted ) ? $converted : $content;
			}
		}

		// Normalize line endings.
		return str_replace( array( "\r\n", "\r" ), "\n", $content );
	}

	/**
	 * Auto-detect the CSV delimiter by counting occurrences in the first line.
	 *
	 * @param string $content File content.
	 * @return string Detected delimiter character.
	 */
	private function detect_delimiter( string $content ): string {
		$first_line = strtok( $content, "\n" );

		if ( false === $first_line ) {
			return ',';
		}

		$scores = array();
		foreach ( self::DELIMITERS as $delim ) {
			$scores[ $delim ] = substr_count( $first_line, $delim );
		}

		arsort( $scores );
		$best = array_key_first( $scores );

		// Fall back to comma if no delimiter found.
		return ( $scores[ $best ] > 0 ) ? $best : ',';
	}

	/**
	 * Parse content into rows using str_getcsv for proper quote handling.
	 *
	 * @param string $content   File content.
	 * @param string $delimiter Delimiter character.
	 * @return array<array<string>> Array of row arrays.
	 */
	private function content_to_lines( string $content, string $delimiter ): array {
		$lines  = array();
		$stream = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- WP_Filesystem requires prior instantiation not available in this parsing context.

		if ( false === $stream ) {
			return array();
		}

		fwrite( $stream, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WP_Filesystem requires prior instantiation not available in this parsing context.
		rewind( $stream );

		$row = fgetcsv( $stream, 0, $delimiter, '"', '\\' );
		while ( false !== $row ) {
			// fgetcsv yields a single null field for blank lines; normalize to ''.
			$lines[] = array_map( static fn( $field ) => $field ?? '', $row );
			$row     = fgetcsv( $stream, 0, $delimiter, '"', '\\' );
		}

		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem requires prior instantiation not available in this parsing context.

		return $lines;
	}

	/**
	 * Normalize header names: lowercase, trim, replace spaces/special chars with underscores.
	 *
	 * @param array<string> $headers Raw header row.
	 * @return array<string> Normalized headers.
	 */
	private function normalize_headers( array $headers ): array {
		return array_map(
			static function ( $header ): string {
				$header = strtolower( trim( $header ) );
				$header = preg_replace( '/[^a-z0-9_]/', '_', $header ) ?? '';
				return preg_replace( '/_+/', '_', trim( $header, '_' ) ) ?? '';
			},
			$headers
		);
	}

	/**
	 * Check if a row contains only empty values.
	 *
	 * @param array<string|null> $row Row data.
	 * @return bool True if row is empty.
	 */
	private function is_empty_row( array $row ): bool {
		foreach ( $row as $cell ) {
			if ( null !== $cell && '' !== trim( (string) $cell ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Align a data row to match header count (pad or truncate).
	 *
	 * @param array<string|null> $row          Row data.
	 * @param int                $header_count Expected column count.
	 * @return array<string> Aligned row.
	 */
	private function align_row( array $row, int $header_count ): array {
		$row = array_map( static fn( $value ) => (string) ( $value ?? '' ), $row );

		if ( count( $row ) < $header_count ) {
			$row = array_pad( $row, $header_count, '' );
		} elseif ( count( $row ) > $header_count ) {
			$row = array_slice( $row, 0, $header_count );
		}

		return $row;
	}
}
