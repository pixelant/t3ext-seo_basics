<?php

namespace B13\SeoBasics\Controller;

/***************************************************************
 *  Copyright notice - MIT License (MIT)
 *
 *  (c) 2007-2014 Benni Mack <benni@typo3.org>
 *  All rights reserved
 *
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is
 *  furnished to do so, subject to the following conditions:
 *
 *  The above copyright notice and this permission notice shall be included in
 *  all copies or substantial portions of the Software.
 *
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *  THE SOFTWARE.
 ***************************************************************/

use \TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * This package includes all functions for generating XML sitemaps
 *
 */
class SitemapController
{

    /**
     * holds all configuration information for rendering the sitemap
     * @var array
     */
    protected $sitemapConfiguration = array();

    /**
     * contains an array of all URLs used in on the page
     * @var array
     */
    protected $usedUrls = array();

    /**
     * @var string
     */
    protected $baseURL = '';

    /**
     * @var string
     */
    protected $currentHostName = '';

    /**
     * language configuration array containing information about what languages include in sitemap
     * array of sys_language_uid => hreflang
     * @var array
     */
    protected $sysLanguageHrefLangMappings = array();

    /**
     * contains an array of pages to exclude from sitemap
     * @var array
     */
    protected $excludedPageUids = array();

    /**
     * if set only pages with doktype from array will be included in sitemap
     * @var array
     */
    protected $allowedDoktypes = array();

    /**
     * Generates a XML sitemap from the page structure, entry point for the page
     *
     * @param string $content the content to be filled, usually empty
     * @param array $configuration additional configuration parameters given via TypoScript
     * @return string the XML sitemap ready to render
     */
    public function renderXMLSitemap($content, $configuration)
    {
        $this->sitemapConfiguration = $configuration;

        $this->resolveBaseUrl();

        // -- do a 301 redirect to the "main" sitemap.xml if not already there
        if ($this->sitemapConfiguration['redirectToMainSitemap'] && $this->baseURL) {
            $sitemapURL = $this->baseURL . 'sitemap.xml';
            $requestURL = GeneralUtility::getIndpEnv('TYPO3_REQUEST_URL');
            if ($requestURL != $sitemapURL && strpos($requestURL, 'sitemap.xml')) {
                header('Location: ' . GeneralUtility::locationHeaderUrl($sitemapURL), true, 301);
            }
        }

        $this->excludedPageUids = GeneralUtility::trimExplode(',', $this->sitemapConfiguration['excludePages'], true);
        $this->allowedDoktypes = GeneralUtility::trimExplode(',', $this->sitemapConfiguration['allowedDoktypes'], true);
        $this->sysLanguageHrefLangMappings = $this->sitemapConfiguration['sysLanguageHrefLangMappings.'];

        $id = (int)$this->getFrontendController()->id;
        $treeRecords = $this->fetchPagesFromTreeStructure($id);

        foreach ($treeRecords as $row) {
            $item = $row['row'];
            $languageOverlayItems = array();
            $sitemapPages = array();

            // check if sysLanguageHrefLangMappings are set, then fetch page language overlays
            if (is_array($this->sysLanguageHrefLangMappings) && count($this->sysLanguageHrefLangMappings) > 0) {

                foreach ($this->sysLanguageHrefLangMappings as $sysLanguageUid => $hreflang) {

                    // No need to check page overlay for default language
                    if ($sysLanguageUid > 0) {

                        // Fetch overlay for language
                        $itemOverlayed = $this->getFrontendController()->sys_page->getPageOverlay($item, $sysLanguageUid);

                        // Could also include || !GeneralUtility::hideIfNotTranslated($itemOverlayed['l18n_cfg']),
                        // but would it be of any use to output in sitemap if there are no real translation?
                        if ($itemOverlayed['_PAGES_OVERLAY']) {
                            $languageOverlayItems[$sysLanguageUid] = $itemOverlayed;
                        }
                    }
                }
            }

            // "combine" all valid versions of page
            if ($this->includePageInSitemap($item)) {
                // default language
                $sitemapPages[0] = $item;
            }
            if (is_array($languageOverlayItems)) {
                foreach ($languageOverlayItems as $sysLanguageUid => $languageOverlayItem) {
                    if ($this->includePageInSitemap($languageOverlayItem)) {
                        // language overlays
                        $sitemapPages[$sysLanguageUid] = $languageOverlayItem;
                    }
                }
            }

            if (is_array($sitemapPages)) {
                foreach ($sitemapPages as $sysLanguageUid => $currentItem) {
                    $url = $this->buildPageUrl($currentItem);
                    $lastmod = $this->buildPageLastmod($currentItem);

                    if (!isset($this->usedUrls[$url])) {
                        $this->usedUrls[$url] = array(
                            'url' => $url,
                            'lastmod' => $lastmod
                        );
                    }
                    if (count($sitemapPages) > 1) {
                        foreach ($sitemapPages as $alternateSysLanguageUid => $alternateItem) {
                            $alternateUrl = $this->buildPageUrl($alternateItem);
                            $alternetHreflang = $this->sysLanguageHrefLangMappings[$alternateSysLanguageUid];
                            if (!isset($this->usedUrls[$url]['alternates'][$alternateUrl])) {
                                $this->usedUrls[$url]['alternates'][$alternateUrl] = array(
                                    'href' => $alternateUrl,
                                    'hreflang' => $alternetHreflang
                                );
                            }
                        }
                    }
                }
            }
        }

        // check for additional pages
        $additionalPages = trim($this->sitemapConfiguration['scrapeLinksFromPages']);
        if ($additionalPages) {
            $additionalPages = GeneralUtility::trimExplode(',', $additionalPages, true);
            if (count($additionalPages)) {
                $additionalSubpagesOfPages = $this->sitemapConfiguration['scrapeLinksFromPages.']['includingSubpages'];
                $additionalSubpagesOfPages = GeneralUtility::trimExplode(',', $additionalSubpagesOfPages);
                $this->fetchAdditionalUrls($additionalPages, $additionalSubpagesOfPages);
            }
        }

        // creating the XML output
        foreach ($this->usedUrls as $urlData) {
            // skip pages that are not on the same domain
            if (stripos($urlData['url'], $this->currentHostName) === false && !$this->sitemapConfiguration['ignoreSameDomainCheck']) {
                continue;
            }
            if ($urlData['lastmod']) {
                $lastModificationDate = '
        <lastmod>' . htmlspecialchars($urlData['lastmod']) . '</lastmod>';
            } else {
                $lastModificationDate = '';
            }
            // alternate hreflang
            $alternates = '';
            if ($urlData['alternates']) {
                foreach ($urlData['alternates'] as $key => $alternate) {
                    $alternates .= '
        <xhtml:link rel="alternate" hreflang="' . $alternate['hreflang'] . '" href="' . $alternate['href'] . '" />';
                }
            }

            $content .= '
    <url>
        <loc>' . htmlspecialchars($urlData['url']) . '</loc>' . $lastModificationDate . $alternates . '
    </url>';
        }

        // hook for adding additional urls
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['seo_basics']['sitemap']['additionalUrlsHook'])) {
            $_params = array(
                'content' => &$content,
                'usedUrls' => &$this->usedUrls,
            );
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['seo_basics']['sitemap']['additionalUrlsHook'] as $_funcRef) {
                GeneralUtility::callUserFunction($_funcRef, $_params, $this);
            }
        }

        // see https://www.google.com/webmasters/tools/docs/en/protocol.html for complete format
        $content =
'<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . $content . '
</urlset>';

        return $content;
    }


    /**
     * fetches all URLs from existing pages + the subpages (1-level)
     * and adds them to the $usedUrls array of the object
     *
     * @param array $additionalPages
     * @param array $additionalSubpagesOfPages array to keep track which subpages have been fetched already
     */
    protected function fetchAdditionalUrls($additionalPages, $additionalSubpagesOfPages = array())
    {

        $baseUrl = GeneralUtility::getIndpEnv('TYPO3_SITE_URL');
        foreach ($additionalPages as $additionalPage) {
            $newlyFoundUrls = array();
            $crawlSubpages = in_array($additionalPage, $additionalSubpagesOfPages);

            $additionalPageId = intval($additionalPage);
            if ($additionalPageId) {
                $additionalUrl = $baseUrl . 'index.php?id=' . $additionalPageId;
            } else {
                $pageParts = parse_url($additionalPage);
                if (!$pageParts['scheme']) {
                    $additionalUrl = $baseUrl . $additionalPage;
                } else {
                    $additionalUrl = $additionalPage;
                }
            }
            $additionalUrl = htmlspecialchars($additionalUrl);
            $foundUrls = $this->fetchLinksFromPage($additionalUrl);

            // add the urls to the used urls
            foreach ($foundUrls as $url) {
                if (!isset($this->usedUrls[$url]) && !isset($this->usedUrls[$url . '/'])) {
                    $this->usedUrls[$url] = array('url' => $url);
                    if ($crawlSubpages) {
                        $newlyFoundUrls[] = $url;
                    }
                }
            }

            // now crawl the subpages as well
            if ($crawlSubpages) {
                foreach ($newlyFoundUrls as $subPage) {
                    $foundSuburls = $this->fetchLinksFromPage($subPage);
                    foreach ($foundSuburls as $url) {
                        if (!isset($this->usedUrls[$url]) && !isset($this->usedUrls[$url . '/'])) {
                            $this->usedUrls[$url] = array('url' => $url);
                        }
                    }
                }
            }
        }
    }


    /**
     * function to fetch all links from a page
     * by making a call to fetch the contents of the URL (via getURL)
     * and then applying certain regular expressions
     *
     * also takes "nofollow" into account (!)
     *
     * @param string $url
     * @return array the found URLs
     */
    protected function fetchLinksFromPage($url)
    {
        $content = GeneralUtility::getUrl($url);
        $foundLinks = array();

        $result = array();
        $regexp = '/<a\s+(?:[^"\'>]+|"[^"]*"|\'[^\']*\')*href=("[^"]+"|\'[^\']+\'|[^<>\s]+)([^>]+)/i';
        preg_match_all($regexp, $content, $result);

        $baseUrl = GeneralUtility::getIndpEnv('TYPO3_SITE_URL');
        foreach ($result[1] as $pos => $link) {

            if (strpos($result[2][$pos], '"nofollow"') !== false || strpos($result[0][$pos], '"nofollow"') !== false) {
                continue;
            }

            $link = trim($link, '"');
            list($link) = explode('#', $link);
            $linkParts = parse_url($link);
            if (!$linkParts['scheme']) {
                $link = $baseUrl . ltrim($link, '/');
            }

            if ($linkParts['scheme'] == 'javascript') {
                continue;
            }

            if ($linkParts['scheme'] == 'mailto') {
                continue;
            }

            // don't include real files
            $fileName = basename($linkParts['path']);
            if (strpos($fileName, '.') !== false && file_exists(PATH_site . ltrim($linkParts['path'], '/'))) {
                continue;
            }

            if ($link != $url) {
                $foundLinks[$link] = $link;
            }
        }
        return $foundLinks;
    }

    /**
     * resolves the domain URL
     * that is used for all pages
     * precedence:
     *   - $this->sitemapConfiguration['useDomain']
     *   - config.baseURL
     *   - the domain record
     *   - config.absRefPrefix
     */
    protected function resolveBaseUrl()
    {
        $baseURL = '';

        if (isset($this->sitemapConfiguration['useDomain'])) {
            if ($this->sitemapConfiguration['useDomain'] == 'current') {
                $baseURL = GeneralUtility::getIndpEnv('TYPO3_SITE_URL');
            } else {
                $baseURL = $this->sitemapConfiguration['useDomain'];
            }
        }

        if (empty($baseURL)) {
            $baseURL = $this->getFrontendController()->baseUrl;
        }

        if (empty($baseURL)) {
            $domainPid = $this->getFrontendController()->findDomainRecord();
            if ($domainPid) {
                $domainRecords = $this->getFrontendController()->sys_page->getRecordsByField('sys_domain', 'pid', $domainPid, ' AND hidden=0 AND redirectTo = ""', '', 'sorting ASC', 1);
                if (count($domainRecords)) {
                    $domainRecord = reset($domainRecords);
                    $baseURL = $domainRecord['domainName'];
                }
            }
        }

        if (!empty($baseURL) && strpos($baseURL, '://') === false) {
            $baseURL = 'http://' . $baseURL;
        }

        if (empty($baseURL) && $this->getFrontendController()->absRefPrefix) {
            $baseURL = $this->getFrontendController()->absRefPrefix;
        }

        if (empty($baseURL)) {
            die('Please add a domain record at the root of your TYPO3 site in your TYPO3 backend.');
        }

        // add appending slash
        $this->baseURL = rtrim($baseURL, '/') . '/';
        $baseURLParts = parse_url($this->baseURL);
        $currentHostName = $baseURLParts['host'];
        if ($currentHostName !== null) {
            $this->currentHostName = $baseURLParts['host'];
        } else {
            $this->currentHostName = $this->baseURL;
        }
        return $this->baseURL;
    }


    /**
     * fetches the pages needed from the tree component
     *
     * @param int $id
     * @return array
     */
    protected function fetchPagesFromTreeStructure($id)
    {
        $depth = 50;
        $additionalFields = 'uid,pid,doktype,shortcut,crdate,SYS_LASTCHANGED,shortcut_mode';

        // Initializing the tree object
        $treeStartingRecord = $this->getFrontendController()->sys_page->getRawRecord('pages', $id, $additionalFields);

        // see if this page is a redirect from the parent page
        // and loop while parentid is not null and the parent is still a redirect
        $parentId = $treeStartingRecord['pid'];
        while ($parentId > 0) {
            $parentRecord = $this->getFrontendController()->sys_page->getRawRecord('pages', $parentId, $additionalFields);

                // check for shortcuts
            if ($this->sitemapConfiguration['resolveMainShortcut'] == 1) {
                if ($parentRecord['doktype'] == 4 && ($parentRecord['shortcut'] == $id || $parentRecord['shortcut_mode'] > 0)) {
                    $treeStartingRecord = $parentRecord;
                    $id = $parentId = $parentRecord['pid'];
                } else {
                    break;
                }
            } else {
                // just traverse the rootline up
                $treeStartingRecord = $parentRecord;
                $id = $parentId = $parentRecord['pid'];
            }
        }

        $tree = GeneralUtility::makeInstance('B13\\SeoBasics\\Tree\\PageTreeView');
        $tree->addField('SYS_LASTCHANGED', 1);
        $tree->addField('crdate', 1);
        $tree->addField('no_search', 1);
        $tree->addField('doktype', 1);
        $tree->addField('nav_hide', 1);

            // disable recycler and everything below
        $tree->init('AND doktype!=255' . $this->getFrontendController()->sys_page->enableFields('pages'));

        // Only select pages starting from next root page in rootline
        $rootLine = $this->getFrontendController()->rootLine;
        if (count($rootLine) > 0) {
            $i = count($rootLine) - 1;
            $page = $rootLine[$i];
            while (!(boolean)$page['is_siteroot'] && $i >= 0) {
                $i--;
                $page = $rootLine[$i];

            }
        }

            // create the tree from starting point
        $tree->getTree($id, $depth, '');

        $treeRecords = $tree->tree;
        array_unshift($treeRecords, array('row' => $treeStartingRecord));
        return $treeRecords;
    }

    /**
     * check if page is valid for sitemap
     * @return bool
     */
    protected function includePageInSitemap($page)
    {
        $result = true;

        // don't render spacers, sysfolders etc, and the ones that have the
        // "no_search" checkbox
        if ($page['doktype'] >= 199 || intval($page['no_search']) == 1) {
            $result = false;
        }

        // remove "hide-in-menu" items
        if ($this->sitemapConfiguration['renderHideInMenu'] == 0 && intval($page['nav_hide']) == 1) {
            $result = false;
        }

        // explicitly remove items based on a deny-list
        if (!empty($this->excludedPageUids) && in_array($page['uid'], $this->excludedPageUids)) {
            $result = false;
        }

        // explicitly remove pages based on allowedDoktypes if set
        if (!empty($this->allowedDoktypes) && !in_array($page['doktype'], $this->allowedDoktypes)) {
            $result = false;
        }

        // remove page if page isn't language_overlay and hideIfDefaultLanguage is set
        if (!$page['_PAGES_OVERLAY'] && GeneralUtility::hideIfDefaultLanguage($page['l18n_cfg'])) {
            $result = false;
        }

        return $result;
    }

    /**
     * build url for page
     * @return string
     */
    protected function buildPageUrl($page)
    {

            $conf = array(
                'parameter' => $page['uid'],
                /*'forceAbsoluteUrl' => 1*/
            );
            // check if page is language overlay
            if ($page['_PAGES_OVERLAY'] && $page['_PAGES_OVERLAY_LANGUAGE']) {
                $conf['additionalParams'] = '&L=' . (int)$page['_PAGES_OVERLAY_LANGUAGE'];
            }

            // create the final URL
            $url  = $this->getFrontendController()->cObj->typoLink_URL($conf);

            $urlParts = parse_url($url);
            if (!$urlParts['host']) {
                $url = $this->baseURL . ltrim($url, '/');
            }

            return htmlspecialchars($url);
    }

    /**
     * build lastmod for page
     * @return string
     */
    protected function buildPageLastmod($page)
    {

            $lastmod = ($page['SYS_LASTCHANGED'] ? $page['SYS_LASTCHANGED'] : $page['crdate']);

            // format date, see http://www.w3.org/TR/NOTE-datetime for possible formats
            $lastmod = date('c', $lastmod);
            return $lastmod;
    }

    /**
     * wrapper function for the current TSFE object
     * @return \TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController
     */
    protected function getFrontendController()
    {
        return $GLOBALS['TSFE'];
    }
}
