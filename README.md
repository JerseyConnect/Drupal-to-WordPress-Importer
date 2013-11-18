Drupal-to-WordPress-Importer
============================

Import a Drupal (tested with v6) site into a WordPress install

Instructions
------------

1. Install a fresh copy of WordPress in the `drupal-to-wp/wp` subfolder and point it at your WP database
1. Add any plugins you need to get custom post types, taxonomies, or roles
1. Edit `settings.php` to point to your WP install and the Drupal database you want to import
1. Open a web browser and visit the `drupal-to-wp` folder
1. Select your Drupal database and import parameters and click `IMPORT`
1. Make sure you write down the password displayed for imported users!

Notes
-----

This is a quick hack, with everything crammed into one file. If you get any errors or warnings you don't think should be popping up, create a new issue on this project's github page.

It goes without saying, but DO NOT point this at your production WordPress database until you are sure it will work the way you expect.