<?php

/**
 * Job to create or modify a page, for use by Special:MintyDocsPublish.
 *
 * @author Yaron Koren
 */
class MintyDocsCreatePageJob extends Job {

	/**
	 * @param Title $title
	 * @param array $params
	 * @param int $id
	 */
	function __construct( $title, $params = '', $id = 0 ) {
		parent::__construct( 'MDCreatePage', $title, $params, $id );
	}

	/**
	 * Run a createPage job
	 * @return bool success
	 */
	function run() {
		// If a page is supposed to have a parent but doesn't, we
		// don't want to save it, because that would lead to an
		// invalid page.
		if ( array_key_exists( 'parent_page', $this->params ) ) {
			$parent_page = $this->params['parent_page'];
			$parent_title = Title::newFromText( $parent_page );
			if ( !$parent_title->exists() ) {
				$this->error = "MDCreatePage: Parent page is missing; canceling save.";
				return false;
			}
		}

		$pageText = $this->params['page_text'];
		$editSummary = '';
		if ( array_key_exists( 'edit_summary', $this->params ) ) {
			$editSummary = $this->params['edit_summary'];
		}
		$userID = $this->params['user_id'];
		$user = User::newFromID( $userID );

		// ----------------
		// START PUBSWIKI-2431: Handling for jobs where page_text causes params array to exceed database blob size limit
		// 	Switching to revision-based publishing for large pages, to avoid db size restrictions
		// 	Consider using this process for all pages instead of passing page_text via jobs?
		if ( $this->params['long_page'] ) {
			if ( class_exists("RevisionRecord") ) {
				// THIS SECTION NEEDS TESTING WITH MW 1.35+ TO CONFIRM THAT IT RETRIEVES PAGE TEXT CORRECTLY
				// REFERENCE:   https://www.mediawiki.org/wiki/Manual:Revision.php/Migration
				$revision = MediaWikiServices::getInstance()->getRevisionLookup()->getRevisionById( $this->params['revision_id'] );
				$pageText = Content::serialize( $revision->getContent( SlotRecord::MAIN, RevisionRecord::RAW ) );
			}
			else {
				$revision = Revision::NewFromId( $this->params['revision_id'] );
				if ( !empty($revision) ) {
					$pageText = $revision->getSerializedData();
				}
				else {
					// can't find a matching revision; fall back on publishing content from 
					$fromPage = WikiPage::factory( $this->params['page_source'] );
					$pageText = $fromPage->getContent()->getNativeData();
				}
			}
		}
		// END PUBSWIKI-2431
		// ----------------

		try {
			MintyDocsUtils::createOrModifyPage( $this->title, $pageText, $editSummary, $user );
			// ----------------
			// START PUBSWIKI-2454.c: create a refresh page job for published pages, to ensure we don't have bad links
			$jobs = [];
			$params['user_id'] = $userID;
			$params['edit_summary'] = $editSummary . " (refresh)";
			$params['page_source'] = $this->title;
			$jobs[] = new MintyDocsRefreshPageJob( $this->title, $params );
			JobQueueGroup::singleton()->push( $jobs );
			// END PUBSWIKI-2454.c
			// ----------------
		} catch ( MWException $e ) {
			$this->error = 'MDCreatePage: ' . $e->getMessage();
			return false;
		}

		return true;
	}
}
