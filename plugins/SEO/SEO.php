<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik_Plugins
 * @package Piwik_SEO
 */
use Piwik\Version;
use Piwik\Plugin;
use Piwik\WidgetsList;

/**
 * @package Piwik_SEO
 */
class Piwik_SEO extends Plugin
{
    /**
     * @see Piwik_Plugin::getInformation
     */
    public function getInformation()
    {
        return array(
            'description'     => 'This Plugin extracts and displays SEO metrics: Alexa web ranking, Google Pagerank, number of Indexed pages and backlinks of the currently selected website.',
            'author'          => 'Piwik',
            'author_homepage' => 'http://piwik.org/',
            'version'         => Version::VERSION,
        );
    }

    /**
     * @see Piwik_Plugin::getListHooksRegistered
     */
    public function getListHooksRegistered()
    {
        $hooks = array('WidgetsList.add' => 'addWidgets');
        return $hooks;
    }

    function addWidgets()
    {
        WidgetsList::add('SEO', 'SEO_SeoRankings', 'SEO', 'getRank');
    }
}
