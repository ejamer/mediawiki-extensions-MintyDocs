<?php

/**
 * Handles the 'mdpublish' action.
 *
 * @author Yaron Koren
 * @ingroup MintyDocs
 */

class MintyDocsPublishAction extends Action {
	/**
	 * Return the name of the action this object responds to
	 * @return string lowercase
	 */
	public function getName() {
		return 'mdpublish';
	}

	/**
	 * The main action entry point. Do all output for display and send it
	 * to the context output.
	 * $this->getOutput(), etc.
	 */
	public function show() {
		$title = $this->page->getTitle();

		$mdPublishPage = new MintyDocsPublish();
		$mdPublishPage->execute( $title );
	}

}
