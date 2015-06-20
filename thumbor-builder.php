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

		// Bail if scheme isn't http or port is set that isn't port 80. When scheme is null assume it's fine.
		if ( $url_info['scheme'] && ( 'http' != $url_info['scheme'] || ! in_array( $url_info['port'], array( 80, null ) ) ) ) {
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