<?php

/**
 * Handles the 'mdrefresh' action.
 *
 * @author Ed Jamer
 * @ingroup MintyDocs
 */

class MintyDocsRefreshAction extends Action {
	/**
	 * Return the name of the action this object responds to
	 * @return string lowercase
	 */
	public function getName() {
		return 'mdrefresh';
	}

	/**
	 * The main action entry point. Do all output for display and send it
	 * to the context output.
	 * $this->getOutput(), etc.
	 */
	public function show() {
		$title = $this->page->getTitle();

		$mdRefreshPage = new MintyDocsRefresh();
		$mdRefreshPage->execute( $title );
	}

}
