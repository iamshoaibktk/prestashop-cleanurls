<?php
/**
 * Clean URLs - Remove IDs, 301 Redirects & SEO URLs
 *
 * @author    iamshoaibktk@gmail.com (WhatsApp: +923459016286)
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Iamshoaibktk\Cleanurls;

/**
 * Without IDs, two pages with the same slug would share one URL. This finds those clashes
 * and can rename the extra ones (slug-2, slug-3…), remembering the old slug for a 301.
 */
class Duplicates
{
    private const TABLES = [
        'product' => ['table' => 'product', 'key' => 'id_product', 'name' => 'name', 'shopInLang' => true],
        'category' => ['table' => 'category', 'key' => 'id_category', 'name' => 'name', 'shopInLang' => true],
        'cms' => ['table' => 'cms', 'key' => 'id_cms', 'name' => 'meta_title', 'shopInLang' => true],
        'cms_category' => ['table' => 'cms_category', 'key' => 'id_cms_category', 'name' => 'name', 'shopInLang' => true],
    ];

    /**
     * @return array<int, array{type: string, slug: string, id_lang: int, iso: string, items: array<int, array{id: int, name: string}>, reason: string}>
     */
    public static function find(int $idShop): array
    {
        $out = [];
        $isos = [];
        foreach (\Language::getLanguages(false, $idShop) as $l) {
            $isos[(int) $l['id_lang']] = $l['iso_code'];
        }

        foreach (self::TABLES as $type => $t) {
            $rows = \Db::getInstance()->executeS('SELECT l.link_rewrite, l.id_lang, GROUP_CONCAT(l.`' . $t['key'] . '` ORDER BY l.`' . $t['key'] . '`) AS ids,
                    GROUP_CONCAT(REPLACE(l.`' . $t['name'] . '`, "|", "/") ORDER BY l.`' . $t['key'] . '` SEPARATOR "|") AS names
                FROM `' . _DB_PREFIX_ . $t['table'] . '_lang` l
                WHERE l.id_shop = ' . $idShop . ($type === 'category' ? ' AND l.`id_category` > 1' : '') . '
                GROUP BY l.link_rewrite, l.id_lang HAVING COUNT(*) > 1') ?: [];
            foreach ($rows as $r) {
                $out[] = self::row($type, $r['link_rewrite'], (int) $r['id_lang'], $isos, explode(',', $r['ids']), explode('|', (string) $r['names']), 'same_slug');
            }
        }

        // A category whose slug equals a page URL (contact-us, cart…) can never be reached.
        $rows = \Db::getInstance()->executeS('SELECT cl.link_rewrite, cl.id_lang, cl.id_category, cl.name
            FROM `' . _DB_PREFIX_ . 'category_lang` cl
            INNER JOIN `' . _DB_PREFIX_ . 'meta_lang` ml ON (ml.url_rewrite = cl.link_rewrite AND ml.id_lang = cl.id_lang AND ml.id_shop = cl.id_shop)
            WHERE cl.id_shop = ' . $idShop) ?: [];
        foreach ($rows as $r) {
            $out[] = self::row('category', $r['link_rewrite'], (int) $r['id_lang'], $isos, [$r['id_category']], [$r['name']], 'page_clash');
        }

        return $out;
    }

    private static function row(string $type, string $slug, int $idLang, array $isos, array $ids, array $names, string $reason): array
    {
        $items = [];
        foreach ($ids as $i => $id) {
            $items[] = ['id' => (int) $id, 'name' => $names[$i] ?? ''];
        }

        return ['type' => $type, 'slug' => $slug, 'id_lang' => $idLang, 'iso' => $isos[$idLang] ?? '', 'items' => $items, 'reason' => $reason];
    }

    /**
     * Renames every clash except the first object of each group. Returns how many slugs changed.
     */
    public static function fix(int $idShop): int
    {
        $changed = 0;
        foreach (self::find($idShop) as $dup) {
            $t = self::TABLES[$dup['type']];
            $items = $dup['reason'] === 'page_clash' ? $dup['items'] : array_slice($dup['items'], 1);
            foreach ($items as $item) {
                $new = self::freeSlug($dup['type'], $dup['slug'], $dup['id_lang'], $idShop);
                Store::rememberSlug($dup['type'], $item['id'], $dup['id_lang'], $idShop, $dup['slug']);
                \Db::getInstance()->update($t['table'] . '_lang', ['link_rewrite' => pSQL($new)],
                    '`' . $t['key'] . '` = ' . $item['id'] . ' AND id_lang = ' . $dup['id_lang'] . ' AND id_shop = ' . $idShop);
                ++$changed;
            }
        }

        return $changed;
    }

    private static function freeSlug(string $type, string $slug, int $idLang, int $idShop): string
    {
        $t = self::TABLES[$type];
        for ($n = 2; $n < 1000; ++$n) {
            $candidate = $slug . '-' . $n;
            $taken = \Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . $t['table'] . '_lang`
                WHERE link_rewrite = "' . pSQL($candidate) . '" AND id_lang = ' . $idLang . ' AND id_shop = ' . $idShop)
                || \Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'meta_lang`
                WHERE url_rewrite = "' . pSQL($candidate) . '" AND id_lang = ' . $idLang . ' AND id_shop = ' . $idShop);
            if (!$taken) {
                return $candidate;
            }
        }

        return $slug . '-' . uniqid();
    }
}
