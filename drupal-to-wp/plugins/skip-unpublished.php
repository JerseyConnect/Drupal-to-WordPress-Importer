<?php
/**
 * Allow the importer to skip unpublished nodes
 */

add_filter( 'import_node_skip_node', array( 'SkipUnpublishedNodes', 'is_node_published' ), 1, 2 );

class SkipUnpublishedNodes {
	
	public static function is_node_published( $result, $node ) {
		
		return $result || ( $node['status'] == 0 );
		
	}
	
}

?>