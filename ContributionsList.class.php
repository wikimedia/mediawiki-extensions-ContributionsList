<?php

/**
 * ContributionsList - generates a list of contributions
 *
 * @author Ike Hecht
 */
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
	 * @var DatabaseBase
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
	 * @var ResultWrapper
	 */
	public $result;

	/**
	 * Get all parameters required for the query
	 *
	 * @param string $user
	 * @param string $category
	 * @param string $type
	 * @param string $dateFrom
	 * @param string $dateTo
	 * @param IContextSource $context
	 */
	public function __construct( $user, $category = null, $type = null, $dateFrom = null,
		$dateTo = null, IContextSource $context = null ) {
		if ( $context ) {
			$this->setContext( $context );
		}

		$this->db = wfGetDB( DB_SLAVE, 'contributionslist' );

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
			$this->dateTo = wfTimestamp( TS_MW, strtotime( "tomorrow", strtotime( $dateTo ) ) - 1 );
		}

		$this->doQuery();
	}

	/**
	 * Perform the db query
	 * The query code is based on ContribsPager
	 */
	public function doQuery() {
		# Use the child class name for profiling
		$fname = __METHOD__ . ' (' . get_class( $this ) . ')';
		wfProfileIn( $fname );

		list( $tables, $fields, $conds, $fname, $options, $join_conds ) = $this->buildQueryInfo();
		$this->result = $this->db->select(
			$tables, $fields, $conds, $fname, $options, $join_conds
		);

		$this->result->rewind(); // Paranoia

		wfProfileOut( $fname );
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
		$conds = isset( $info['conds'] ) ? $info['conds'] : array();
		$options = isset( $info['options'] ) ? $info['options'] : array();
		$join_conds = isset( $info['join_conds'] ) ? $info['join_conds'] : array();

		$options['ORDER BY'] = $this->indexField . ' DESC';

		if ( $this->dateFrom ) {
			$conds[] = $this->indexField .
				'>=' . $this->db->addQuotes( $this->dateFrom );
		}
		if ( $this->dateTo ) {
			$conds[] = $this->indexField .
				'<=' . $this->db->addQuotes( $this->dateTo );
		}

		return array( $tables, $fields, $conds, $fname, $options, $join_conds );
	}

	/**
	 * Generate an array of basic query info.
	 *
	 * @return array
	 */
	function getQueryInfo() {
		list( $tables, $index, $userCond, $join_cond ) = $this->getUserCond();

		if ( $this->category instanceof Title ) {
			$tables[] = 'categorylinks';
			$conds = array_merge( $userCond, array( 'cl_to' => $this->category->getDBkey() ) );
			$join_cond['categorylinks'] = array( 'INNER JOIN', 'cl_from = page_id' );
		} else {
			$conds = $userCond;
		}

		$user = $this->getUser();
		// Paranoia: avoid brute force searches (bug 17342)
		if ( !$user->isAllowed( 'deletedhistory' ) ) {
			$conds[] = $this->db->bitAnd( 'rev_deleted', Revision::DELETED_USER ) . ' = 0';
		} elseif ( !$user->isAllowed( 'suppressrevision' ) ) {
			$conds[] = $this->db->bitAnd( 'rev_deleted', Revision::SUPPRESSED_USER ) .
				' != ' . Revision::SUPPRESSED_USER;
		}

		# Don't include orphaned revisions
		$join_cond['page'] = Revision::pageJoinCond();
		# Get the current user name for accounts
		$join_cond['user'] = Revision::userJoinCond();

		$options = array();
		if ( $index ) {
			$options['USE INDEX'] = array( 'revision' => $index );
		}
		$queryInfo = array(
			'tables' => $tables,
			'fields' => array_merge(
				Revision::selectFields(), Revision::selectUserFields(),
				array( 'page_namespace', 'page_title', 'page_is_new',
				'page_latest', 'page_is_redirect', 'page_len' )
			),
			'conds' => $conds,
			'options' => $options,
			'join_conds' => $join_cond
		);

		return $queryInfo;
	}

	/**
	 * Generate the condition that will fetch revisions of a specific user
	 *
	 * @return array
	 */
	function getUserCond() {
		$condition = array();
		$join_conds = array();
		$tables = array( 'revision', 'page', 'user' );
		$index = false;

		$uid = User::idFromName( $this->user );
		if ( $uid ) {
			$condition['rev_user'] = $uid;
			$index = 'user_timestamp';
		} else {
			$condition['rev_user_text'] = $this->user;
			$index = 'usertext_timestamp';
		}

		if ( $this->type == 'createonly' ) {
			$condition[] = 'rev_parent_id = 0';
		} elseif ( $this->type == 'notcreate' ) {
			$condition[] = 'rev_parent_id != 0';
		}

		return array( $tables, $index, $condition, $join_conds );
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
		wfProfileIn( __METHOD__ );

		$classes = array();

		/*
		 * There may be more than just revision rows. To make sure that we'll only be processing
		 * revisions here, let's _try_ to build a revision out of our row (without displaying
		 * notices though) and then trying to grab data from the built object. If we succeed,
		 * we're definitely dealing with revision data and we may proceed, if not, we'll leave it
		 * to extensions to subscribe to the hook to parse the row.
		 */
		wfSuppressWarnings();
		try {
			$rev = new Revision( $row );
			$validRevision = (bool) $rev->getId();
		} catch ( MWException $e ) {
			$validRevision = false;
		}
		wfRestoreWarnings();

		if ( $validRevision ) {
			$classes = array();

			$page = Title::newFromRow( $row );
			$link = Linker::link(
					$page, htmlspecialchars( $page->getPrefixedText() ),
					array( 'class' => 'contributionslist-title' ),
					$page->isRedirect() ? array( 'redirect' => 'no' ) : array()
			);
		}

		wfProfileOut( __METHOD__ );

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
		return call_user_func( array( $this, 'getContributionsList_' . $format ) );
	}

	/**
	 * A list of valid list formats
	 *
	 * @return array
	 */
	public static function getValidFormats() {
		return array( 'ol', 'ul', 'plain' );
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
		$html = Html::openElement( $type, array( 'class' => 'contributionslist' ) );
		while ( $row = $this->result->fetchObject() ) {
			$html .= Html::rawElement( 'li', array(), $this->getLinkedTitle( $row ) );
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
		$links = array();
		while ( $row = $this->result->fetchObject() ) {
			$links[] = $this->getLinkedTitle( $row );
		}
		$lang = $this->getLanguage();
		$html = $lang->listToText( $links );
		return $html;
	}
}