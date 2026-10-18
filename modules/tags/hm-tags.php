<?php

/**
 * Tags modules
 * @package modules
 * @subpackage tags
 */

if (!defined('DEBUG_MODE')) { die(); }

/**
 * @subpackage tags/libs
 */
class Hm_Tags {
    use Hm_Repository;

    private static $data = array();

    public static function init($hmod) {
        self::initRepo('tags', $hmod->user_config, $hmod->session, self::$data);
    }

    public static function colorPalette() {
        return array(
            '#d93025', '#e8710a', '#f9ab00', '#188038', '#12805c', '#039be5',
            '#1a73e8', '#3949ab', '#8430ce', '#d01884', '#795548', '#5f6368',
        );
    }

    public static function defaultColor() {
        return '#5f6368';
    }

    public static function sanitizeColor($color) {
        return in_array($color, self::colorPalette(), true) ? $color : self::defaultColor();
    }

    /**
     * Derive the IMAP keyword a new label is stored under. The keyword is fixed
     * at creation so renaming a label does not orphan the messages carrying it.
     * @param string $name label name
     * @param array $existing labels already defined
     * @return string
     */
    public static function keywordFromName($name, $existing = array()) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($ascii === false) {
            $ascii = $name;
        }
        $keyword = trim(preg_replace('/_+/', '_', preg_replace('/[^A-Za-z0-9_]/', '_', $ascii)), '_');
        if ($keyword === '') {
            $keyword = 'label_'.mb_substr(md5($name), 0, 6);
        }
        $taken = array();
        foreach ($existing as $tag) {
            if (!empty($tag['keyword'])) {
                $taken[] = $tag['keyword'];
            }
        }
        $candidate = $keyword;
        $n = 2;
        while (in_array($candidate, $taken, true)) {
            $candidate = $keyword.'_'.$n++;
        }
        return $candidate;
    }

    /**
     * IMAP keyword of a label
     * @param string $tagId label id
     * @return string|false
     */
    public static function keyword($tagId) {
        $tag = self::get($tagId);
        if (!$tag || empty($tag['keyword'])) {
            return false;
        }
        return $tag['keyword'];
    }

    /**
     * Ids of the labels whose keyword appears in an IMAP flag list
     * @param string $flags flags as returned by FETCH, space separated
     * @return array
     */
    public static function tagIdsFromFlags($flags) {
        $present = array_filter(preg_split('/[\s\\\\]+/', (string) $flags));
        $ids = array();
        foreach (self::getAll() as $tagId => $tag) {
            if (!empty($tag['keyword']) && in_array($tag['keyword'], $present, true)) {
                $ids[] = $tagId;
            }
        }
        return $ids;
    }
}
