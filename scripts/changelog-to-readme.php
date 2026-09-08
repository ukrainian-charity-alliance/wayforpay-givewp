<?php
/**
 * Sync a release's changelog into readme.txt.
 *
 * Rebuilds the `== Changelog ==` section from every released version in
 * CHANGELOG.md, newest first, converting the Markdown bullets to WordPress
 * readme.txt format and stamping `Stable tag:`. The version being released must
 * have entries of its own; it falls back to `[Unreleased]` if it has no section
 * yet.
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
 * Every released version's section, newest first.
 *
 * `[Unreleased]` is skipped: only `## [X.Y.Z]` headings are collected, in the
 * order the changelog lists them.
 *
 * @param string $markdown Full CHANGELOG.md contents.
 * @return array<string, string[]> Version number => raw body lines.
 */
function wfp_released_sections( string $markdown ): array {
	$lines    = preg_split( '/\r\n|\r|\n/', $markdown );
	$sections = array();
	$current  = null;

	foreach ( $lines as $line ) {
		if ( preg_match( '/^##\s+\[([^\]]+)\]/', $line, $m ) ) {
			$heading = trim( $m[1] );
			$current = preg_match( '/^\d+\.\d+\.\d+$/', $heading ) ? $heading : null;

			if ( null !== $current ) {
				$sections[ $current ] = array();
			}
			continue;
		}
		if ( null !== $current ) {
			$sections[ $current ][] = $line;
		}
	}

	return $sections;
}

/**
 * Convert Markdown changelog body lines to readme.txt bullet lines.
 *
 * `### Category` headings become a prefix on each following bullet, so
 * "### Fixed" + "- foo" becomes "* Fixed: foo". Ungrouped bullets stay plain.
 *
 * Changelog bullets wrap across lines; readme.txt bullets cannot. An indented
 * line following a bullet is folded back onto it, so a wrapped entry survives
 * whole rather than being truncated at the first line break.
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
			continue;
		}
		// Indentation is what marks a continuation, so unindented prose is
		// skipped as before rather than being glued onto the previous entry.
		if ( ! empty( $bullets ) && preg_match( '/^\s/', $line ) ) {
			$bullets[ array_key_last( $bullets ) ] .= ' ' . $trimmed;
		}
	}

	return $bullets;
}

$markdown = file_get_contents( $changelog );

$sections = wfp_released_sections( $markdown );

// The tagged version normally has its own section, rolled in by
// `composer release:prepare`. Fall back to [Unreleased] so a build still
// describes what it ships if that has not happened.
if ( ! isset( $sections[ $version ] ) ) {
	$unreleased = wfp_extract_section( $markdown, 'Unreleased' );
	if ( null !== $unreleased ) {
		$sections = array( $version => $unreleased ) + $sections;
	}
}

// The release being built must say something, even though older versions
// could carry the section on their own.
if ( empty( wfp_to_readme_bullets( $sections[ $version ] ?? array() ) ) ) {
	fwrite( STDERR, "No changelog entries found for version {$version} (or [Unreleased]).\n" );
	exit( 1 );
}

$blocks = array();
foreach ( $sections as $sectionVersion => $sectionLines ) {
	$bullets = wfp_to_readme_bullets( $sectionLines );
	if ( ! empty( $bullets ) ) {
		$blocks[] = "= {$sectionVersion} =\n" . implode( "\n", $bullets );
	}
}

$readme = file_get_contents( $readmePath );

if ( strpos( $readme, '== Changelog ==' ) === false ) {
	fwrite( STDERR, "readme.txt has no '== Changelog ==' section to update.\n" );
	exit( 1 );
}

// Stamp the Stable tag.
$readme = preg_replace( '/^(Stable tag:).*$/m', '$1 ' . $version, $readme, 1 );

// Replace the whole section rather than inserting into it, so the changelog
// is rebuilt from CHANGELOG.md every time and repeated runs are idempotent.
$readme = preg_replace(
	'/^== Changelog ==[ \t]*\n.*?(?=^== |\z)/ms',
	"== Changelog ==\n\n" . implode( "\n\n", $blocks ) . "\n\n",
	$readme,
	1
);

file_put_contents( $readmePath, rtrim( $readme, "\n" ) . "\n" );

fwrite( STDOUT, "readme.txt updated for {$version} (" . count( $blocks ) . " versions).\n" );
