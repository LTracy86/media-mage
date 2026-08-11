<?php
/**
 * Build a demo media library that looks like a real small-business site, so the
 * Media Mage screenshots show plausible filenames and real photographs
 * instead of fixture noise and solid colour swatches.
 *
 * Photos are Unsplash images served through Lorem Picsum. 26 unique files, no
 * repeats, plus four deliberate duplicates created by uploading the same source
 * a second time under a different name, which is how duplicates actually happen.
 */

$src_dir = 'C:/Users/linco/AppData/Local/Temp/claude/C--xampp-htdocs-library-Claude-Code-Projects/cddb279c-801c-4fa3-b22e-efd0fcae7c10/scratchpad/photos/';

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

/** Copy a source file into uploads under $as and register it as an attachment. */
function demo_upload( $src_dir, $file, $as ) {
	$src = $src_dir . $file;
	if ( ! file_exists( $src ) ) {
		WP_CLI::warning( "missing source: $file" );
		return 0;
	}
	$up   = wp_upload_dir();
	$dest = trailingslashit( $up['path'] ) . $as;
	copy( $src, $dest );

	$type = wp_check_filetype( $as );
	$id   = wp_insert_attachment( [
		'post_mime_type' => $type['type'],
		'post_title'     => preg_replace( '/\.[^.]+$/', '', $as ),
		'post_status'    => 'inherit',
	], $dest );
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $dest ) );
	update_post_meta( $id, '_demo_seed', 1 );
	return $id;
}

// --------------------------------------------------------------------------
// 1. The library. 26 unique photos.
// --------------------------------------------------------------------------
$unique = [
	'hero-banner.jpg', 'about-header.jpg', 'services-intro.jpg',
	'gallery-01.jpg', 'gallery-02.jpg', 'gallery-03.jpg',
	'gallery-04.jpg', 'gallery-05.jpg', 'gallery-06.jpg',
	'team-portrait.jpg', 'feature-tile-a.jpg', 'feature-tile-b.jpg',
	'blog-header-january.jpg', 'blog-header-february.jpg', 'blog-header-march.jpg',
	'testimonial-bg.jpg', 'contact-map-area.jpg',
	'product-shot-01.jpg', 'product-shot-02.jpg', 'footer-texture.png',
	// Never referenced by anything. This is what real clutter looks like.
	'IMG_4821.jpg', 'old-flyer-draft.jpg', 'banner-2024-retired.jpg',
	'headshot-unused.jpg', 'untitled-design.png', 'screenshot-2025-11-03.png',
];

$id = [];
foreach ( $unique as $f ) {
	$id[ $f ] = demo_upload( $src_dir, $f, $f );
}

// --------------------------------------------------------------------------
// 2. Duplicates. Same bytes, different filename, which is the case the plugin
//    exists for. "-1" is the suffix WordPress itself adds on a name collision.
// --------------------------------------------------------------------------
$dupes = [
	'hero-banner-FINAL.jpg'  => 'hero-banner.jpg',
	'homepage-hero-v2.jpg'   => 'hero-banner.jpg',
	'team-portrait-copy.jpg' => 'team-portrait.jpg',
	'gallery-03-1.jpg'       => 'gallery-03.jpg',
];
foreach ( $dupes as $as => $from ) {
	$id[ $as ] = demo_upload( $src_dir, $from, $as );
}

// --------------------------------------------------------------------------
// 3. Content that references them, so "unused" means something.
// --------------------------------------------------------------------------
$url = function ( $f ) use ( $id ) {
	return wp_get_attachment_url( $id[ $f ] );
};
$img = function ( $f ) use ( $id, $url ) {
	return '<img class="wp-image-' . $id[ $f ] . '" src="' . $url( $f ) . '" alt="">';
};

$pages = [
	'Home' => $img( 'hero-banner.jpg' ) . '<p>Welcome.</p>'
		. $img( 'feature-tile-a.jpg' ) . $img( 'feature-tile-b.jpg' )
		. '<div style="background-image:url(' . $url( 'testimonial-bg.jpg' ) . ')">Kind words.</div>',
	'About' => $img( 'about-header.jpg' ) . '<p>Who we are.</p>' . $img( 'team-portrait.jpg' ),
	'Services' => $img( 'services-intro.jpg' ) . '<p>What we do.</p>'
		. $img( 'product-shot-01.jpg' ) . $img( 'product-shot-02.jpg' ),
	'Gallery' => implode( '', array_map( $img, [
		'gallery-01.jpg', 'gallery-02.jpg', 'gallery-03.jpg',
		'gallery-04.jpg', 'gallery-05.jpg', 'gallery-06.jpg',
	] ) ),
	'Contact' => $img( 'contact-map-area.jpg' ) . '<p>Say hello.</p>',
	// The second hero copy really is in use on a landing page. That is what
	// makes a duplicate group interesting: you cannot just delete the extras.
	'Spring Campaign' => $img( 'homepage-hero-v2.jpg' ) . '<p>Landing page.</p>',
];
foreach ( $pages as $title => $content ) {
	wp_insert_post( [
		'post_title' => $title, 'post_content' => $content,
		'post_type' => 'page', 'post_status' => 'publish',
	] );
}

$posts = [
	'January newsletter' => 'blog-header-january.jpg',
	'February update'    => 'blog-header-february.jpg',
	'Plans for spring'   => 'blog-header-march.jpg',
];
foreach ( $posts as $title => $feature ) {
	$pid = wp_insert_post( [
		'post_title' => $title, 'post_status' => 'publish', 'post_type' => 'post',
		'post_content' => '<p>Some words about ' . strtolower( $title ) . '.</p>',
	] );
	set_post_thumbnail( $pid, $id[ $feature ] );
}

// Referenced from the options table rather than any post, which is exactly the
// kind of reference a content-only scanner misses.
set_theme_mod( 'demo_footer_texture', $url( 'footer-texture.png' ) );

WP_CLI::log( '' );
global $wpdb;
WP_CLI::log( sprintf(
	'attachments=%d  pages=%d  posts=%d',
	$wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment'" ),
	$wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish'" ),
	$wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish'" )
) );
WP_CLI::log( 'expected: 3 duplicate groups (4 redundant copies), 6 unused files' );
