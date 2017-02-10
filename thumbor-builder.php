<?php

Class Thumbor_Builder {

	protected $extensions = array(
		'gif',
		'jpg',
		'jpeg',
		'png'
	);

	private $server;
	private $secret;

	private $factory;

	public function __construct( $server, $secret ) {
		$this->server = $server;
		$this->secret = $secret;
	}

	public function url( $image_url, $builder_args ) {
		$thumbnailUrlFactory = Thumbor\Url\BuilderFactory::construct( $this->server, $this->secret );

		$image_url = preg_replace( '(^https?://)', '', $image_url );
		$image_url = $thumbnailUrlFactory->url( $image_url );

		if ( isset( $builder_args['fit'] ) ) {
			$image_url = $image_url->fitIn( $builder_args['fit']['width'], $builder_args['fit']['height'] );
		}

		if ( isset( $builder_args['crop'] ) ) {
			$image_url = $image_url->resize( $builder_args['crop']['width'], $builder_args['crop']['height'] );
		}

		if ( isset( $builder_args['smart_crop'] ) ) {
			$image_url->smartCrop( apply_filters( 'thumbor_smart_crop', $builder_args['smart_crop'] ) );
		}

		if ( ! empty( $builder_args['format'] ) ) {
			if ( 'jpg' == $builder_args['format'] ) {
				$builder_args['format'] = 'jpeg';
			}

			$image_url->addFilter( 'format', $builder_args['format'] );
		}
		
		if ( isset( $builder_args['fill'] ) ) {
			$image_url->addFilter( 'fill', $builder_args['fill'], 1);
		}
		
		return $image_url;
	}


	/**
	 * Ensure image URL is valid.
	 * Though Thumbor functions address some of the URL issues, we should avoid unnecessary processing if we know early on that the image isn't supported.
	 *
	 * @param string $url
	 * @uses wp_parse_args
	 * @return bool
	 */
	public function validate_image_url( $url ) {

		// Bail if the image alredy went through Thumbor
		if ( strpos( $url, $this->server ) !== false ) {
			return false;
		}

		$parsed_url = @parse_url( $url );

		if ( ! $parsed_url ) {
			return false;
		}

		// Parse URL and ensure needed keys exist, since the array returned by `parse_url` only includes the URL components it finds.
		$url_info = wp_parse_args( $parsed_url, array(
			'scheme' => null,
			'host'   => null,
			'port'   => null,
			'path'   => null
		) );

		// Bail if $url_info isn't complete
		if ( is_null( $url_info['host'] ) || is_null( $url_info['path'] ) ) {
			return false;
		}

		// Ensure image extension is acceptable
		if ( ! in_array( strtolower( pathinfo( $url_info['path'], PATHINFO_EXTENSION ) ), $this->extensions ) ) {
			return false;
		}

		// If we got this far, we should have an acceptable image URL
		// But let folks filter to decline if they prefer.
		return apply_filters( 'thumbor_validate_image_url', true, $url, $parsed_url );
	}

}
