<?php
class TranslatablePage {

	private $title = null;
	private $text = null;
	private $revision = null;
	private $source = null;
	private $init = false;

	private function __construct( Title $title ) {
		$this->title = $title;
	}

	// Public constructors //

	public static function newFromText( Title $title, $text ) {
		$obj = new self( $title );
		$obj->text = $text;
		$obj->source = 'text';
		return $obj;
	}

	public static function newFromRevision( Title $title, $revision ) {
		$rev = Revision::newFromTitle( $title, $revision );
		if ( $rev === null ) throw new MWException( 'Revision is null' );

		$obj = new self( $title );
		$obj->source = 'revision';
		$obj->revision = $revision;
		return $obj;
	}

	public static function newFromTitle( Title $title ) {
		$obj = new self( $title );
		$obj->source = 'title';
		return $obj;
	}

	// Getters //

	public function getTitle() {
		return $this->title;
	}

	public function getText() {
		if ( $this->init === false ) {
			switch ( $this->source ) {
			case 'text':
				break;
			case 'title':
				$revision = $this->getMarkedTag();
			case 'revision':
				$rev = Revision::newFromTitle( $this->getTitle(), $this->revision );
				$this->text = $rev->getText();
				break;
			}
		}

		if ( !is_string($this->text) ) throw new MWException( 'We have no text' );
		return $this->text;
	}

	/**
	 * Revision is null if object was constructed using newFromText.
	 */
	public function getRevision() {
		return $this->revision;
	}

	// Public functions //

	public function getParse() {
		if ( isset($this->cachedParse) ) return $this->cachedParse;

		$text = $this->getText();

		$sections = array();
		$tagPlaceHolders = array();

		while (true) {
			$re = '~(<translate>)\s*(.*?)(</translate>)~s';
			$matches = array();
			$ok = preg_match_all( $re, $text, $matches, PREG_OFFSET_CAPTURE );
			if ( $ok === false ) return array( 'pt-error', 'tag', $text );
			if ( $ok === 0 ) break; // No matches

			// Do-placehold for the whole stuff
			$ph    = $this->getUniq();
			$start = $matches[0][0][1];
			$len   = strlen($matches[0][0][0]);
			$end   = $start + $len;
			$text = self::index_replace( $text, $ph, $start, $end );

			// Sectionise the contents
			// Strip the surrounding tags
			$contents = $matches[0][0][0]; // full match
			$start = $matches[2][0][1] - $matches[0][0][1]; // bytes before actual content
			$len   = strlen($matches[2][0][0]); // len of the content
			$end   = $start + $len;

			$ret = $this->sectionise( &$sections, substr( $contents, $start, $len ) );

			$tagPlaceHolders[$ph] =
				self::index_replace( $contents, $ret, $start, $end );
		}

		if ( strpos( $text, '<translate>' ) !== false )
			throw new TPException( array( 'pt-parse-open', $text ) );

		if ( strpos( $text, '</translate>' ) !== false )
			throw new TPException( array( 'pt-parse-close', $text ) );

		foreach ( $tagPlaceHolders as $ph => $value ) {
			$text = str_replace( $ph, $value, $text );
		}

		$parse = new TPParse( $this->getTitle() );
		$parse->template = $text;
		$parse->sections = $sections;

		// Cache it
		$this->cachedParse = $parse;

		return $parse;
	}

	// Inner functionality //

	protected function getUniq() {
		static $i = 0;
		return "\x7fUNIQ" . dechex(mt_rand(0, 0x7fffffff)) . dechex(mt_rand(0, 0x7fffffff)) . '|' . $i++;
	}

	protected static function index_replace( $string, $rep, $start, $end ) {
		return substr( $string, 0, $start ) . $rep . substr( $string, $end );
	}

	protected function sectionise( $sections, $text ) {
		$flags = PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE;
		$parts = preg_split( '~(\s*\n\n\s*|\s*$)~', $text, -1, $flags );
		
		$template = '';
		foreach ( $parts as $_ ) {
			if ( trim( $_ ) === '' ) {
				$template .= $_;
			} else {
				$ph = $this->getUniq();
				$sections[$ph] = $this->shakeSection( $_ );
				$template .= $ph;
			}
		}
		return $template;
	}

	protected function shakeSection( $content ) {
		$re = '~<!--T:(.*?)-->~';
		$matches = array();
		$count = preg_match_all( $re, $content, $matches, PREG_SET_ORDER );
		if ( $count === false ) throw new TPException( 'pt-error', 'shake', $content );
		if ( $count > 1 ) throw new TPException( 'pt-shake-dupid', $content );

		$section = new TPSection;
		if ( $count === 1 ) {
			foreach ( $matches as $match ) {
				list( /*full*/, $id ) = $match;
				$section->id = $id;

				$rer1 = '~^\s*<!--T:(.*?)-->\s*~'; // Normal sections
				$rer2 = '~\s*<!--T:(.*?)-->~'; // Sections with title
				$content = preg_replace( $rer1, '', $content );
				$content = preg_replace( $rer2, '', $content );
			}
		} else { // New section
			$section->id = -1;
		}

		if ( $content === '' ) throw new TPException( 'pt-shake-empty' );

		$section->text = $content;

		return $section;
	}

	public function addMarkedTag( $revision ) {
		$this->addTag( 'tp:mark', $revision );
	}

	public function addReadyTag( $revision ) {
		$this->addTag( 'tp:tag', $revision );
	}

	protected function addTag( $tag, $revision ) {
		$dbw = wfGetDB( DB_MASTER );

		// Can this be done in one query?
		$id = $dbw->selectField( 'revtag_type', 'rtt_id',
			array( 'rtt_name' => $tag ), __METHOD__ );

		$conds = array(
			'rt_page' => $this->getTitle()->getArticleId(),
			'rt_type' => $id,
			'rt_revision' => $revision
		);
		$dbw->delete( 'revtag', $conds, __METHOD__ );
		$dbw->insert( 'revtag', $conds, __METHOD__ );
	}

	public function getMarkedTag($db = DB_SLAVE) {
		return $this->getTag( 'tp:mark' );
	}
	public function getReadyTag($db = DB_SLAVE) {
		return $this->getTag( 'tp:tag' );
	}

	protected function getTag( $tag, $dbt = DB_SLAVE ) {
		$db = wfGetDB( $dbt );

		// Can this be done in one query?
		$id = $db->selectField( 'revtag_type', 'rtt_id',
			array( 'rtt_name' => $tag ), __METHOD__ );

		$fields = 'rt_revision';
		$conds = array(
			'rt_page' => $this->getTitle()->getArticleId(),
			'rt_type' => $id,
		);
		$options = array( 'ORDER BY', 'rt_revision DESC' );
		return $db->selectField( 'revtag', $fields, $conds, __METHOD__, $options );
	}


	public function getTranslationUrl( $code ) {
		$translate = SpecialPage::getTitleFor( 'Translate' );
		$params = array(
			'group' => 'page|' . $this->getTitle()->getPrefixedText(),
			'task' => 'view'
		);
		if ( $code ) $params['language'] = $code;
		return $translate->getFullURL( $params );
	}

	protected function getMarkedRevs( $tag ) {
		$db = wfGetDB( DB_SLAVE );

		// Can this be done in one query?
		$id = $db->selectField( 'revtag_type', 'rtt_id',
			array( 'rtt_name' => $tag ), __METHOD__ );

		$fields = array( 'rt_revision', 'rt_value' );
		$conds = array(
			'rt_page' => $this->getTitle()->getArticleId(),
			'rt_type' => $id,
		);
		$options = array( 'ORDER BY', 'rt_revision DESC' );
		return $db->select( 'revtag', $fields, $conds, __METHOD__, $options );
	}

	public function getTranslationPercentages() {
		// Check the memory cache, as this is very slow to calculate
		global $wgMemc;
		$memcKey = wfMemcKey( 'pt', 'status', $this->getTitle()->getPrefixedText() );
		$cache = $wgMemc->get( $memcKey );
		#if ( is_array( $cache ) ) return $cache;

		// Fetch the available translation pages from database
		$dbr = wfGetDB( DB_SLAVE );
		$likePattern = $dbr->escapeLike( $this->getTitle()->getDBkey() ) . '/%%';
		$res = $dbr->select(
			'page',
			array( 'page_namespace', 'page_title' ),
			array(
				'page_namespace' => $this->getTitle()->getNamespace(),
				"page_title LIKE '$likePattern'"
			), __METHOD__ );

		$titles = TitleArray::newFromResult( $res );

		// Calculate percentages for the available translations
		$group = MessageGroups::getGroup( 'page|' . $this->getTitle()->getPrefixedText() );
		if ( !$group instanceof WikiPageMessageGroup ) return null;

		$markedRevs = $this->getMarkedRevs( 'tp:mark' );


		$temp = array();
		foreach ( $titles as $t ) {
			if ( $t->getSubpageText() === $t->getText() ) continue;
			$collection = $group->initCollection( $t->getSubpageText() );
			$group->fillCollection( $collection );
			$temp[$collection->code] = $this->getPercentageInternal( $collection, $markedRevs );
		}
		// English is always up-to-date
		$temp['en'] = 1.00;

		// TODO: Ideally there would be some kind of purging here
		$wgMemc->set( $memcKey, $temp, 60*60*12 );
		return $temp;
	}

	protected function getPercentageInternal( $collection, $markedRevs ) {
		$count = count($collection);
		if ( $count === 0 ) return 0;

		$total = 0;

		foreach ( $collection as $key => $message ) {
			if ( !$message->translated() ) continue; // No score

			$score = 1;

			// Fuzzy halves
			if ( $message->fuzzy() ) $score *= 0.5; 

			// Reduce 20% for every newer revision than what is translated against
			$rev = $this->getTransrev( $key .'/' . $collection->code );
			foreach ( $markedRevs as $r ) {
				if ( $rev === $r->rt_revision ) break;
				$score *= 0.8;
			}
			$total += $score;
		}
		return $total/$count;
	}

	protected function getTransRev( $suffix ) {
		$id = $this->getTagId( 'tp:transver' );
		$title = Title::makeTitle( NS_TRANSLATIONS,
			$this->getTitle()->getPrefixedText() . '/' . $suffix
		);

		$db = wfGetDB( DB_SLAVE );
		$fields = 'rt_value';
		$conds = array(
			'rt_page' => $title->getArticleId(),
			'rt_type' => $id,
		);
		$options = array( 'ORDER BY', 'rt_revision DESC' );
		return $db->selectField( 'revtag', $fields, $conds, __METHOD__, $options );
			
	}

	protected function getTagId( $tag ) {
		static $tagcache = array();
		if ( !isset( $tagcache[$tag] ) ) {
			$db = wfGetDB( DB_SLAVE );
			$tagcache[$tag] = $db->selectField(
				'revtag_type', // Table
				'rtt_id', // Field
				array( 'rtt_name' => $tag ), // Conds
				__METHOD__
			);
		}
		return $tagcache[$tag];
	}
}

class TPException extends MWException {
	public function __construct( $msg ) {
		parent::__construct( call_user_func_array( 'wfMsg', $msg ) );
	}
}

