<?php
/**
 * Plugin Name:  Thumbor
 * Version:      1.0
 * Plugin URI:   http://codekitchen.eu
 * Description:  Thumbor is an open-source photo thumbnail service. This plugin connects to it.
 * Author:       CodeKitchen B.V.
 * Author URI:   https://codekitchen.eu
 * Text Domain:  thumbor
 * Domain Path:  /languages/
 * License:      GPL v3
 */

Class Thumbor {

	// Private properties for internal usage.
	private $path;
	private $builder;
	private $image_sizes;

	public function __construct() {
		$this->path = plugin_dir_path( __FILE__ );

		$this->load_autoload();
		$this->load_hooks();
	}

	public function load_autoload() {
		if ( file_exists( $this->path . '/vendor/autoload_52.php' ) ) {
			require $this->path . '/vendor/autoload_52.php';
		}
	}

	public function load_hooks() {
		add_filter( 'image_downsize', array( $this, 'filter_image_downsize' ), 10, 3 );
	}


	/**
	 ** INTERNAL HELPERS
	 **/

	protected function get_builder() {
		if ( ! $this->builder ) {
			include 'thumbor-builder.php';
			$this->builder = new Thumbor_Builder( THUMBOR_SERVER, THUMBOR_SECRET );
		}

		return $this->builder;
	}

	/**
	 * Provide an array of available image sizes and corresponding dimensions.
	 * Similar to get_intermediate_image_sizes() except that it includes image sizes' dimensions, not just their names.
	 *
	 * @global $wp_additional_image_sizes
	 * @uses get_option
	 * @return array
	 */
	protected function image_sizes() {
		if ( null == $this->image_sizes ) {
			global $_wp_additional_image_sizes;

			// Populate an array matching the data structure of $_wp_additional_image_sizes so we have a consistent structure for image sizes
			$images = array(
				'thumb'  => array(
					'width'  => intval( get_option( 'thumbnail_size_w' ) ),
					'height' => intval( get_option( 'thumbnail_size_h' ) ),
					'crop'   => (bool) get_option( 'thumbnail_crop' )
				),
				'medium' => array(
					'width'  => intval( get_option( 'medium_size_w' ) ),
					'height' => intval( get_option( 'medium_size_h' ) ),
					'crop'   => false
				),
				'large'  => array(
					'width'  => intval( get_option( 'large_size_w' ) ),
					'height' => intval( get_option( 'large_size_h' ) ),
					'crop'   => false
				),
				'full'   => array(
					'width'  => null,
					'height' => null,
					'crop'   => false
				)
			);

			// Compatibility mapping as found in wp-includes/media.php
			$images['thumbnail'] = $images['thumb'];

			// Update class variable, merging in $_wp_additional_image_sizes if any are set
			if ( is_array( $_wp_additional_image_sizes ) && ! empty( $_wp_additional_image_sizes ) ) {
				$this->image_sizes = array_merge( $images, $_wp_additional_image_sizes );
			}
			else {
				$this->image_sizes = $images;
			}
		}

		return is_array( $this->image_sizes ) ? $this->image_sizes : array();
	}


	/**
	 ** HOOKS
	 **/

	public function filter_image_downsize( $image, $attachment_id, $size ) {
		// Don't foul up the admin side of things, and provide plugins a way of preventing Photon from being applied to images.
		if ( is_admin() || apply_filters( 'thumbor_override_image_downsize', false, compact( 'image', 'attachment_id', 'size' ) ) ) {
			return $image;
		}

		// Get the image URL.
		$image_url = wp_get_attachment_url( $attachment_id );

		if ( $image_url ) {
			$builder = $this->get_builder();

			// Check if image URL should be used.
			if ( ! $builder->validate_image_url( $image_url ) ) {
				return $image;
			}

			// If an image is requested with a size known to WordPress, use that size's settings with Photon
			if ( ( is_string( $size ) || is_int( $size ) ) && array_key_exists( $size, $this->image_sizes() ) ) {
				$image_args = self::image_sizes();
				$image_args = $image_args[ $size ];

				$builder_args = array();

				// `full` is a special case in WP
				// To ensure filter receives consistent data regardless of requested size, `$image_args` is overridden with dimensions of original image.
				if ( 'full' == $size ) {
					$image_meta = wp_get_attachment_metadata( $attachment_id );
					if ( isset( $image_meta['width'], $image_meta['height'] ) ) {
						// 'crop' is true so Photon's `resize` method is used
						$image_args = array(
							'width'  => $image_meta['width'],
							'height' => $image_meta['height'],
							'crop'   => true
						);
					}
				}

				// Expose determined arguments to a filter before passing to Photon
				$transform = $image_args['crop'] ? 'resize' : 'fit';

				// Check specified image dimensions and account for possible zero values; photon fails to resize if a dimension is zero.
				if ( 0 == $image_args['width'] || 0 == $image_args['height'] ) {
					if ( 0 == $image_args['width'] && 0 < $image_args['height'] ) {
						$builder_args['h'] = $image_args['height'];
					} elseif ( 0 == $image_args['height'] && 0 < $image_args['width'] ) {
						$builder_args['w'] = $image_args['width'];
					}
				} else {
					if ( ( 'resize' === $transform ) && $image_meta = wp_get_attachment_metadata( $attachment_id ) ) {
						// Lets make sure that we don't upscale images since wp never upscales them as well
						$smaller_width  = ( ( $image_meta['width']  < $image_args['width']  ) ? $image_meta['width']  : $image_args['width']  );
						$smaller_height = ( ( $image_meta['height'] < $image_args['height'] ) ? $image_meta['height'] : $image_args['height'] );

						$builder_args[ $transform ] = $smaller_width . ',' . $smaller_height;
					} else {
						$builder_args[ $transform ] = $image_args['width'] . ',' . $image_args['height'];
					}

				}

				$builder_args = apply_filters( 'thumbor_image_downsize_string', $builder_args, compact( 'image_args', 'image_url', 'attachment_id', 'size', 'transform' ) );

				// Generate Photon URL
				$image = array(
					$builder->url( $image_url, $builder_args ),
					false,
					false
				);
			} elseif ( is_array( $size ) ) {
				// Pull width and height values from the provided array, if possible
				$width = isset( $size[0] ) ? (int) $size[0] : false;
				$height = isset( $size[1] ) ? (int) $size[1] : false;

				// Don't bother if necessary parameters aren't passed.
				if ( ! $width || ! $height )
					return $image;

				// Expose arguments to a filter before passing to Photon
				$builder_args = array(
					'fit' => $width . ',' . $height
				);

				$builder_args = apply_filters( 'thumbor_image_downsize_array', $builder_args, compact( 'width', 'height', 'image_url', 'attachment_id' ) );

				// Generate Photon URL
				$image = array(
					$builder->url( $image_url, $builder_args ),
					false,
					false
				);
			}
		}

		return $image;
	}

}

if ( defined( 'THUMBOR_SERVER' ) && defined( 'THUMBOR_SECRET' ) ) {
	$GLOBAL['thumbor'] = new Thumbor;
}