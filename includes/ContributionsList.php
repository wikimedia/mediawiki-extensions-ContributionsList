<?php
/**
 * This file is part of the MediaWiki extension ContributionsList
 *
 * Copyright (C) 2014, Ike Hecht
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use Wikimedia\AtEase\AtEase;

class ContributionsList extends ContextSource {
	/**
	 * Username of target
	 *
	 * @var string
	 */
	private $user;

	/**
	 * Category name to target
	 *
	 * @var string
	 */
	private $category;

	/**
	 * The type of contributions to show.
	 * Can be either "createonly" to only show contributions that created the page
	 * or "notcreate" to show only contributions that modified an existing page.
	 * Any other value will show all contributions.
	 *
	 * @var string
	 */
	private $type;

	/**
	 * Only show contributions that were on or after this date.
	 *
	 * @var string
	 */
	private $dateFrom;

	/**
	 * Only show contributions that were on or before this date.
	 *
	 * @var string
	 */
	private $dateTo;

	/**
	 * A read-only database object
	 *
	 * @var \Wikimedia\Rdbms\IDatabase
	 */
	private $db;

	/**
	 * The index to actually be used for ordering. This is a single column,
	 * for one ordering, even if multiple orderings are supported.
	 *
	 * @var string
	 */
	protected $indexField = 'rev_timestamp';

	/**
	 * Result object for the query. Warning: seek before use.
	 *
	 * @var IResultWrapper
	 */
	public $result;

	/**
	 * Get all parameters required for the query
	 *
	 * @param string $user
	 * @param string|null $category
	 * @param string|null $type
	 * @param string|null $dateFrom
	 * @param string|null $dateTo
	 * @param IContextSource|null $context
	 */
	public function __construct( $user, $category = null, $type = null, $dateFrom = null,
		$dateTo = null, IContextSource $context = null ) {
		if ( $context ) {
			$this->setContext( $context );
		}

		$services = MediaWikiServices::getInstance();
		if ( method_exists( $services, 'getConnectionProvider' ) ) {
			// MW 1.4x+?
			$this->db = $services->getConnectionProvider()->getReplicaDatabase( false, 'contributionslist' );
		} else {
			// MW 1.39
			$this->db = $services->getDBLoadBalancer()->getConnection( DB_REPLICA, 'contributionslist' );
		}

		$this->user = $user;

		if ( $category ) {
			$this->category = Title::newFromText( $category, NS_CATEGORY );
		}

		if ( $type ) {
			$this->type = $type;
		}

		if ( $dateFrom ) {
			$this->dateFrom = wfTimestamp( TS_MW, strtotime( $dateFrom ) );
		}
		if ( $dateTo ) {
			// We want the end of the $dateTo, not the beginning.
			$this->dateTo = wfTimestamp( TS_MW, strtotime( 'tomorrow', strtotime( $dateTo ) ) - 1 );
		}

		$this->doQuery();
	}

	/**
	 * Perform the db query
	 * The query code is based on ContribsPager
	 */
	public function doQuery() {
		[ $tables, $fields, $conds, $fname, $options, $join_conds ] = $this->buildQueryInfo();
		$this->result = $this->db->select(
			$tables, $fields, $conds, $fname, $options, $join_conds
		);

		$this->result->rewind(); // Paranoia
	}

	/**
	 * @return string
	 */
	function getSqlComment() {
		return get_class( $this );
	}

	/**
	 * Generate an array to be turned into the full and final query.
	 *
	 * @return array
	 */
	protected function buildQueryInfo() {
		$fname = __METHOD__ . ' (' . $this->getSqlComment() . ')';
		$info = $this->getQueryInfo();
		$tables = $info['tables'];
		$fields = $info['fields'];
		$conds = isset( $info['conds'] ) ? $info['conds'] : [];
		$options = isset( $info['options'] ) ? $info['options'] : [];
		$join_conds = isset( $info['join_conds'] ) ? $info['join_conds'] : [];

		$options['ORDER BY'] = $this->indexField . ' DESC';

		if ( $this->dateFrom ) {
			$conds[] = $this->indexField . '>=' . $this->db->addQuotes( $this->dateFrom );
		}
		if ( $this->dateTo ) {
			$conds[] = $this->indexField . '<=' . $this->db->addQuotes( $this->dateTo );
		}

		return [ $tables, $fields, $conds, $fname, $options, $join_conds ];
	}

	/**
	 * Generate an array of basic query info.
	 *
	 * @return array
	 */
	function getQueryInfo() {
		[ $tables, $index, $userCond, $join_cond ] = $this->getUserCond();

		if ( $this->category instanceof Title ) {
			$tables[] = 'categorylinks';
			$conds = array_merge( $userCond, [ 'cl_to' => $this->category->getDBkey() ] );
			$join_cond['categorylinks'] = [ 'INNER JOIN', 'cl_from = page_id' ];
		} else {
			$conds = $userCond;
		}

		$user = $this->getUser();
		// Paranoia: avoid brute force searches (bug 17342)
		if ( !$user->isAllowed( 'deletedhistory' ) ) {
			$conds[] = $this->db->bitAnd( 'rev_deleted', RevisionRecord::DELETED_USER ) . ' = 0';
		} elseif ( !$user->isAllowed( 'suppressrevision' ) ) {
			$conds[] = $this->db->bitAnd( 'rev_deleted', RevisionRecord::SUPPRESSED_USER ) .
				' != ' . RevisionRecord::SUPPRESSED_USER;
		}

		$revisionStore = MediaWikiServices::getInstance()->getRevisionStore();
		$pageQuery = $revisionStore->getQueryInfo( [ 'page' ] );
		$userQuery = $revisionStore->getQueryInfo( [ 'user' ] );

		$options = [];
		if ( $index ) {
			$options['USE INDEX'] = [ 'revision' => $index ];
		}

		$queryInfo = [
			// The ordering is VERY precise and the use of the plus operator is intentional!
			'tables' => $pageQuery['tables'] + $tables + $userQuery['tables'],
			'fields' => array_merge(
				$revisionStore->getQueryInfo()['fields'], $userQuery['fields'],
				[
					'page_namespace', 'page_title', 'page_is_new', 'page_id',
					'page_latest', 'page_is_redirect', 'page_len'
				]
			),
			'conds' => $conds,
			'options' => $options,
			'join_conds' => array_merge( $join_cond, $pageQuery['joins'], $userQuery['joins'] )
		];

		return $queryInfo;
	}

	/**
	 * Generate the condition that will fetch revisions of a specific user
	 *
	 * @return array
	 */
	function getUserCond() {
		$condition = [];
		$join_conds = [];
		$tables = [ 'revision', 'page', 'user' ];
		$index = false;

		$services = MediaWikiServices::getInstance();
		$userIdentity = $services->getUserIdentityLookup()->getUserIdentityByName( $this->user );
		// $actorId = $userIdentity ? $userIdentity->getActorId() : null; // E_DEPRECATED
		$actorId = $userIdentity ? $services->getActorNormalization()->findActorId( $userIdentity, $this->db ) : null;

		if ( $actorId ) {
			$condition['rev_actor'] = $actorId;
			// $index = 'user_timestamp';
		} else {
			// $condition['rev_user_text'] = $this->user;
			// $index = 'usertext_timestamp';
		}

		if ( $this->type == 'createonly' ) {
			$condition[] = 'rev_parent_id = 0';
		} elseif ( $this->type == 'notcreate' ) {
			$condition[] = 'rev_parent_id != 0';
		}

		return [ $tables, $index, $condition, $join_conds ];
	}

	function getIndexField() {
		return 'rev_timestamp';
	}

	/**
	 * Generate the HTML for a valid page title that links to its page
	 *
	 * @param stdClass $row Object databse row
	 * @return string HTML link to a page title
	 */
	function getLinkedTitle( $row ) {
		get_class( $row );

		$classes = [];
		$services = MediaWikiServices::getInstance();

		/*
		 * There may be more than just revision rows. To make sure that we'll only be processing
		 * revisions here, let's _try_ to build a revision out of our row (without displaying
		 * notices though) and then trying to grab data from the built object. If we succeed,
		 * we're definitely dealing with revision data and we may proceed, if not, we'll leave it
		 * to extensions to subscribe to the hook to parse the row.
		 */
		AtEase::suppressWarnings();
		try {
			$lookupService = $services->getRevisionLookup();
			$currentRevision = $lookupService->getRevisionByPageId( $row->page_id );

			if ( !$currentRevision ) {
				// ?!?
				$validRevision = false;
			} else {
				$validRevision = (bool)$currentRevision->getId();
			}
		} catch ( MWException $e ) {
			$validRevision = false;
		}
		AtEase::restoreWarnings();

		if ( $validRevision ) {
			$classes = [];

			$page = Title::newFromRow( $row );
			$link = $services->getLinkRenderer()->makeLink(
				$page,
				$page->getPrefixedText(),
				[ 'class' => 'contributionslist-title' ],
				$page->isRedirect() ? [ 'redirect' => 'no' ] : []
			);
		}

		return $link;
	}

	/**
	 * Get a list of contributions in the specified format
	 *
	 * @param string $format A valid format
	 * @return string HTML list
	 */
	public function getContributionsList( $format ) {
		if ( !in_array( strtolower( $format ), self::getValidFormats() ) ) {
			$format = 'ul';
		}
		return call_user_func( [ $this, 'getContributionsList_' . $format ] );
	}

	/**
	 * A list of valid list formats
	 *
	 * @return array
	 */
	public static function getValidFormats() {
		return [ 'ol', 'ul', 'plain' ];
	}

	/**
	 * Get an ordered list of contributions
	 *
	 * @return string HTML ordered list
	 */
	public function getContributionsList_ol() {
		return $this->getContributionsListList( 'ol' );
	}

	/**
	 * Get an unordered list of contributions
	 *
	 * @return string HTML unordered list
	 */
	public function getContributionsList_ul() {
		return $this->getContributionsListList( 'ul' );
	}

	/**
	 * Get a list of contributions
	 *
	 * @param string $type An HTML list type: 'ol' or 'ul'
	 * @return string HTML list
	 */
	public function getContributionsListList( $type ) {
		$html = Html::openElement( $type, [ 'class' => 'contributionslist' ] );
		foreach ( $this->result as $row ) {
			$html .= Html::rawElement( 'li', [], $this->getLinkedTitle( $row ) );
		}
		$html .= Html::closeElement( $type );

		return $html;
	}

	/**
	 * Get a plain comma-separated list of contributions with an "and" before the final list item
	 *
	 * @return string HTML plain list
	 */
	public function getContributionsList_plain() {
		$links = [];
		foreach ( $this->result as $row ) {
			$links[] = $this->getLinkedTitle( $row );
		}
		$lang = $this->getLanguage();
		$html = $lang->listToText( $links );
		return $html;
	}
}
