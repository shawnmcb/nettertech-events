<?php
/**
 * Documentation drift gate.
 *
 * Extends the HooksDocumentationTest pattern to the other mechanical claims
 * the shipped docs make: shortcode tags, CSS custom properties, template
 * override paths, REST routes, and version declarations. Each claim a doc
 * presents as real (inside an inline-code span or fenced code block) is
 * verified against source, so a copy-pasteable example cannot silently break.
 *
 * Direction: doc to code is the required direction (a documented identifier
 * must exist), because that is what catches broken examples. Code to doc is
 * asserted only for shortcodes (every registered shortcode must be documented
 * in README.md); CSS variables are deliberately not swept code to doc because
 * most are internal theming knobs and documenting all of them would be noise.
 *
 * This gate exists because the docs recurrently kept pre-rebrand nte_*
 * shortcode names, phantom CSS variables, and Pro-only routes after the code
 * moved on, and nothing caught it.
 *
 * @package NetterTechEvents\Tests\Unit\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Core;

/**
 * Structural gate: mechanical doc claims must match the code.
 *
 * @coversNothing Static analysis of source and doc files, not class behaviour.
 *
 * @group structural
 */
class DocumentationDriftTest extends \NetterTechEventsTestCase {

	/**
	 * Doc-referenced shortcode tags that are intentionally not registered here.
	 *
	 * Keyed by tag, value is the reason. Nothing is silently skipped: a doc
	 * shortcode is either registered in this repo or listed here.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED_FOREIGN_SHORTCODES = array();

	/**
	 * Doc-referenced REST paths that are intentionally not registered here.
	 *
	 * Keyed by normalized doc path (namespace-relative, placeholders as *).
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED_FOREIGN_ROUTES = array(
		'check-in/*' => 'Registered by the Pro plugin (CheckInController, rest_base check-in) under the shared namespace; SECURITY.md documents the whole API surface.',
	);

	/**
	 * Doc-referenced template-like .php paths that are not plugin templates.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED_FOREIGN_TEMPLATES = array(
		'functions.php'                => 'Theme file shown in the override how-to tree, not a plugin template.',
		'wp-config.php'                => 'WordPress core file referenced in the debugging how-to.',
		'parts/event-card-concert.php' => 'User-created variant shown in the template-swap filter example; intentionally does not ship.',
	);

	/**
	 * Plugin root directory.
	 *
	 * @var string
	 */
	private string $plugin_dir;

	/**
	 * Set up paths.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->plugin_dir = dirname( __DIR__, 3 );
	}

	/**
	 * Every shortcode tag shown in a doc code context must be registered.
	 *
	 * @return void
	 */
	public function test_documented_shortcodes_are_registered(): void {
		$registered = $this->registered_shortcodes();
		$this->assertNotSame( array(), $registered, 'No add_shortcode calls found in source; extractor is broken.' );

		$failures = array();

		foreach ( $this->doc_code_tokens( '/\[(nte_[a-z0-9_]*|nettertech_events(?:_[a-z0-9_]+)?)\b/' ) as $hit ) {
			$tag = $hit['token'];

			if ( isset( self::ALLOWED_FOREIGN_SHORTCODES[ $tag ] ) ) {
				continue;
			}

			if ( ! isset( $registered[ $tag ] ) ) {
				$failures[] = "[{$tag}] at {$hit['site']}: not registered by any add_shortcode call in source.";
			}
		}

		$this->assertSame(
			array(),
			$failures,
			"Doc shortcode examples that would not work:\n  - " . implode( "\n  - ", $failures )
			. "\nFix the doc to use the registered tag, or allowlist with a reason in ALLOWED_FOREIGN_SHORTCODES."
		);
	}

	/**
	 * Every registered shortcode must be documented in README.md.
	 *
	 * @return void
	 */
	public function test_registered_shortcodes_are_documented(): void {
		$readme = (string) file_get_contents( $this->plugin_dir . '/README.md' );

		$missing = array();

		foreach ( $this->registered_shortcodes() as $tag => $site ) {
			if ( ! str_contains( $readme, "[{$tag}]" ) ) {
				$missing[] = "{$tag} (registered at {$site})";
			}
		}

		$this->assertSame(
			array(),
			$missing,
			"Registered shortcodes missing from README.md:\n  - " . implode( "\n  - ", $missing )
		);
	}

	/**
	 * Every CSS custom property shown in a doc code context must exist in assets.
	 *
	 * The reverse direction is intentionally not asserted: most variables are
	 * internal and documenting all of them would be noise, not coverage.
	 *
	 * @return void
	 */
	public function test_documented_css_variables_exist(): void {
		$defined = $this->defined_css_variables();
		$this->assertNotSame( array(), $defined, 'No --nte-* variables found in assets; extractor is broken.' );

		$failures = array();

		foreach ( $this->doc_code_tokens( '/(--nte-[a-z0-9-]+)/' ) as $hit ) {
			if ( ! isset( $defined[ $hit['token'] ] ) ) {
				$failures[] = "{$hit['token']} at {$hit['site']}: not defined or consumed in any assets/ stylesheet.";
			}
		}

		$this->assertSame(
			array(),
			$failures,
			"Doc CSS variables that do not exist in the shipped styles:\n  - " . implode( "\n  - ", $failures )
			. "\nFix the doc to reference a real variable."
		);
	}

	/**
	 * Every template path shown in the docs must exist under templates/.
	 *
	 * Matches both explicit templates/... paths and the theme-override forms
	 * (yourtheme/nettertech-events/parts/event-card.php); in every form the
	 * tail after the known prefixes must be a real file in templates/.
	 *
	 * @return void
	 */
	public function test_documented_template_paths_exist(): void {
		$failures = array();

		foreach ( $this->doc_code_tokens( '/([a-zA-Z0-9_\/.-]+\.php)/' ) as $hit ) {
			$relative = $this->template_relative_path( $hit['token'] );

			if ( null === $relative ) {
				continue;
			}

			if ( isset( self::ALLOWED_FOREIGN_TEMPLATES[ $relative ] ) ) {
				continue;
			}

			if ( ! is_file( $this->plugin_dir . '/templates/' . $relative ) ) {
				$failures[] = "{$hit['token']} at {$hit['site']}: templates/{$relative} does not exist.";
			}
		}

		$this->assertSame(
			array(),
			$failures,
			"Doc template paths that do not exist:\n  - " . implode( "\n  - ", $failures )
			. "\nFix the doc path, or allowlist with a reason in ALLOWED_FOREIGN_TEMPLATES."
		);
	}

	/**
	 * Every wp-json path shown in the docs must match a registered route.
	 *
	 * The namespace must be the registered one, and the route tail must match
	 * a register_rest_route call (doc {placeholders} match route regex groups).
	 *
	 * @return void
	 */
	public function test_documented_rest_routes_are_registered(): void {
		$routes = $this->registered_routes();
		$this->assertNotSame( array(), $routes, 'No register_rest_route calls resolved; extractor is broken.' );

		$namespace = 'nettertech-events/v1';
		$failures  = array();

		foreach ( $this->doc_code_tokens( '~wp-json/([a-zA-Z0-9_\/{}<>.-]+)~' ) as $hit ) {
			$path = trim( $hit['token'], '/' );

			if ( ! str_starts_with( $path . '/', $namespace . '/' ) ) {
				$failures[] = "{$hit['token']} at {$hit['site']}: namespace is not {$namespace}.";
				continue;
			}

			$tail = trim( substr( $path, strlen( $namespace ) ), '/' );

			if ( '' === $tail ) {
				continue; // Bare namespace reference; namespace already verified.
			}

			$normalized = preg_replace( '/\{[^}]*\}|<[^>]*>/', '*', $tail );

			if ( isset( self::ALLOWED_FOREIGN_ROUTES[ $normalized ] ) ) {
				continue;
			}

			if ( ! $this->route_matches( $normalized, $routes ) ) {
				$failures[] = "{$hit['token']} at {$hit['site']}: no register_rest_route call matches '{$tail}'.";
			}
		}

		$this->assertSame(
			array(),
			$failures,
			"Doc REST paths that are not registered:\n  - " . implode( "\n  - ", $failures )
			. "\nFix the doc, or allowlist with a reason in ALLOWED_FOREIGN_ROUTES."
		);
	}

	/**
	 * Every hook name used in a doc code example must be a hook the code fires.
	 *
	 * HooksDocumentationTest gates the fired-to-documented direction for
	 * docs/HOOKS.md. This gates the reverse for the whole corpus: an
	 * add_action / add_filter example naming a plugin hook that nothing fires
	 * (classically a dead pre-rebrand nte_* name) is a broken example.
	 *
	 * @return void
	 */
	public function test_documented_hook_examples_use_fired_names(): void {
		$fired = $this->fired_hook_names();
		$this->assertNotSame( array(), $fired, 'No fired hooks collected from source; extractor is broken.' );

		$pattern = '/\b(?:add_action|add_filter|do_action|apply_filters|remove_action|remove_filter)\s*\(\s*[\'"]((?:nte_|nettertech_events_)[a-z0-9_]+)/';

		$failures = array();

		foreach ( $this->doc_code_tokens( $pattern ) as $hit ) {
			if ( ! isset( $fired[ $hit['token'] ] ) ) {
				$failures[] = "{$hit['token']} at {$hit['site']}: no do_action or apply_filters call in source fires this hook.";
			}
		}

		$this->assertSame(
			array(),
			$failures,
			"Doc hook examples that reference hooks nothing fires:\n  - " . implode( "\n  - ", $failures )
			. "\nFix the doc to use the fired hook name (see docs/HOOKS.md)."
		);
	}

	/**
	 * All plugin hook names fired in source, including constant-registered ones.
	 *
	 * String literals in do_action or apply_filters calls plus every string
	 * constant on the Hooks registry (constants exist precisely to be fired).
	 *
	 * @return array<string, true>
	 */
	private function fired_hook_names(): array {
		$fired = array();

		foreach ( $this->source_files() as $path => $contents ) {
			if ( str_contains( $path, 'PHPStan' ) ) {
				continue;
			}

			if ( preg_match_all( '/\b(?:do_action|apply_filters)(?:_ref_array)?\s*\(\s*([\'"])((?:nte_|nettertech_events_)[a-z0-9_]+)\1/', $contents, $matches ) ) {
				foreach ( $matches[2] as $name ) {
					$fired[ $name ] = true;
				}
			}
		}

		$reflection = new \ReflectionClass( \NetterTechEvents\Core\Hooks::class );
		foreach ( $reflection->getConstants() as $value ) {
			if ( is_string( $value ) && str_starts_with( $value, 'nettertech_events_' ) ) {
				$fired[ $value ] = true;
			}
		}

		return $fired;
	}

	/**
	 * Plugin header Version, readme.txt Stable tag, and the version constant agree.
	 *
	 * Strict equality for now. If a trailing Stable tag workflow is ever
	 * adopted, relax the Stable tag comparison here with an explicit note.
	 *
	 * @return void
	 */
	public function test_version_declarations_agree(): void {
		$main = (string) file_get_contents( $this->plugin_dir . '/nettertech-events.php' );
		$txt  = (string) file_get_contents( $this->plugin_dir . '/readme.txt' );

		$this->assertSame( 1, preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $main, $header ), 'Plugin header Version: not found.' );
		$this->assertSame( 1, preg_match( '/^Stable tag:\s*(\S+)/m', $txt, $stable ), 'readme.txt Stable tag not found.' );
		$this->assertSame(
			1,
			preg_match( '/define\(\s*\'NETTERTECH_EVENTS_VERSION\',\s*\'([^\']+)\'\s*\)/', $main, $constant ),
			'NETTERTECH_EVENTS_VERSION define not found.'
		);

		$this->assertSame(
			$header[1],
			$constant[1],
			"Plugin header Version ({$header[1]}) and NETTERTECH_EVENTS_VERSION ({$constant[1]}) disagree in nettertech-events.php."
		);
		$this->assertSame(
			$header[1],
			$stable[1],
			"Plugin header Version ({$header[1]}) and readme.txt Stable tag ({$stable[1]}) disagree."
		);
	}

	/**
	 * All shortcode tags registered in source, mapped to first call site.
	 *
	 * @return array<string, string>
	 */
	private function registered_shortcodes(): array {
		$registered = array();

		foreach ( $this->source_files() as $path => $contents ) {
			$relative = substr( $path, strlen( $this->plugin_dir ) + 1 );

			// PHPStan helper classes quote add_shortcode in docblocks; skip them.
			if ( str_contains( $relative, 'PHPStan' ) ) {
				continue;
			}

			if ( ! preg_match_all( '/\badd_shortcode\s*\(\s*([\'"])([^\'"]+)\1/s', $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			foreach ( $matches[2] as $match ) {
				$line = substr_count( $contents, "\n", 0, $match[1] ) + 1;
				if ( ! isset( $registered[ $match[0] ] ) ) {
					$registered[ $match[0] ] = "{$relative}:{$line}";
				}
			}
		}

		return $registered;
	}

	/**
	 * All --nte-* custom properties present in shipped or source stylesheets.
	 *
	 * Both declarations and var() consumers count as existing: a doc telling
	 * a user to set a variable the styles consume is a valid claim even if no
	 * stylesheet declares a default for it.
	 *
	 * @return array<string, true>
	 */
	private function defined_css_variables(): array {
		$defined = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->plugin_dir . '/assets', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! in_array( $file->getExtension(), array( 'css', 'scss' ), true ) ) {
				continue;
			}

			$contents = (string) file_get_contents( $file->getPathname() );

			if ( preg_match_all( '/--nte-[a-z0-9-]+/', $contents, $matches ) ) {
				foreach ( $matches[0] as $name ) {
					$defined[ $name ] = true;
				}
			}
		}

		// InlineCssGenerator emits image-ratio variables at runtime; they are
		// real even where no static stylesheet declares them.
		foreach ( array( 'default', 'cards', 'list', 'carousel', 'calendar', 'single' ) as $view ) {
			$defined[ '--nte-image-ratio-' . $view ] = true;
		}

		return $defined;
	}

	/**
	 * Reduce a doc .php token to a templates/-relative path, or null if it is
	 * not claiming to be a plugin template (theme files, source files, URLs).
	 *
	 * @param string $token Doc token ending in .php.
	 * @return string|null
	 */
	private function template_relative_path( string $token ): ?string {
		$token = ltrim( $token, '/' );

		// Placeholder-bearing or explicitly illustrative paths are not
		// checkable claims.
		if ( str_contains( $token, '{' ) || str_contains( $token, '$' ) || str_contains( $token, 'path/to' ) ) {
			return null;
		}

		// Explicit plugin-template paths, from any depth of prefix.
		if ( preg_match( '~(?:^|/)templates/(.+\.php)$~', $token, $m ) ) {
			return $m[1];
		}

		// Theme-override form: the tail after the plugin text domain folder.
		// Source-file references (includes/, admin/) are not template claims.
		if ( preg_match( '~(?:^|/)nettertech-events/(.+\.php)$~', $token, $m )
			&& ! str_starts_with( $m[1], 'templates/' )
			&& ! str_starts_with( $m[1], 'includes/' )
			&& ! str_starts_with( $m[1], 'admin/' )
		) {
			return $m[1];
		}

		// Bare relative claims are only template claims inside the override doc,
		// which addresses every path relative to the templates/ root. A bare
		// basename that exists anywhere under templates/ is a valid reference
		// (filter examples match on basename); report it as its real path.
		if ( str_ends_with( $this->current_doc, 'TEMPLATE-OVERRIDE.md' ) && ! str_contains( $token, '://' ) ) {
			$match = $this->find_template_by_basename( basename( $token ) );

			if ( null !== $match && ( basename( $token ) === $token || $match === $token ) ) {
				return $match;
			}

			return $token;
		}

		return null;
	}

	/**
	 * Locate a template by basename, or null if none ships with that name.
	 *
	 * @param string $basename File basename.
	 * @return string|null templates/-relative path.
	 */
	private function find_template_by_basename( string $basename ): ?string {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->plugin_dir . '/templates', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->getFilename() === $basename ) {
				return substr( $file->getPathname(), strlen( $this->plugin_dir . '/templates/' ) );
			}
		}

		return null;
	}

	/**
	 * Doc file the token iterator is currently reading, for context-sensitive rules.
	 *
	 * @var string
	 */
	private string $current_doc = '';

	/**
	 * Concrete route patterns registered in source, mapped to call site.
	 *
	 * Resolves '/' . $this->rest_base . '...' concatenations using the
	 * $rest_base property default declared in the same file, and normalizes
	 * (?P<name>regex) groups to *.
	 *
	 * @return array<string, string>
	 */
	private function registered_routes(): array {
		$routes = array();

		foreach ( $this->source_files() as $path => $contents ) {
			$relative = substr( $path, strlen( $this->plugin_dir ) + 1 );

			if ( str_contains( $relative, 'PHPStan' ) || ! str_contains( $contents, 'register_rest_route' ) ) {
				continue;
			}

			$rest_base = null;
			if ( preg_match( '/\$rest_base\s*=\s*([\'"])([^\'"]+)\1/', $contents, $m ) ) {
				$rest_base = $m[2];
			}

			if ( ! preg_match_all( '/register_rest_route\s*\(\s*[^,]+,\s*(.*?),\s*array/s', $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			foreach ( $matches[1] as $match ) {
				$expr = trim( preg_replace( '/\s+/', ' ', $match[0] ) );
				$line = substr_count( $contents, "\n", 0, $match[1] ) + 1;

				if ( null !== $rest_base ) {
					$expr = str_replace( '$this->rest_base', "'{$rest_base}'", $expr );
				}

				// Fold string concatenation into one literal; anything left
				// non-literal is dynamic and cannot be a doc match target.
				$expr = preg_replace( '/([\'"])\s*\.\s*([\'"])/', '', $expr );

				if ( ! preg_match( '/^([\'"])(.*)\1$/s', $expr, $lit ) ) {
					continue;
				}

				$route = trim( $lit[2], '/' );
				$route = (string) preg_replace( '/\(\?P<[^>]+>[^)]*\)/', '*', $route );

				if ( '' !== $route && ! isset( $routes[ $route ] ) ) {
					$routes[ $route ] = "{$relative}:{$line}";
				}
			}
		}

		return $routes;
	}

	/**
	 * Whether a normalized doc route tail matches a registered route pattern.
	 *
	 * A doc * placeholder matches a route * group; a doc concrete segment also
	 * matches a route * group (docs may show example literal values).
	 *
	 * @param string                $doc_tail Normalized doc path after the namespace.
	 * @param array<string, string> $routes   Normalized registered routes.
	 * @return bool
	 */
	private function route_matches( string $doc_tail, array $routes ): bool {
		$doc_segments = explode( '/', $doc_tail );

		foreach ( array_keys( $routes ) as $route ) {
			$route_segments = explode( '/', $route );

			if ( count( $route_segments ) !== count( $doc_segments ) ) {
				continue;
			}

			$ok = true;
			foreach ( $route_segments as $i => $segment ) {
				if ( '*' === $segment || '*' === $doc_segments[ $i ] ) {
					continue;
				}
				if ( $segment !== $doc_segments[ $i ] ) {
					$ok = false;
					break;
				}
			}

			if ( $ok ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Yield every regex hit inside doc code contexts, with file:line site.
	 *
	 * Code contexts are fenced code blocks and inline backtick spans. Prose
	 * outside them is deliberately ignored to avoid false positives (markdown
	 * links, incidental mentions).
	 *
	 * @param string $pattern Regex whose first capture group is the token.
	 * @return \Generator<array{token: string, site: string}>
	 */
	private function doc_code_tokens( string $pattern ): \Generator {
		foreach ( $this->doc_files() as $path => $contents ) {
			$this->current_doc = $path;
			$relative          = substr( $path, strlen( $this->plugin_dir ) + 1 );

			foreach ( $this->code_spans( $contents ) as $span ) {
				if ( ! preg_match_all( $pattern, $span['text'], $matches, PREG_OFFSET_CAPTURE ) ) {
					continue;
				}

				foreach ( $matches[1] as $match ) {
					$line = $span['line'] + substr_count( $span['text'], "\n", 0, $match[1] );

					yield array(
						'token' => $match[0],
						'site'  => "{$relative}:{$line}",
					);
				}
			}
		}

		$this->current_doc = '';
	}

	/**
	 * Extract fenced code blocks and inline code spans with start lines.
	 *
	 * @param string $contents Doc file contents.
	 * @return array<array{text: string, line: int}>
	 */
	private function code_spans( string $contents ): array {
		$spans = array();

		// Fenced blocks first; they are removed before inline-span scanning so
		// backticks inside fences are not misread as inline spans.
		if ( preg_match_all( '/^(?:```|~~~)[^\n]*\n(.*?)^(?:```|~~~)\s*$/ms', $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[1] as $match ) {
				$spans[] = array(
					'text' => $match[0],
					'line' => substr_count( $contents, "\n", 0, $match[1] ) + 1,
				);
			}
		}

		$without_fences = (string) preg_replace( '/^(?:```|~~~)[^\n]*\n.*?^(?:```|~~~)\s*$/ms', '', $contents );

		if ( preg_match_all( '/`([^`\n]+)`/', $without_fences, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[1] as $match ) {
				$spans[] = array(
					'text' => $match[0],
					// Line numbers after fence removal drift; report the span
					// text location by searching the original instead.
					'line' => $this->line_of( $contents, $match[0] ),
				);
			}
		}

		return $spans;
	}

	/**
	 * First line in the original file where a code-span text appears.
	 *
	 * @param string $contents Original file contents.
	 * @param string $needle   Span text.
	 * @return int
	 */
	private function line_of( string $contents, string $needle ): int {
		$pos = strpos( $contents, $needle );

		return false === $pos ? 0 : substr_count( $contents, "\n", 0, $pos ) + 1;
	}

	/**
	 * The public doc corpus: README.md, readme.txt, and top-level docs/*.md.
	 *
	 * docs/architecture/ is internal design documentation and out of scope.
	 *
	 * @return \Generator<string, string> Path to contents.
	 */
	private function doc_files(): \Generator {
		$paths = array_merge(
			array( $this->plugin_dir . '/README.md', $this->plugin_dir . '/readme.txt' ),
			glob( $this->plugin_dir . '/docs/*.md' ) ?: array()
		);

		foreach ( $paths as $path ) {
			if ( is_file( $path ) ) {
				yield $path => (string) file_get_contents( $path );
			}
		}
	}

	/**
	 * All PHP source files that can register shortcodes or routes.
	 *
	 * @return \Generator<string, string> Path to contents.
	 */
	private function source_files(): \Generator {
		$roots = array(
			$this->plugin_dir . '/includes',
			$this->plugin_dir . '/admin',
		);

		$main = $this->plugin_dir . '/nettertech-events.php';
		if ( is_file( $main ) ) {
			yield $main => (string) file_get_contents( $main );
		}

		foreach ( $roots as $root ) {
			if ( ! is_dir( $root ) ) {
				continue;
			}

			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( 'php' !== $file->getExtension() ) {
					continue;
				}
				yield $file->getPathname() => (string) file_get_contents( $file->getPathname() );
			}
		}
	}
}
