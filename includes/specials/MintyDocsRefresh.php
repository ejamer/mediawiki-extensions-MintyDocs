<?php

/**
 * MintyDocsRefresh allows previously published content to be re-saved to update stored content (ex: Cargo) or to fix timing-related parser issues (ex: #mintydocs_link).
 * Code is based very heavily on existing MintyDocsPublish functions.
 * 
 * @author Ed Jamer
 */

class MintyDocsRefresh extends SpecialPage {

	protected static $mNoActionNeededMessage = "Nothing to refresh.";
	protected static $mEditSummaryMsg = "mintydocs-refresh-editsummary";
	protected static $mSuccessMsg = "mintydocs-refresh-success";
	protected static $mSinglePageMsg = "mintydocs-refresh-singlepage";
	protected static $mButtonMsg = "mintydocs-refresh-button";
	private static $mCheckboxNumber = 1;

	/**
	 * Constructor
	 */
	function __construct( $pageName = null ) {
		if ( $pageName == null ) {
			$pageName = 'MintyDocsRefresh';
		}
		parent::__construct( $pageName );
	}

	function generateTargetTitle( $targetPageName ) {
		return Title::newFromText( $targetPageName, NS_MAIN );
	}

	function execute( $query ) {
		$this->setHeaders();
		$out = $this->getOutput();
		$req = $this->getRequest();

		if ( $query == '' ) {
			$pageName = $req->getVal( 'titleText' );
			$query = $this->generateTargetTitle( $pageName );
		}

		try {
			$title = $this->getTitleFromQuery( $query );
		} catch ( Exception $e ) {
			$out->addHTML( $e->getMessage() );
			return;
		}

		// Check permissions.
		$mdPage = MintyDocsUtils::pageFactory( $title );
		if ( !$mdPage->userCanAdminister( $this->getUser() ) ) {
			$this->displayRestrictionError();
			return;
		}

		$refresh = $req->getCheck( 'mdRefresh' );
		if ( $refresh ) {
			// Guard against cross-site request forgeries (CSRF).
			$validToken = $this->getUser()->matchEditToken( $req->getVal( 'csrf' ), $this->getName() );
			if ( !$validToken ) {
				$text = "This appears to be a cross-site request forgery; canceling.";
				$out->addHTML( $text );
				return;
			}

			$this->refreshAll();
			return;
		}

		$this->displayMainForm( $title );
	}

	function getTitleFromQuery( $query ) {
		if ( $query == null ) {
			throw new MWException( 'Page name must be set.' );
		}

		// Generate Title
		if ( $query instanceof Title ) {
			$title = $query;
		} else {
			$title = Title::newFromText( $query );
		}

		// Class-specific validation.
		$this->validateTitle( $title );

		// Error if page does not exist.
		if ( !$title->exists() ) {
			throw new MWException( $this->msg( "pagelang-nonexistent-page", '[[' . $title->getFullText() . ']]' ) );
		}

		return $title;
	}

	function validateTitle( $title ) {
		if ( $title->getNamespace() == MD_NS_DRAFT ) {
			throw new MWException( 'Must be a Published page!' );
		}
	}

	function displayMainForm( $title ) {
		$out = $this->getOutput();
		$req = $this->getRequest();
		$out->enableOOUI();

		// Display checkboxes.
		$text = '<form id="mdRefreshForm" action="" method="post">';
		$text .= Html::hidden( 'titleText', $title->getText() );
		if ( $req->getCheck( 'single' ) ) {
			$isSinglePage = true;
		} else {
			$mdPage = MintyDocsUtils::pageFactory( $title );
			$isSinglePage = ( $mdPage == null || $mdPage instanceof MintyDocsTopic );
		}
		if ( $isSinglePage ) {
			$toTitle = $this->generateTargetTitle( $title->getText() );
			$error = $this->validateSinglePageAction( $title );
			if ( $error != null ) {
				$out->addHTML( $error );
				return;
			}
			$text .= Html::element( 'p', null, $this->msg( self::$mSinglePageMsg )->text() );
			$text .= Html::hidden( 'page_name_1', $title->getText() );
		} else {
			$text .= '<h3>Pages for ' . $mdPage->getLink() . ':</h3>';
			$text .= ( new ListToggle( $this->getOutput() ) )->getHTML();
			$text .= Html::rawElement(
				'ul',
				[ 'style' => 'margin: 15px 0; list-style: none;' ],
				$this->displayPageParents( $mdPage )
			);
			$pagesTree = $this->makePagesTree( $mdPage );
			$text .= Html::rawElement(
				'ul',
				null,
				$this->displayCheckboxesForTree( $pagesTree['node'], $pagesTree['tree'] )
			);
		}

		if ( !$isSinglePage && self::$mCheckboxNumber == 1 ) {
			$text = '<p>(' . self::$mNoActionNeededMessage . ")</p>\n";
			$out->addHTML( $text );
			return;
		}

		$titleString = MintyDocsUtils::titleURLString( $this->getPageTitle() );
		$text .= Html::hidden( 'title', $titleString ) . "\n";
		$text .= Html::hidden( 'csrf', $this->getUser()->getEditToken( $this->getName() ) ) . "\n";
		$text .= "<br />\n" . new OOUI\ButtonInputWidget(
			[
				'name' => 'mdRefresh',
				'type' => 'submit',
				'flags' => [ 'progressive', 'primary' ],
				'label' => $this->msg( self::$mButtonMsg )->parse()
			]
		);

		$text .= '</form>';
		$out->addHTML( $text );
	}

	function validateSinglePageAction( $pageTitle ) {
		if ( !$pageTitle->exists() ) {
			return "Page doesn't exist; refresh action is only possible for already published content.";
		}
		return null;
	}

	function displayPageParents( $mdPage ) {
		$parentPage = $mdPage->getParentPage();
		if ( $parentPage == null ) {
			return '';
		}
		$parentMDPage = MintyDocsUtils::pageFactory( $parentPage );
		if ( $parentMDPage == null ) {
			return '';
		}

		// We use the <li> tag so that MediaWiki's toggle JS will
		// work on these checkboxes.
		return $this->displayPageParents( $parentMDPage ) .
			'<li class="parentPage"><em>' . $parentMDPage->getPageTypeValue() . '</em>: ' .
			$this->displayLine( $parentMDPage ) . '</li>';
	}

	static function makePagesTree( $mdPage, $numTopicIndents = 0 ) {
		$pagesTree = [ 'node' => $mdPage, 'tree' => [] ];
		if ( $mdPage instanceof MintyDocsProduct ) {
			$versions = $mdPage->getVersions();
			foreach ( $versions as $versionNum => $version ) {
				$pagesTree['tree'][] = self::makePagesTree( $version );
			}
			return $pagesTree;
		} elseif ( $mdPage instanceof MintyDocsVersion ) {
			$manuals = $mdPage->getAllManuals();
			foreach ( $manuals as $manualName => $manual ) {
				$pagesTree['tree'][] = self::makePagesTree( $manual );
			}
			return $pagesTree;
		} elseif ( $mdPage instanceof MintyDocsManual ) {
			$toc = $mdPage->getTableOfContentsArray( false );
			foreach ( $toc as $i => $element ) {
				list( $topic, $curLevel ) = $element;
				if ( $topic instanceof MintyDocsTopic || is_string( $topic ) ) {
					$pagesTree['tree'][] = self::makePagesTree( $topic, $curLevel - 1 );
				}
			}
			return $pagesTree;
		} elseif ( $mdPage instanceof MintyDocsTopic || is_string( $mdPage ) ) {
			if ( $numTopicIndents > 0 ) {
				$pagesTree['node'] = null;
				$pagesTree['tree'][] = self::makePagesTree( $mdPage, $numTopicIndents - 1 );
			}
			return $pagesTree;
		}
	}

	function displayCheckboxesForTree( $node, $tree ) {
		$text = '';
		if ( $node == null ) {
			// Do nothing.
		} elseif ( is_string( $node ) ) {
			$text .= "\n<li><em>" . $node . '</em></li>';
		} elseif ( $node instanceof MintyDocsTopic && $node->isBorrowed() ) {
			$text .= "\n<li>" . $node->getLink() . ' (this is a borrowed page)</li>';
		} else {
			$text .= "\n<li>" . $this->displayLine( $node ) . '</li>';
		}
		if ( count( $tree ) > 0 ) {
			$text .= '<ul>';
			foreach ( $tree as $node ) {
				$innerNode = $node['node'];
				$innerTree = $node['tree'];
				$text .= $this->displayCheckboxesForTree( $innerNode, $innerTree );
			}
			$text .= '</ul>';
		}
		return $text;
	}

	function displayLine( $mdPage ) {
		$pageTitle = $mdPage->getTitle();
		$pageName = $pageTitle->getText();
		$toTitle = $this->generateTargetTitle( $pageName );
		if ( !$toTitle->exists() ) {
			return $this->displayLineWithCheckbox( $mdPage, $pageName, false );
		}
		// if page exists, currently no reason not to refresh it
		$cannotBeRefreshed = false;
		return $this->displayLineWithCheckbox( $mdPage, $pageName, true, $cannotBeRefreshed );
	}

	function displayLineWithCheckbox( $mdPage, $pageName, $toPageExists, $cannotBeRefreshed ) {
		$checkboxAttrs = [
			'name' => 'page_name_' . self::$mCheckboxNumber++,
			'value' => $pageName,
			'class' => [ 'mdCheckbox' ],
			'selected' => true
		];
		if ( $cannotBeRefreshed ) {
			$checkboxAttrs['disabled'] = true;
		}
		$str = new OOUI\CheckboxInputWidget( $checkboxAttrs );
		if ( $toPageExists ) {
			$str .= $mdPage->getLink();
		} else {
			$str .= '<strong>' . $mdPage->getLink() . '</strong>';
		}
		if ( $cannotBeRefreshed ) {
			$str .= ' (this page cannot be refreshed)';
		}
		return $str;
	}

	function overwritingIsAllowed() {
		return true;
	}

	function refreshAll() {
		$req = $this->getRequest();
		$user = $this->getUser();
		$out = $this->getOutput();

		$jobs = [];

		$submittedValues = $req->getValues();
		$refreshTitles = [];

		foreach ( $submittedValues as $key => $val ) {
			if ( substr( $key, 0, 10 ) != 'page_name_' ) {
				continue;
			}

			$refreshPageName = $val;
			$refreshTitle = $this->generateTargetTitle( $refreshPageName );
			$refreshPage = WikiPage::factory( $refreshTitle );
			$refreshTitles[] = $refreshTitle;
			$params = [];
			$params['user_id'] = $user->getId();
			$params['edit_summary'] = $this->msg( self::$mEditSummaryMsg )->inContentLanguage()->text();
			$params['page_source'] = $refreshTitle;
			$jobs[] = new MintyDocsRefreshPageJob( $refreshTitle, $params );
		}

		JobQueueGroup::singleton()->push( $jobs );

		if ( count( $jobs ) == 0 ) {
			$text = 'No pages were specified.';
		} elseif ( count( $jobs ) == 1 ) {
			$text = 'The page ' . Linker::link( $refreshTitle ) . ' will be refreshed.';
		} else {
			$titlesStr = '';
			foreach ( $refreshTitles as $i => $title ) {
				if ( $i > 0 ) {
					$titlesStr .= ', ';
				}
				$titlesStr .= Linker::link( $title );
			}
			$text = $this->msg( self::$mSuccessMsg, $titlesStr )->text();
		}

		$out->addHTML( $text );
	}

	protected function getGroupName() {
		return 'mintydocs';
	}
}
