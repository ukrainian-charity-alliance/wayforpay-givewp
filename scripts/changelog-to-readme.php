<?php
/**
 * Sync a release's changelog into readme.txt.
 *
 * Reads the section for the given version from CHANGELOG.md (falling back to the
 * `[Unreleased]` section), converts the Markdown bullets to WordPress readme.txt
 * format, stamps `Stable tag:`, and inserts the block under `== Changelog ==`.
 *
 * Usage: php scripts/changelog-to-readme.php <version>
 *
 * @package WayforpayGiveWP
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions, WordPress.Security.EscapeOutput

if ( $argc < 2 || '' === trim( (string) ( $argv[1] ?? '' ) ) ) {
	fwrite( STDERR, "Usage: php scripts/changelog-to-readme.php <version>\n" );
	exit( 1 );
}

$version    = ltrim( trim( $argv[1] ), 'v' );
$root       = dirname( __DIR__ );
$changelog  = $root . '/CHANGELOG.md';
$readmePath = $root . '/readme.txt';

foreach ( array( $changelog, $readmePath ) as $file ) {
	if ( ! is_readable( $file ) ) {
		fwrite( STDERR, "Cannot read {$file}\n" );
		exit( 1 );
	}
}

/**
 * Extract the body lines of a `## [heading]` section from the changelog.
 *
 * @param string $markdown Full CHANGELOG.md contents.
 * @param string $heading  Heading to match inside the brackets (e.g. "1.1.0").
 * @return string[]|null   Raw body lines, or null if the section is absent.
 */
function wfp_extract_section( string $markdown, string $heading ): ?array {
	$lines   = preg_split( '/\r\n|\r|\n/', $markdown );
	$body    = array();
	$capture = false;

	foreach ( $lines as $line ) {
		if ( preg_match( '/^##\s+\[([^\]]+)\]/', $line, $m ) ) {
			if ( $capture ) {
				break; // Reached the next section.
			}
			$capture = ( strcasecmp( trim( $m[1] ), $heading ) === 0 );
			continue;
		}
		if ( $capture ) {
			$body[] = $line;
		}
	}

	return $capture ? $body : null;
}

/**
 * Convert Markdown changelog body lines to readme.txt bullet lines.
 *
 * `### Category` headings become a prefix on each following bullet, so
 * "### Fixed" + "- foo" becomes "* Fixed: foo". Ungrouped bullets stay plain.
 *
 * @param string[] $lines Raw body lines.
 * @return string[]       readme.txt-formatted bullet lines.
 */
function wfp_to_readme_bullets( array $lines ): array {
	$category = '';
	$bullets  = array();

	foreach ( $lines as $line ) {
		$trimmed = trim( $line );

		if ( '' === $trimmed ) {
			continue;
		}
		if ( preg_match( '/^#{3,}\s+(.+)$/', $trimmed, $m ) ) {
			$category = trim( $m[1] );
			continue;
		}
		if ( preg_match( '/^[-*]\s+(.+)$/', $trimmed, $m ) ) {
			$text      = trim( $m[1] );
			$bullets[] = '' !== $category ? "* {$category}: {$text}" : "* {$text}";
		}
	}

	return $bullets;
}

$markdown = file_get_contents( $changelog );

$sectionLines = wfp_extract_section( $markdown, $version );
if ( null === $sectionLines ) {
	$sectionLines = wfp_extract_section( $markdown, 'Unreleased' );
}

$bullets = null === $sectionLines ? array() : wfp_to_readme_bullets( $sectionLines );

if ( empty( $bullets ) ) {
	fwrite( STDERR, "No changelog entries found for version {$version} (or [Unreleased]).\n" );
	exit( 1 );
}

$readme = file_get_contents( $readmePath );

// Stamp the Stable tag.
$readme = preg_replace( '/^(Stable tag:).*$/m', '$1 ' . $version, $readme, 1 );

// Build the new changelog block.
$block = "= {$version} =\n" . implode( "\n", $bullets ) . "\n";

if ( strpos( $readme, "= {$version} =" ) !== false ) {
	fwrite( STDERR, "readme.txt already contains a changelog entry for {$version}; leaving it unchanged.\n" );
} elseif ( strpos( $readme, '== Changelog ==' ) === false ) {
	fwrite( STDERR, "readme.txt has no '== Changelog ==' section to update.\n" );
	exit( 1 );
} else {
	// Insert the block immediately after the "== Changelog ==" header line.
	$readme = preg_replace(
		'/(== Changelog ==\n)(\n*)/',
		'$1' . "\n" . $block . "\n",
		$readme,
		1
	);
}

file_put_contents( $readmePath, $readme );

fwrite( STDOUT, "readme.txt updated for {$version} (" . count( $bullets ) . " entries).\n" );
