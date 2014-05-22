Plugins folder for Drupal to WP importer
=========================================

There is a small but growing list of actions and filters triggered during conversion, and 
integrators can drop scripts in this folder to alter the conversion process. 

Included plugins:

`listing-pages.php` - Instead of placeholder text, use a listing of child pages as content of generated parent pages.
`map-nodes.php` - Allow importers to arbitrarily re-map nodes to posts, including changing type and merging nodes
`map-routes.php` - Allow importers to create redirects for Drupal menu_router entries
`ninja-forms.php` - Convert Drupal webform nodes into pages with embedded Ninja Forms; import fields and submissions
`oembed.php` - Convert D7 attachment protocol links (like "youtube://") to oembed blocks on import
`redirection.php` - Generate redirects for all url_alias values for imported Drupal nodes
`skip-nodes.php` - Allow importers to define a simple list of Drupal node IDs to skip on import
`skip-unpublished.php` - Skip unpublished Drupal nodes when performing an import
`the-events-calendar.php` - Convert Drupal event nodes into event posts with The Events Calendar
