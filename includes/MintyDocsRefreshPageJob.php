<?php

/**
 * Job to refresh content on a page, typically used if #mintydocs_link or stored values (ex: Cargo) need to be fixed after publishing a set of pages for the first time; for use by Special:MintyDocsPublish.
 *
 * @author Ed Jamer
 */
class MintyDocsRefreshPageJob extends Job {

	/**
	 * @param Title $title
	 * @param array $params
	 * @param int $id
	 */
	function __construct( $title, $params = '', $id = 0 ) {
		parent::__construct( 'MDRefreshPage', $title, $params, $id );
	}


	/**
	 * Run a refreshPage job
	 * @return bool success
	 */
	function run() {
		$editSummary = '';
		if ( array_key_exists( 'edit_summary', $this->params ) ) {
			$editSummary = $this->params['edit_summary'];
		}
		$userID = $this->params['user_id'];
		$user = User::newFromID( $userID );

		try {
			$fromPage = WikiPage::factory( $this->params['page_source'] );
			$pageText = $fromPage->getContent()->getNativeData();
			MintyDocsUtils::createOrModifyPage( $this->title, $pageText, $editSummary, $user );
		} catch ( MWException $e ) {
			$this->error = 'MDRefreshPage: ' . $e->getMessage();
			return false;
		}

	}

}
