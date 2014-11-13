<?php
/**
 * Variant of QueryPage which adds links to the live site to page links
 *
 * @ingroup SpecialPage
 */
abstract class LabsPageQueryPage extends PageQueryPage {
	/**
	 * Add links to the live site to page links
	 *
	 * @param Skin $skin
	 * @param object $row Result row
	 * @return string
	 */
	public function formatResult( $skin, $row ) {
		global $wgSitename, $wgWMFServer, $wgWMFScriptPath;

		$title = Title::makeTitleSafe( $row->namespace, $row->title );
		$html = parent::formatResult( $skin, $row );

		if ( $title instanceof Title ) {
			$html = $this->getLanguage()->specialList( $html, htmlspecialchars( $wgSitename )
				. wfMessage( 'colon-separator' )->escaped() . Linker::makeExternalLink(
					wfAppendQuery( $wgWMFServer . $wgWMFScriptPath, array(
						'title' => $title->getPrefixedDBkey(),
					) ), wfMessage( 'view' )->text()
				) . wfMessage( 'pipe-separator' )->escaped() . Linker::makeExternalLink(
					wfAppendQuery( $wgWMFServer . $wgWMFScriptPath, array(
						'title' => $title->getPrefixedDBkey(),
						'action' => 'edit',
					) ), wfMessage( 'edit' )->text()
				) . wfMessage( 'pipe-separator' )->escaped() . Linker::makeExternalLink(
					wfAppendQuery( $wgWMFServer . $wgWMFScriptPath, array(
						'title' => $title->getPrefixedDBkey(),
						'action' => 'history',
					) ), wfMessage( 'history' )->text()
				) . wfMessage( 'pipe-separator' )->escaped() . Linker::makeExternalLink(
					wfAppendQuery( $wgWMFServer . $wgWMFScriptPath, array(
						'title' => $title->getPrefixedDBkey(),
						'action' => 'delete',
					) ), wfMessage( 'delete' )->text()
				) . wfMessage( 'pipe-separator' )->escaped() . Linker::makeExternalLink(
					wfAppendQuery( $wgWMFServer . $wgWMFScriptPath, array(
						'title' => $title->getPrefixedDBkey(),
						'action' => 'protect',
					) ), wfMessage( 'protect' )->text()
				)
			);
		}

		return $html;
	}
}
