<?php


/*
 *	module_welcome.inc.php
 *	Module for displaying a short informative message for new users
 *
 *	Copyright Gottfried Haider, Danja Vasiliev 2011.
 *	This source code is licensed under the GNU General Public License.
 *	See the file COPYING for more details.
 *
 *	theagitist/hotglue2 fork, 2026-06-25: welcome-page prose wrapped in t()
 *	(module_i18n) for UI localization; English byte-identical.
 */


@require_once('config.inc.php');
require_once('html.inc.php');
require_once('modules.inc.php');
$page_has_object = false;


function welcome_alter_render_late($args)
{
	global $page_has_object;
	// we only display the informative div if there are no object already 
	// on the page we're starting to edit
	$page_has_object = true;
}


function welcome_render_page_late($args)
{
	global $page_has_object;
	if (!$args['edit'] || $page_has_object) {
		return false;
	}
	// we only display the information when there are no other pages in the 
	// content directory except the current one
	load_modules('glue');
	$pns = pagenames(array());
	$pns = $pns['#data'];
	if (1 < count($pns)) {
		return false;
	}
	
	html_add_css(base_url().'modules/welcome/welcome-edit.css');
	html_add_js(base_url().'modules/welcome/welcome.js');
	body_append('<div id="welcome-msg">'.nl());
	body_append(tab().'<span id="welcome-first"><img style="float:left; margin:5px 10px 0 5px" src="'.base_url().'modules/welcome/gun32.gif">'.t('welcome.title').'</span><br>'.nl());
	body_append(tab().t('welcome.ready').nl());
	body_append(tab().'<p>'.t('welcome.intro').'</p>'.nl());
	body_append(tab().'<span id="cont"><span id="text">'.t('welcome.step1').'</span>'.nl());
	body_append(tab().'<span id="text">'.t('welcome.step2').'</span>'.nl());
	body_append(tab().'<span id="text">'.t('welcome.step3').'</span>'.nl());
	body_append(tab().'<span id="text">'.t('welcome.step4_pre').(SHORT_URLS ? '' : '?').t('welcome.step4_post').'</span>'.nl());
	body_append(tab().'<span id="text">'.t('welcome.step5_pre').(SHORT_URLS ? '' : '?').t('welcome.step5_mid').base_url().'<b>'.(SHORT_URLS ? '' : '?').t('welcome.step5_post').'</span></span>'.nl());
	body_append(tab().'<p>'.t('welcome.browser_note').nl());
body_append(tab().'<p>'.t('welcome.more_info').nl());
	body_append(tab().'<p>'.t('welcome.enjoy').'</p>'.nl());
	body_append('</div>'.nl());
	return true;
}
