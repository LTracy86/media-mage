<?php
/**
 * Build the wordpress.org directory banners.
 *
 * Layout is designed once in 386x125 "banner space" and rendered at 2x and 4x,
 * so the two outputs are the same design rather than one resampled from the
 * other. The hat is nearest-neighbour scaled by a whole number at every size,
 * which is the only way pixel art survives.
 *
 *   php assets-src/make-banner.php
 *
 * Outputs assets-src/banner-772x250.png and assets-src/banner-1544x500.png.
 * These belong in the SVN /assets/ folder, NOT inside the plugin.
 */

$GROUND = [ 0x1E, 0x22, 0x25 ];
$SHADOW = [ 0x14, 0x56, 0x9B ];
$BODY   = [ 0x00, 0xAE, 0xEE ];
$WHITE  = [ 0xFF, 0xFF, 0xFF ];
$RIM    = [ 0x3A, 0x3A, 0x3C ];

$FONT_BOLD = 'C:/Windows/Fonts/seguisb.ttf';
$FONT_REG  = 'C:/Windows/Fonts/segoeui.ttf';

function banner( $scale, $icon_src, $out, $fb, $fr ) {
	global $GROUND, $SHADOW, $BODY, $WHITE, $RIM;

	$W = 386 * $scale;
	$H = 125 * $scale;
	$im = imagecreatetruecolor( $W, $H );
	$c = function ( $rgb ) use ( $im ) { return imagecolorallocate( $im, $rgb[0], $rgb[1], $rgb[2] ); };

	imagefilledrectangle( $im, 0, 0, $W, $H, $c( $GROUND ) );

	// A slow wash toward the brand navy on the right, so the panel is not a flat
	// rectangle. Stays inside the palette: it only ever mixes ground and shadow.
	for ( $x = 0; $x < $W; $x++ ) {
		$t = pow( $x / $W, 1.7 ) * 0.55;
		$mix = imagecolorallocate( $im,
			(int) ( $GROUND[0] + ( $SHADOW[0] - $GROUND[0] ) * $t ),
			(int) ( $GROUND[1] + ( $SHADOW[1] - $GROUND[1] ) * $t ),
			(int) ( $GROUND[2] + ( $SHADOW[2] - $GROUND[2] ) * $t ) );
		imageline( $im, $x, 0, $x, $H, $mix );
	}

	// The hat, with its plate stripped so it sits ON the banner rather than
	// looking like a thumbnail pasted onto it. Only the OUTSIDE of the sprite is
	// dropped: the same dark tone also draws the interior fold lines, and those
	// have to stay. Flood-fill from the border tells the two apart.
	$src = imagecreatefrompng( $icon_src );
	$isz = imagesx( $src );
	$hex = function ( $x, $y ) use ( $src ) {
		$p = imagecolorat( $src, $x, $y );
		return sprintf( '#%02X%02X%02X', ( $p >> 16 ) & 0xFF, ( $p >> 8 ) & 0xFF, $p & 0xFF );
	};
	$outside = [];
	$queue   = [];
	for ( $i = 0; $i < $isz; $i++ ) {
		$queue[] = [ $i, 0 ]; $queue[] = [ $i, $isz - 1 ];
		$queue[] = [ 0, $i ]; $queue[] = [ $isz - 1, $i ];
	}
	while ( $queue ) {
		list( $x, $y ) = array_pop( $queue );
		if ( $x < 0 || $y < 0 || $x >= $isz || $y >= $isz ) continue;
		if ( isset( $outside[ "$x,$y" ] ) ) continue;
		$h = $hex( $x, $y );
		if ( $h !== '#1E2225' && $h !== '#3A3A3C' ) continue;
		$outside[ "$x,$y" ] = true;
		$queue[] = [ $x + 1, $y ]; $queue[] = [ $x - 1, $y ];
		$queue[] = [ $x, $y + 1 ]; $queue[] = [ $x, $y - 1 ];
	}

	// 1.5x in banner space, which is a whole number at both export scales (3 and 6).
	$mult = (int) ( 1.5 * $scale );
	$bx = $isz; $by = $isz; $bx2 = -1; $by2 = -1;
	for ( $y = 0; $y < $isz; $y++ ) for ( $x = 0; $x < $isz; $x++ ) {
		if ( isset( $outside[ "$x,$y" ] ) ) continue;
		$bx = min( $bx, $x ); $bx2 = max( $bx2, $x );
		$by = min( $by, $y ); $by2 = max( $by2, $y );
	}
	$hw = ( $bx2 - $bx + 1 ) * $mult;
	$hh = ( $by2 - $by + 1 ) * $mult;
	$ix = 24 * $scale;
	$iy = (int) ( ( $H - $hh ) / 2 );
	for ( $y = $by; $y <= $by2; $y++ ) {
		for ( $x = $bx; $x <= $bx2; $x++ ) {
			if ( isset( $outside[ "$x,$y" ] ) ) continue;
			$p = imagecolorat( $src, $x, $y );
			$col = imagecolorallocate( $im, ( $p >> 16 ) & 0xFF, ( $p >> 8 ) & 0xFF, $p & 0xFF );
			$dx = $ix + ( $x - $bx ) * $mult;
			$dy = $iy + ( $y - $by ) * $mult;
			imagefilledrectangle( $im, $dx, $dy, $dx + $mult - 1, $dy + $mult - 1, $col );
		}
	}

	$tx = $ix + $hw + 26 * $scale;

	// Auto-fit both lines inside the space left over, so neither can run into
	// the right edge when the copy or the font changes.
	$avail = $W - $tx - 24 * $scale;
	$fit = function ( $text, $font, $want ) use ( $avail ) {
		$size = $want;
		while ( $size > 6 ) {
			$b = imagettfbbox( $size, 0, $font, $text );
			if ( ( $b[2] - $b[0] ) <= $avail ) break;
			$size -= 0.5;
		}
		return $size;
	};

	// Wordmark, sized so the hat stays the hero rather than a bullet point.
	imagettftext( $im, $fit( 'Media Mage', $fb, 24 * $scale ), 0,
		$tx, (int) ( 62 * $scale ), $c( $WHITE ), $fb, 'Media Mage' );

	// One line, not three. The third line was unreadable at #3A3A3C on this
	// ground, and a banner that needs a paragraph is not doing its job.
	$tag = 'Duplicate and unused media cleanup';
	imagettftext( $im, $fit( $tag, $fr, 11 * $scale ), 0,
		$tx + 2, (int) ( 84 * $scale ), $c( $BODY ), $fr, $tag );

	imagepng( $im, $out );
	$kb = filesize( $out ) / 1024;
	printf( "%-34s %dx%d  %.0f KB\n", basename( $out ), $W, $H, $kb );
	imagedestroy( $im );
	imagedestroy( $src );
}

$dir = __DIR__;
banner( 2, "$dir/../assets/icon.png", "$dir/banner-772x250.png",  $FONT_BOLD, $FONT_REG );
banner( 4, "$dir/../assets/icon.png", "$dir/banner-1544x500.png", $FONT_BOLD, $FONT_REG );
