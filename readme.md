FEM - File Elements Mirroring plugin for MODx
==============================================

Introduction
-------------

If you create your templates and chunks in files and use include snippets to link them in the MODx manager, this plugin will save you the trouble of having to create the elements entirely.

Based on some simple folder, file and tv/chunks naming conventions, the plugin will create these elements for you in the MODx manager:

-   Templates
-   Chunks
-   TVs
-   Context settings


Installation
-------------

1. Download the latest transport package under the packages directory.
2. Copy to your core/packages/ directory.
3. Install in package manager.


Folder Structure
----------------

FEM will recursively parse all contents under web_assets (in your modx root) and create the respective elements (chunks/templates/snippets). The folder names for chunks/templates/snippets can be defined in the plugin's properties.

- web_assets/
    - chunks/
        - category/ (optional)
        - subcat/
            - chunk_name.html
    - templates/
        - template_name.html
    - snippets/
        - snippet_name.php

With the setup above, the chunk in file `chunk_name.html` will be created with the name `fem.category.subcat.chunk_name`. You can use this in your template or other chunks now with the usual MODx tag syntax:

`[[$fem.category.subcat.chunk_name]]`

Note that all elements (templates/chunks/snippets/tvs/settings) will have a prefix (`fem.`) in their names. This prefix can be changed in the plugin's properties.



TVs
----

To have FEM automagically create a TV and assign it to your template you used it in, use the following naming convention:

`[[*prefix.optional-category.type_name]]`

So for example:

`[[*fem.common.image_banner]]`

Will create a IMAGE TV named `fem.common.image_banner`, captioned as 'banner' in the category 'common'. The type must be exactly named as one of the available types in your MODx installation (text, date, image, etc). This TV will now be accessible in the templates you used it in.


Settings
--------

Follow the same naming convention when naming your settings will have them automatically created in your 'web' context settings.