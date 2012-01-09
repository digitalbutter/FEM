<?php
/**
 * FEM
 *
 * Copyright 2011 by Ronald Ng <ronald@butter.com.hk>
 *
 * This file is part of FEM.
 *
 * FEM is free software; you can redistribute it and/or modify it under the
 * terms of the GNU General Public License as published by the Free Software
 * Foundation; either version 2 of the License, or (at your option) any later
 * version.
 *
 * FEM is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR
 * A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * FEM; if not, write to the Free Software Foundation, Inc., 59 Temple Place,
 * Suite 330, Boston, MA 02111-1307 USA
 *
 * @package FEM
 */
/**
 * FEM Plugin to copy elements (templates, chunks, TVs and snippets) from files to modx database
 *
 * Events:
 * OnHandleRequest
 *
 * @author Ronald Ng <ronald@butter.com.hk>
 *
 * @package FileElementsMirror
 */
if (!isset($_GET['flush'])){
    return;
}
$modx->log(MODX_LOG_LEVEL_INFO, '-- Begin fem.php plugin --');

function femCreateCat($catname, $parentCatId=0){
    global $modx;
    $plugin = $modx->getObject( 'modPlugin', array( 'name' => 'fem' ) );
    $scriptProperties = $plugin->getProperties();

    $elementNamePrefix = $scriptProperties['elementNamePrefix'];

    // recursively create parent categories from cat names using dot notation.
    $catname = str_replace($elementNamePrefix, "", $catname); // remove fem prefix
    $explodedCatName = explode(".", $catname);
    if (count($explodedCatName) > 1){
        $catname = array_pop($explodedCatName);
        $parentCatId = femCreateCat(implode(".", $explodedCatName));
    }

    $modx->log(MODX_LOG_LEVEL_INFO, 'Creating/Updating category '.$catname);

    $cat = $modx->getObject('modCategory', array('category' => $catname));
    if($cat == null){
        $cat = $modx->newObject('modCategory');
        $cat->set('category', $catname);
        $cat->set('parent', $parentCatId);
        $cat->save();
    }
    return $cat->get('id');
}

function femCreateTvs(array $tvNames, $template){
    global $modx;
    foreach($tvNames as $tvName){
        $modx->log(MODX_LOG_LEVEL_INFO, "Working on TV $tvName...");
        // Check if TV exists
        $tv = $modx->getObject('modTemplateVar', array('name' => $tvName));
        if ($tv==null){
            // Create the TV
            $modx->log(MODX_LOG_LEVEL_INFO, "TV doesn't exist, creating...");
            // Get the type of the TV off it's name - e.g. 'fem.common.image_banner' will be an image TV
            $type = array_shift( explode("_", array_pop(explode(".", $tvName)) ) );
            $modx->log(MODX_LOG_LEVEL_INFO, "TV type is $type...");
            $tv = $modx->newObject('modTemplateVar');
            $tv->set('type',$type);
            $tv->set('name',$tvName);
            $tv->set('caption',array_pop( explode("_", array_pop(explode(".", $tvName)) ) ));
            $tv->set('description','');

            $catNamePop = explode(".", $tvName);
            $cat = $modx->getObject('modCategory', array('category' => $catNamePop[count($catNamePop)-2]) );
            if(!$cat){
                // Create new cat
                $tv->set('category', femCreateCat($catNamePop[count($catNamePop)-2], 0) );
            } else {
                $tv->set('category',$cat->get('id'));
            }

            $tv->set('display','default');
            $tv->save();
            $modx->log(MODX_LOG_LEVEL_INFO, "TV created.");
        }
        // Check if TV is mapped to the template
        $templateVarTemplate = $modx->getObject('modTemplateVarTemplate',array(
                                                                              'templateid' => $template->get('id'),
                                                                              'tmplvarid' => $tv->get('id'),
                                                                         ));

        /* if not, add to the template */
        if ( empty($templateVarTemplate) ) {
            $templateVarTemplate = $modx->newObject('modTemplateVarTemplate');
            $templateVarTemplate->set('templateid',$template->get('id'));
            $templateVarTemplate->set('tmplvarid',$tv->get('id'));
            //$templateVarTemplate->set('rank',0);
            if ($templateVarTemplate->save() === false) {
                return $modx->error->failure($modx->lexicon('tvt_err_save'));
            }
            $modx->log(MODX_LOG_LEVEL_INFO, "Added tv template access to ".$template->get('templatename'));

            //TODO: remove mappings not in template?
        }/* elseif ( !empty($templateVarTemplate) ) {
            if ($templateVarTemplate->remove() === false) {
                return $modx->error->failure($modx->lexicon('tvt_err_remove'));
            }
        }*/
        $modx->log(MODX_LOG_LEVEL_INFO, "Done.");
    }
}

function femCreateChunk($chunkName, $filePath, $catId=0){
    global $modx;
    $modx->log(MODX_LOG_LEVEL_INFO, "Creating/Updating chunk $chunkName...");
    if (!file_exists($filePath)){
        $modx->log(MODX_LOG_LEVEL_ERROR, "Chunk file does not exist!");
        return false;
    }
    $contents = file_get_contents($filePath);
    $chunk = $modx->getObject('modChunk', array('name' => $chunkName));
    if($chunk == null){
        $chunk = $modx->newObject('modChunk');
    }
    $chunk->set('name', $chunkName);
    $chunk->set('category', $catId);
    $chunk->setContent($contents);
    $chunk->save();

    $modx->log(MODX_LOG_LEVEL_INFO, "Saved.");

    return $chunk;
}

function femParseChunkCb($chunkName, $key, $template){ return femParseChunk($chunkName, $template); }

/**
 * Does three things: creates the chunk (if it doesn't exist) using femCreateChunk, parses nested chunks and parses TVs
 *
 * @param  $chunkName
 * @param  $template
 * @return bool
 */
function femParseChunk($chunkName, $template){
    global $modx;
    $plugin = $modx->getObject( 'modPlugin', array( 'name' => 'fem' ) );
    $scriptProperties = $plugin->getProperties();

    $modx->log(MODX_LOG_LEVEL_INFO, "Parsing chunk $chunkName...");

    $elementNamePrefix = $scriptProperties['elementNamePrefix'];
    $chunkPath = $scriptProperties['elementsRoot']."/".$scriptProperties['chunksDirName']."/";
    $explodedChunkName = explode(".",str_replace($elementNamePrefix, "", $chunkName)); // replace fem prefix
    $chunkPath .= implode("/",$explodedChunkName).".html"; // create the file path
    $chunkCat = explode(".", $chunkName);
    array_pop($chunkCat);
    $chunkCat = implode(".", $chunkCat);

    // Update/Create chunk
    $chunk = femCreateChunk($chunkName, $chunkPath, femCreateCat($chunkCat));
    if (!$chunk){ return false; } // return if chunk not created.

    // Get fem prefixed placeholder/tv
    $modx->log(MODX_LOG_LEVEL_INFO, "Parsing TVs in chunk...");
    $chunkPregTvResults = array();
    if ( preg_match_all('/\[\[[\+|\*]('.preg_quote($elementNamePrefix, '/').'[^ |^\:|^\]\]]*)/i', $chunk->getContent(), $chunkPregTvResults) > 0 ){
        femCreateTvs(array_unique($chunkPregTvResults[1]), $template);
    }
    // Get fem prefixed settings
    $tplPregSettingsResults = array();
    if ( preg_match_all('/\[\[\+\+('.preg_quote($elementNamePrefix, '/').'[^ |^\:|^\]\]]*)/i', $chunk->getContent(), $tplPregSettingsResults) > 0 ){
        $modx->log(MODX_LOG_LEVEL_DEBUG, 'Parsing settings in chunk... ');
        foreach (array_unique($tplPregSettingsResults[1]) as $settingKey){
            femCreateSetting($settingKey);
        }
    }
    $chunkPregChunksResults = array();
    // Get nested chunks and parse them
    if ( preg_match_all('/\[\[\$('.preg_quote($elementNamePrefix, '/').'[^ |^\:|^\]\]]*)/i', $chunk->getContent(), $chunkPregChunksResults) > 0 ){
        $modx->log(MODX_LOG_LEVEL_INFO, "Parsing nested chunks...");
        array_walk($chunkPregChunksResults[1], 'femParseChunkCb', $template);
        $modx->log(MODX_LOG_LEVEL_INFO, "Done parsing nested chunks in $chunkName.");
    }
    $modx->log(MODX_LOG_LEVEL_INFO, "Done parsing chunk $chunkName.");
}

function femCreateTemplate($templateName, $contents, $catId=0){
    global $modx;
    $plugin = $modx->getObject( 'modPlugin', array( 'name' => 'fem' ) );
    $scriptProperties = $plugin->getProperties();
    $elementNamePrefix = $scriptProperties['elementNamePrefix'];

    $modx->log(MODX_LOG_LEVEL_INFO, 'Parsing template '.$templateName);
    $template = $modx->getObject('modTemplate', array('description' => $templateName));
    if($template == null){
        $modx->log(MODX_LOG_LEVEL_INFO, "$templateName does not exist in db, creating...");
        $template = $modx->newObject('modTemplate');
    }
    $template->set('templatename', str_replace("_", " ",array_pop(explode(".",$templateName))) );
    $template->set('description', $templateName);
    $template->set('category', $catId);
    $template->setContent($contents);
    $template->save();
    $tvNames= array();
    $chunkNames = array();
    //@TODO Get lexicon tags in template content
    $lexPregResults = array();
    if ( preg_match_all('/\[\[\$([^ |^\:|^\]\]]*)/i', $template->getContent(), $lexPregResults) > 0 ){
        $modx->log(MODX_LOG_LEVEL_DEBUG, 'Parsing lexicon entry in template content... ');
        $lexNames = array_unique($lexPregResults[1]);

    }
    // Get fem prefixed TVs in template content
    $tplPregResults = array();
    if ( preg_match_all('/\[\[\*('.preg_quote($elementNamePrefix, '/').'[^ |^\:|^\]\]]*)/i', $template->getContent(), $tplPregResults) > 0 ){
        $modx->log(MODX_LOG_LEVEL_DEBUG, 'Parsing TVs in template... ');
        $tvNames = array_unique($tplPregResults[1]);
        femCreateTvs($tvNames, $template);
    }
    // Get fem prefixed settings in template content
    $tplPregSettingsResults = array();
    if ( preg_match_all('/\[\[\+\+('.preg_quote($elementNamePrefix, '/').'[^ |^\:|^\]\]]*)/i', $template->getContent(), $tplPregSettingsResults) > 0 ){
        $modx->log(MODX_LOG_LEVEL_DEBUG, 'Parsing settings in template... ');
        foreach (array_unique($tplPregSettingsResults[1]) as $settingKey){
            femCreateSetting($settingKey);
        }
    }
    // Get fem prefixed chunk tags in template
    /*
    if ( preg_match_all('/\[\[\$('.preg_quote($elementNamePrefix, '/').'[^ |^\?|^\]\]]*)/i', $contents, $chunkNames) > 0 ){
        $modx->log(MODX_LOG_LEVEL_DEBUG, 'Parsing chunks in template... ');
        foreach($chunkNames[1] as $chunkName){
            // Parse chunk for TVs and nested chunks
            femParseChunk($chunkName, $template);
        }
    };
    */
    $modx->log(MODX_LOG_LEVEL_DEBUG, 'Done parsing template.');
}

function femCreateSnippet($snippetName, $contents, $catId=0){
    global $modx;
    $modx->log(MODX_LOG_LEVEL_INFO, 'Creating/Updating snippet '.$snippetName);
    $snippet = $modx->getObject('modSnippet', array('name' => $snippetName));
    if($snippet == null){
        $snippet = $modx->newObject('modSnippet');
    }
    $snippet->set('name', $snippetName);
    $snippet->set('category', $catId);
    $snippet->setContent($contents);
    $snippet->save();
}

function femCreateSetting($key){
    global $modx;
    $setting = $modx->getObject('modContextSetting', array('key' => $key));
    if($setting == null){
        $setting = $modx->newObject('modContextSetting');
        $setting->set('key', $key);
        $setting->set('context_key', "web");
        $setting->set('xtype', array_shift( explode("_",array_pop(explode(".", $key))) ) );
        //TODO: Create convention for namespace and/or area?
        //    $setting->set('namespace',);
        //    $setting->set('area',);
        //    $setting->set('editedon',);
        $setting->save();
        $modx->log(MODX_LOG_LEVEL_INFO, "Created setting: '$key'.");
    }
}

function femDoStuff($level, $path, $flag=null, $catString='', $parentCatId=0){
    global $modx;
    $plugin = $modx->getObject( 'modPlugin', array( 'name' => 'fem' ) );
    $scriptProperties = $plugin->getProperties();

    $chunkFolder = $scriptProperties['chunksDirName'];
    $templatesFolder = $scriptProperties['templateDirName'];
    $snippetsFolder = $scriptProperties['snippetDirName'];
    $elementNamePrefix = $scriptProperties['elementNamePrefix'];

    foreach(glob($path.'/*') as $currPath) {
        $modx->log(MODX_LOG_LEVEL_INFO, 'Processing path: '.$currPath." ($level levels deep)...");
        // Chunks/Templates path:
        $pathPop = array_pop( explode("/",$currPath) );
        if ( $pathPop == $chunkFolder || $pathPop == $templatesFolder || $pathPop==$snippetsFolder ){
            if (is_dir($currPath)){
                femDoStuff($level+1, $currPath, $pathPop);
            }
        } elseif ($flag==$chunkFolder || $flag==$templatesFolder || $flag==$snippetsFolder) {
            // Sub directories of chunks/templates path:
            if (is_dir($currPath)){
                femDoStuff($level+1, $currPath, $flag, $catString.$pathPop.'.', femCreateCat($pathPop, $parentCatId));
            } else {
                // Map this file to a chunk/template:
                switch ($flag){
                    // Back in use - faster to parse all chunks than to search for them in templates, also some chunks are only used by snippets and backend
                    case $chunkFolder:
                        $chunkName = str_replace('/.html$/', '', $elementNamePrefix.$catString.$pathPop);
                        femCreateChunk($chunkName, $currPath, $parentCatId);
                        break;
                    case $templatesFolder:
                        $snippetName = str_replace('/.html$/', '', $elementNamePrefix.$catString.$pathPop);
                        femCreateTemplate($snippetName, file_get_contents($currPath), $parentCatId);
                        break;
                    case $snippetsFolder:
                        $snippetName = str_replace('/.php$/', '', $elementNamePrefix.$catString.$pathPop);
                        femCreateSnippet($snippetName, file_get_contents($currPath), $parentCatId);
                        break;
                    default:
                        break;
                }
            }
        } else {

        }
    }
}
// MAIN
$femStartTime = microtime(true);
femDoStuff(0, $scriptProperties['elementsRoot']);
$modx->log(MODX_LOG_LEVEL_INFO, 'Clearing cache...');
if($modx->context->get('key') != "mgr")
    $modx->cacheManager->clearCache();
$modx->log(MODX_LOG_LEVEL_INFO, 'All done. ('.(microtime(true) - $femStartTime).' seconds)');
$modx->log(MODX_LOG_LEVEL_INFO, '-- End fem.php plugin ;D --');
