GovPress Build Script
=====================

Once a day (or on demand) builds a single zip from all plugins on the currated plugin list and a zip of a WordPress install with those plugins plus the GovFresh theme. Grabs the latest stable version of each plugin, the theme, and WordPress core.

File will be GovPress.zip and GovPress-Plugins.zip in the `wp-content/uploads/` directory.

Plugin list is pulled from plugins.txt. Each plugin should be listed by slug, on a single line. Order does not matter.