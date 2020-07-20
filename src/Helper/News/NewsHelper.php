<?php

namespace Oneup\SiblingNavigation\Helper\News;

use Contao\Input;

class NewsHelper extends \Backend
{
    /**
     * Import the back end user object
     */
    public function __construct()
    {
        parent::__construct();
        $this->import('BackendUser', 'User');
    }

    /**
     * Get all news archives and return them as array
     * @return array
     */
    public function getNewsArchives()
    {
        if (!$this->User->isAdmin && !is_array($this->User->news)) {
            return [];
        }

        $arrArchives = [];
        $objArchives = $this->Database->execute("SELECT id, title FROM tl_news_archive ORDER BY title");

        while ($objArchives->next()) {
            if ($this->User->hasAccess($objArchives->id, 'news')) {
                $arrArchives[$objArchives->id] = $objArchives->title;
            }
        }

        return $arrArchives;
    }

    public function generateSiblingNavigation($objPage, $newsArchives, $newsOrder)
    {
        // Set the item from the auto_item parameter
        if (!isset($_GET['items']) && $GLOBALS['TL_CONFIG']['useAutoItem'] && isset($_GET['auto_item'])) {
            \Input::setGet('items', \Input::get('auto_item'));
        }

        // Do not index or cache the page if no news item has been specified
        if (!\Input::get('items')) {
            $objPage->noSearch = 1;
            $objPage->cache = 0;

            return [];
        }

        $this->news_archives = $this->sortOutProtected(deserialize($newsArchives));

        // Return if there are no archives
        if (!is_array($this->news_archives) || empty($this->news_archives)) {
            return [];
        }

        $alias = \Input::get('items');

        $current = \NewsModel::findByIdOrAlias($alias);

        if (!in_array($current->pid, $this->news_archives)) {
            $this->news_archives = [$current->pid];
        }


        $arrOptions = [
            'columns' => [
                "pid IN (?)",
                "published = '1'"
            ],
            'values' => [
                implode(',', $this->news_archives),
            ]
        ];
        $t = 'tl_news';
        switch ($newsOrder)
        {
            case 'order_headline_asc':
                $arrOptions['order'] = "$t.headline";
                break;

            case 'order_headline_desc':
                $arrOptions['order'] = "$t.headline DESC";
                break;

            case 'order_random':
                $arrOptions['order'] = "RAND()";
                break;

            case 'order_date_asc':
                $arrPrevOptions['order'] = "$t.time DESC, $t.date DESC";
                $arrPrevOptions['column'][] = "tl_news.date < ?";
                $arrPrevOptions['column'][] = "tl_news.time < ?";
                $arrPrevOptions['value'][] = $current->date;
                $arrPrevOptions['value'][] = $current->time;

                $arrNextOptions['order'] = "$t.time ASC, $t.date ASC";
                $arrNextOptions['column'][] = "tl_news.date > ?";
                $arrNextOptions['column'][] = "tl_news.time > ?";
                $arrNextOptions['value'][] = $current->date;
                $arrNextOptions['value'][] = $current->time;
                break;

            default:
                $arrPrevOptions['order'] = "$t.time ASC, $t.date ASC";
                $arrPrevOptions['column'][] = "tl_news.date > ?";
                $arrPrevOptions['column'][] = "tl_news.time > ?";
                $arrPrevOptions['value'][] = $current->date;
                $arrPrevOptions['value'][] = $current->time;

                $arrNextOptions['order'] = "$t.time DESC, $t.date DESC";
                $arrNextOptions['column'][] = "tl_news.date < ?";
                $arrNextOptions['column'][] = "tl_news.time < ?";
                $arrNextOptions['value'][] = $current->date;
                $arrNextOptions['value'][] = $current->time;
        }

        if(Input::get('year')) {
            $arrPrevOptions['column'][] = "tl_news.date >= ?";
            $arrPrevOptions['value'][] = strtotime(Input::get('year').'-01-01');

            $arrNextOptions['column'][] = "tl_news.date <= ?";
            $arrNextOptions['value'][] = strtotime(Input::get('year').'-12-31');
        }

        if(Input::get('month')) {
            $arrPrevOptions['column'][] = "tl_news.date >= ?";
            $arrPrevOptions['value'][] = strtotime(Input::get('month').'-01');

            $arrNextOptions['column'][] = "tl_news.date <= ?";
            $arrNextOptions['value'][] = strtotime(Input::get('month').'-31');
        }

        // find prev
        $prev = \NewsModel::findAll([
            'column' => $arrPrevOptions['column'],
            'value' => $arrPrevOptions['value'],
            'order' => $arrPrevOptions['order'],
            'limit' => 1,
        ]);

        if ($prev) {
            $prev = $prev->current();
        }

        $next = \NewsModel::findAll([
            'column' => $arrNextOptions['column'],
            'value' => $arrNextOptions['value'],
            'order' => $arrNextOptions['order'],
            'limit' => 1,
        ]);

        if ($next) {
            $next = $next->current();
        }

        // take care, prev/next are swapped <== its now correct
        return [
            'prev'      => $this->generateNewsUrl($objPage, $prev),
            'next'      => $this->generateNewsUrl($objPage, $next),
            'prevTitle' => $next->headline,
            'nextTitle' => $prev->headline,
            'objPrev'   => $prev,
            'objNext'   => $next,
        ];
    }

    protected function sortOutProtected($archives)
    {
        if (BE_USER_LOGGED_IN || !is_array($archives) || empty($archives)) {
            return $archives;
        }

        $this->import('FrontendUser', 'User');
        $objArchive = \NewsArchiveModel::findMultipleByIds($archives);
        $arrArchives = [];

        if ($objArchive !== null) {
            while ($objArchive->next()) {
                if ($objArchive->protected) {
                    if (!FE_USER_LOGGED_IN) {
                        continue;
                    }

                    $groups = deserialize($objArchive->groups);

                    if (!is_array($groups) || empty($groups) || !count(array_intersect($groups, $this->User->groups))) {
                        continue;
                    }
                }

                $arrArchives[] = $objArchive->id;
            }
        }

        return $arrArchives;
    }

    protected function generateNewsUrl($objPage, $news = null)
    {
        if (null === $news) {
            return null;
        }

        $strUrl = $this->generateFrontendUrl(
            $objPage->row(),
            (($GLOBALS['TL_CONFIG']['useAutoItem'] && !$GLOBALS['TL_CONFIG']['disableAlias'])
                ?  '/%s'
                : '/items/%s'), $objPage->language
        );

        $strUrl = sprintf(
            $strUrl,
            (($news->alias != '' && !$GLOBALS['TL_CONFIG']['disableAlias'])
                ? $news->alias
                : $news->id)
        );

        if($_SERVER['QUERY_STRING'])  $strUrl .= '?'.$_SERVER['QUERY_STRING'];
        return $strUrl;
    }
}
