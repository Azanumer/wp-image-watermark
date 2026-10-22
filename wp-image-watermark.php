<?php
/**
 * Plugin Name: WP Image Watermark
 * Description: Automatically watermarks uploaded images with text or a PNG overlay (GD only). Position, opacity, size threshold, per-size control.
 * Version: 1.0.0
 * Author: Azan Umer
 * License: MIT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WIW_OPTION', 'wiw_settings' );

/**
 * Default settings.
 */
function wiw_defaults() {
	return array(
		'enabled'          => 1,
		'type'             => 'text',           // text | image
		'text'             => get_bloginfo( 'name' ),
		'font_size'        => 5,                // GD built-in font 1–5
		'text_color'       => '255,255,255',
		'text_bg'          => '',               // e.g. "0,0,0" for a dark pill behind text; empty = none
		'opacity'          => 50,               // 0–100
		'position'         => 'bottom-right',   // top-left | top-right | bottom-left | bottom-right | center
		'margin'           => 15,               // px inset from edges
		'image_path'       => '',               // absolute path to PNG watermark (type=image)
		'image_max_ratio'  => 25,               // PNG watermark scaled to at most N% of target width
		'min_width'        => 600,              // skip images narrower than this
		'apply_to'         => 'original',       // original | all (original + generated sizes)
	);
}

function wiw_get_settings() {
	return wp_parse_args( (array) get_option( WIW_OPTION, array() ), wiw_defaults() );
}

/**
 * Fire after WordPress generates the attachment sizes for an upload.
 */
add_filter( 'wp_generate_attachment_metadata', 'wiw_maybe_watermark', 10, 2 );

function wiw_maybe_watermark( $metadata, $attachment_id ) {
	$set = wiw_get_settings();
	if ( empty( $set['enabled'] ) || ! function_exists( 'imagecreatetruecolor' ) ) {
		return $metadata;
	}

	$mime = get_post_mime_type( $attachment_id );
	if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
		return $metadata;
	}

	// Allow code to skip specific attachments.
	if ( apply_filters( 'wiw_skip_attachment', false, $attachment_id, $mime ) ) {
		return $metadata;
	}

	$upload_dir = wp_upload_dir();
	$targets    = array();

	if ( isset( $metadata['file'] ) ) {
		$targets['original'] = $upload_dir['basedir'] . '/' . $metadata['file'];
	}
	if ( $set['apply_to'] === 'all' && ! empty( $metadata['sizes'] ) ) {
		$dir = dirname( $upload_dir['basedir'] . '/' . $metadata['file'] );
		foreach ( $metadata['sizes'] as $size_name => $size ) {
			if ( isset( $size['file'] ) ) {
				$targets[ $size_name ] = $dir . '/' . $size['file'];
			}
		}
	}

	foreach ( $targets as $path ) {
		wiw_stamp_image( $path, $set );
	}

	return $metadata;
}

/**
 * Apply the watermark to one image file in place.
 */
function wiw_stamp_image( $path, $set ) {
	if ( ! is_file( $path ) || ! is_writable( $path ) ) {
		return false;
	}

	$info = getimagesize( $path );
	if ( ! $info ) {
		return false;
	}
	list( $width, $height, $type ) = $info;

	if ( $width < (int) $set['min_width'] ) {
		return false;
	}

	switch ( $type ) {
		case IMAGETYPE_JPEG:
			$img = imagecreatefromjpeg( $path );
			break;
		case IMAGETYPE_PNG:
			$img = imagecreatefrompng( $path );
			break;
		case IMAGETYPE_WEBP:
			if ( ! function_exists( 'imagecreatefromwebp' ) ) {
				return false;
			}
			$img = imagecreatefromwebp( $path );
			break;
		default:
			return false;
	}
	if ( ! $img ) {
		return false;
	}

	if ( $set['type'] === 'image' && is_file( $set['image_path'] ) ) {
		$ok = wiw_stamp_png_overlay( $img, $width, $height, $set );
	} else {
		$ok = wiw_stamp_text( $img, $width, $height, $set );
	}

	if ( $ok ) {
		switch ( $type ) {
			case IMAGETYPE_JPEG:
				imagejpeg( $img, $path, 90 );
				break;
			case IMAGETYPE_PNG:
				imagesavealpha( $img, true );
				imagepng( $img, $path, 6 );
				break;
			case IMAGETYPE_WEBP:
				imagewebp( $img, $path, 90 );
				break;
		}
	}
	imagedestroy( $img );
	return $ok;
}

/**
 * Text watermark using a GD built-in font (no TTF dependency).
 */
function wiw_stamp_text( $img, $width, $height, $set ) {
	$text = trim( (string) $set['text'] );
	if ( $text === '' ) {
		return false;
	}

	$font   = min( 5, max( 1, (int) $set['font_size'] ) );
	$tw     = imagefontwidth( $font ) * strlen( $text );
	$th     = imagefontheight( $font );
	$margin = (int) $set['margin'];

	list( $x, $y ) = wiw_position( $width, $height, $tw, $th, $set['position'], $margin );

	// Optional dark pill behind the text for readability.
	$bg = wiw_parse_rgb( $set['text_bg'] );
	if ( $bg ) {
		$bg_color = imagecolorallocatealpha( $img, $bg[0], $bg[1], $bg[2], wiw_alpha( $set['opacity'] ) );
		imagefilledrectangle( $img, $x - 6, $y - 4, $x + $tw + 6, $y + $th + 4, $bg_color );
	}

	$fg = wiw_parse_rgb( $set['text_color'] );
	$fg = $fg ? $fg : array( 255, 255, 255 );
	$color = imagecolorallocatealpha( $img, $fg[0], $fg[1], $fg[2], wiw_alpha( $set['opacity'] ) );

	return imagestring( $img, $font, $x, $y, $text, $color );
}

/**
 * PNG overlay watermark, scaled down to at most image_max_ratio% of target width.
 */
function wiw_stamp_png_overlay( $img, $width, $height, $set ) {
	$wm = imagecreatefrompng( $set['image_path'] );
	if ( ! $wm ) {
		return false;
	}

	$ww = imagesx( $wm );
	$wh = imagesy( $wm );
	$max_w = (int) ( $width * (int) $set['image_max_ratio'] / 100 );
	if ( $max_w > 0 && $ww > $max_w ) {
		$ratio = $max_w / $ww;
		$nw = $max_w;
		$nh = (int) ( $wh * $ratio );
		$resized = imagecreatetruecolor( $nw, $nh );
		imagealphablending( $resized, false );
		imagesavealpha( $resized, true );
		imagecopyresampled( $resized, $wm, 0, 0, 0, 0, $nw, $nh, $ww, $wh );
		imagedestroy( $wm );
		$wm = $resized;
		$ww = $nw;
		$wh = $nh;
	}

	// Apply overall opacity by fading the overlay's alpha channel.
	$opacity = min( 100, max( 1, (int) $set['opacity'] ) );
	if ( $opacity < 100 ) {
		imagefilter( $wm, IMG_FILTER_COLORIZE, 0, 0, 0, 127 - (int) ( 127 * $opacity / 100 ) );
	}

	$margin = (int) $set['margin'];
	list( $x, $y ) = wiw_position( $width, $height, $ww, $wh, $set['position'], $margin );

	imagealphablending( $img, true );
	$ok = imagecopy( $img, $wm, $x, $y, 0, 0, $ww, $wh );
	imagedestroy( $wm );
	return $ok;
}

/**
 * Convert a 9-grid position name to top-left coordinates.
 */
function wiw_position( $w, $h, $tw, $th, $position, $margin ) {
	switch ( $position ) {
		case 'top-left':
			return array( $margin, $margin );
		case 'top-right':
			return array( $w - $tw - $margin, $margin );
		case 'bottom-left':
			return array( $margin, $h - $th - $margin );
		case 'center':
			return array( (int) ( ( $w - $tw ) / 2 ), (int) ( ( $h - $th ) / 2 ) );
		case 'bottom-right':
		default:
			return array( $w - $tw - $margin, $h - $th - $margin );
	}
}

/**
 * Map 0–100 opacity to GD alpha (0 = opaque, 127 = transparent).
 */
function wiw_alpha( $opacity ) {
	$opacity = min( 100, max( 0, (int) $opacity ) );
	return 127 - (int) ( 127 * $opacity / 100 );
}

/**
 * Parse "r,g,b" into an int array, or null.
 */
function wiw_parse_rgb( $str ) {
	$parts = array_map( 'intval', explode( ',', (string) $str ) );
	if ( count( $parts ) !== 3 ) {
		return null;
	}
	foreach ( $parts as $p ) {
		if ( $p < 0 || $p > 255 ) {
			return null;
		}
	}
	return $parts;
}

/* ---------------- Settings page ---------------- */

add_action( 'admin_menu', function () {
	add_options_page(
		'Image Watermark',
		'Watermark',
		'manage_options',
		'wiw-settings',
		'wiw_render_settings'
	);
} );

add_action( 'admin_init', function () {
	register_setting( 'wiw_settings_group', WIW_OPTION, 'wiw_sanitize' );
} );

function wiw_sanitize( $in ) {
	$out = wiw_defaults();
	foreach ( $out as $key => $def ) {
		if ( ! isset( $in[ $key ] ) ) {
			continue;
		}
		$val = $in[ $key ];
		switch ( $key ) {
			case 'enabled':
				$out[ $key ] = empty( $val ) ? 0 : 1;
				break;
			case 'type':
				$out[ $key ] = in_array( $val, array( 'text', 'image' ), true ) ? $val : 'text';
				break;
			case 'position':
				$allowed = array( 'top-left', 'top-right', 'bottom-left', 'bottom-right', 'center' );
				$out[ $key ] = in_array( $val, $allowed, true ) ? $val : 'bottom-right';
				break;
			case 'apply_to':
				$out[ $key ] = ( $val === 'all' ) ? 'all' : 'original';
				break;
			case 'text':
			case 'image_path':
			case 'text_color':
			case 'text_bg':
				$out[ $key ] = sanitize_text_field( $val );
				break;
			default:
				$out[ $key ] = max( 0, (int) $val );
		}
	}
	return $out;
}

function wiw_render_settings() {
	$set = wiw_get_settings();
	?>
	<div class="wrap">
		<h1>Image Watermark</h1>
		<?php if ( ! function_exists( 'imagecreatetruecolor' ) ) : ?>
			<div class="notice notice-error"><p>PHP GD is not installed — watermarking is disabled until GD is available.</p></div>
		<?php endif; ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'wiw_settings_group' ); ?>
			<table class="form-table">
				<tr><th>Enabled</th><td><input type="checkbox" name="<?php echo esc_attr( WIW_OPTION ); ?>[enabled]" value="1" <?php checked( $set['enabled'], 1 ); ?>></td></tr>
				<tr><th>Type</th><td>
					<select name="<?php echo esc_attr( WIW_OPTION ); ?>[type]">
						<option value="text" <?php selected( $set['type'], 'text' ); ?>>Text</option>
						<option value="image" <?php selected( $set['type'], 'image' ); ?>>PNG image</option>
					</select>
				</td></tr>
				<tr><th>Text</th><td><input type="text" class="regular-text" name="<?php echo esc_attr( WIW_OPTION ); ?>[text]" value="<?php echo esc_attr( $set['text'] ); ?>"></td></tr>
				<tr><th>Font size (1–5)</th><td><input type="number" min="1" max="5" name="<?php echo esc_attr( WIW_OPTION ); ?>[font_size]" value="<?php echo esc_attr( $set['font_size'] ); ?>"></td></tr>
				<tr><th>Text color (r,g,b)</th><td><input type="text" name="<?php echo esc_attr( WIW_OPTION ); ?>[text_color]" value="<?php echo esc_attr( $set['text_color'] ); ?>" placeholder="255,255,255"></td></tr>
				<tr><th>Text background (r,g,b, empty=none)</th><td><input type="text" name="<?php echo esc_attr( WIW_OPTION ); ?>[text_bg]" value="<?php echo esc_attr( $set['text_bg'] ); ?>" placeholder="0,0,0"></td></tr>
				<tr><th>PNG watermark path (type=image)</th><td><input type="text" class="regular-text" name="<?php echo esc_attr( WIW_OPTION ); ?>[image_path]" value="<?php echo esc_attr( $set['image_path'] ); ?>" placeholder="/absolute/path/to/watermark.png"></td></tr>
				<tr><th>PNG max width (% of image)</th><td><input type="number" min="5" max="100" name="<?php echo esc_attr( WIW_OPTION ); ?>[image_max_ratio]" value="<?php echo esc_attr( $set['image_max_ratio'] ); ?>"></td></tr>
				<tr><th>Opacity (0–100)</th><td><input type="number" min="0" max="100" name="<?php echo esc_attr( WIW_OPTION ); ?>[opacity]" value="<?php echo esc_attr( $set['opacity'] ); ?>"></td></tr>
				<tr><th>Position</th><td>
					<select name="<?php echo esc_attr( WIW_OPTION ); ?>[position]">
						<?php foreach ( array( 'top-left', 'top-right', 'bottom-left', 'bottom-right', 'center' ) as $pos ) : ?>
							<option value="<?php echo esc_attr( $pos ); ?>" <?php selected( $set['position'], $pos ); ?>><?php echo esc_html( $pos ); ?></option>
						<?php endforeach; ?>
					</select>
				</td></tr>
				<tr><th>Margin (px)</th><td><input type="number" min="0" name="<?php echo esc_attr( WIW_OPTION ); ?>[margin]" value="<?php echo esc_attr( $set['margin'] ); ?>"></td></tr>
				<tr><th>Min image width (px)</th><td><input type="number" min="0" name="<?php echo esc_attr( WIW_OPTION ); ?>[min_width]" value="<?php echo esc_attr( $set['min_width'] ); ?>"></td></tr>
				<tr><th>Apply to</th><td>
					<select name="<?php echo esc_attr( WIW_OPTION ); ?>[apply_to]">
						<option value="original" <?php selected( $set['apply_to'], 'original' ); ?>>Original only</option>
						<option value="all" <?php selected( $set['apply_to'], 'all' ); ?>>Original + all sizes</option>
					</select>
				</td></tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<p class="description">Developers: use the <code>wiw_skip_attachment</code> filter to skip specific uploads
		(<code>add_filter( 'wiw_skip_attachment', '__return_true' )</code> conditionally).</p>
	</div>
	<?php
}
