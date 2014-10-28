<?php
/**
 * ContributionsList extension
 *
 * For more info see http://mediawiki.org/wiki/Extension:ContributionsList
 *
 * @file
 * @ingroup Extensions
 * @author Ike Hecht, 2014
 * @license GNU General Public Licence 2.0 or later
 */
$wgExtensionCredits['other'][] = array(
	'path' => __FILE__,
	'name' => 'ContributionsList',
	'author' => array(
		'Ike Hecht',
	),
	'version' => '0.1.0',
	'url' => 'https://www.mediawiki.org/wiki/Extension:ContributionsList',
	'descriptionmsg' => 'contributionslist-desc',
);

$wgAutoloadClasses['ContributionsListHooks'] = __DIR__ . '/ContributionsList.hooks.php';
$wgAutoloadClasses['ContributionsList'] = __DIR__ . '/ContributionsList.class.php';

$wgMessagesDirs['ContributionsList'] = __DIR__ . '/i18n';
$wgExtensionMessagesFiles['ContributionsListMagic'] = __DIR__ . '/ContributionsList.magic.php';

$wgHooks['ParserFirstCallInit'][] = 'ContributionsListHooks::setupParserFunction';
