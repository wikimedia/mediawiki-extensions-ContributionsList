<?php

/**
 * Hooks for ContributionsList extension
 *
 * @file
 * @ingroup Extensions
 */
class ContributionsListHooks {

	/**
	 * Set up the #contributionslist parser function
	 *
	 * @param Parser $parser
	 * @return boolean
	 */
	public static function setupParserFunction( Parser &$parser ) {
		$parser->setFunctionHook( 'contributionslist', __CLASS__ . '::contributionslistParserFunction',
			SFH_OBJECT_ARGS );

		return true;
	}

	/**
	 *
	 * The parser function is called with the form:
	 * {{#contributionslist:
	 *    user=username | category=categoryname | type=createonly/notcreate/all |
	 *    format=plain/ol/ul | datefrom=fromdate | dateto=todate }}
	 *
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @param array $args
	 * @return string
	 */
	public static function contributionslistParserFunction(
	Parser $parser, PPFrame $frame, array $args ) {
		$params = self::extractOptions( $args, $frame );

		$user = isset( $params['user'] ) ? $params['user'] : '';
		$category = isset( $params['category'] ) ? $params['category'] : '';
		$type = isset( $params['type'] ) ? $params['type'] : '';
		$format = isset( $params['format'] ) ? $params['format'] : '';
		$dateFrom = isset( $params['datefrom'] ) ? $params['datefrom'] : '';
		$dateTo = isset( $params['dateto'] ) ? $params['dateto'] : '';

		$contributionsList = new ContributionsList( $user, $category, $type, $dateFrom, $dateTo );
		$output = $contributionsList->getContributionsList( $format );
		return array( $output, 'noparse' => true, 'isHTML' => true );
	}

	/**
	 * Converts an array of values in form [0] => "name=value" into a real
	 * associative array in form [name] => value
	 *
	 * @param array $options
	 * @param PPFrame $frame
	 * @return array
	 */
	public static function extractOptions( array $options, PPFrame $frame ) {
		$results = array();

		foreach ( $options as $option ) {
			$pair = explode( '=', $frame->expand( $option ), 2 );
			if ( count( $pair ) == 2 ) {
				$name = strtolower( trim( $pair[0] ) );
				$value = trim( $pair[1] );
				$results[$name] = $value;
			}
		}

		return $results;
	}
}
