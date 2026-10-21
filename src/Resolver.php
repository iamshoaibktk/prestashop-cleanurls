<?php
/**
 * Clean URLs - Remove IDs, 301 Redirects & SEO URLs
 *
 * @author    iamshoaibktk@gmail.com (WhatsApp: +923459016286)
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Iamshoaibktk\Cleanurls;

/**
 * Finds the object behind a slug for one language and shop.
 */
class Resolver
{
    public function __construct(private int $idLang, private int $idShop)
    {
    }

    /**
     * @param string|null $parent category slug (product URLs) or category path (category URLs), used to pick between duplicates
     */
    public function find(string $type, string $slug, ?string $parent = null): ?int
    {
        if ($slug === '') {
            return null;
        }

        return match ($type) {
            'product' => $this->product($slug, $parent),
            'category' => $this->category($slug, $parent),
            'cms' => $this->simple('cms', 'id_cms', $slug),
            'cms_category' => $this->simple('cms_category', 'id_cms_category', $slug),
            'manufacturer' => $this->byName('manufacturer', 'id_manufacturer', $slug),
            'supplier' => $this->byName('supplier', 'id_supplier', $slug),
            default => null,
        };
    }

    /**
     * Old core URLs start with the ID: "12-blue-shirt", "12-34-blue-shirt" (product + combination).
     *
     * @return array{id: int, ipa: int}|null
     */
    public static function parseLegacy(string $slug): ?array
    {
        if (!preg_match('/^(\d+)(?:-(\d+))?(?:-(.*))?$/u', $slug, $m)) {
            return null;
        }

        return ['id' => (int) $m[1], 'ipa' => isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0];
    }

    public function exists(string $type, int $id): bool
    {
        $table = match ($type) {
            'product' => 'product_shop',
            'category' => 'category_shop',
            'cms' => 'cms_shop',
            'cms_category' => 'cms_category_shop',
            'manufacturer' => 'manufacturer_shop',
            'supplier' => 'supplier_shop',
            default => null,
        };
        if ($table === null || $id <= 0) {
            return false;
        }
        $key = 'id_' . $type;

        return (bool) \Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . bqSQL($table) . '` WHERE `' . bqSQL($key) . '` = ' . (int) $id . ' AND id_shop = ' . $this->idShop);
    }

    private function product(string $slug, ?string $categorySlug): ?int
    {
        $rows = \Db::getInstance()->executeS('SELECT pl.id_product, cl.link_rewrite AS category
            FROM `' . _DB_PREFIX_ . 'product_lang` pl
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON (ps.id_product = pl.id_product AND ps.id_shop = pl.id_shop)
            LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON (cl.id_category = ps.id_category_default AND cl.id_lang = pl.id_lang AND cl.id_shop = pl.id_shop)
            WHERE pl.link_rewrite = "' . pSQL($slug) . '" AND pl.id_lang = ' . $this->idLang . ' AND pl.id_shop = ' . $this->idShop . '
            ORDER BY ps.active DESC, pl.id_product ASC');

        return $this->pick($rows ?: [], 'id_product', $categorySlug === null ? null : fn ($r) => $r['category'] === $categorySlug);
    }

    private function category(string $slug, ?string $path): ?int
    {
        $rows = \Db::getInstance()->executeS('SELECT cl.id_category, c.level_depth
            FROM `' . _DB_PREFIX_ . 'category_lang` cl
            INNER JOIN `' . _DB_PREFIX_ . 'category` c ON (c.id_category = cl.id_category)
            INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON (cs.id_category = cl.id_category AND cs.id_shop = cl.id_shop)
            WHERE cl.link_rewrite = "' . pSQL($slug) . '" AND cl.id_lang = ' . $this->idLang . ' AND cl.id_shop = ' . $this->idShop . '
            AND c.id_parent > 0
            ORDER BY c.active DESC, c.level_depth ASC, cl.id_category ASC');
        if (!$rows) {
            return null;
        }
        if ($path !== null && count($rows) > 1) {
            // With {categories:/}{rewrite}, the parent path tells same-named categories apart.
            foreach ($rows as $row) {
                if ($this->categoryPath((int) $row['id_category']) === trim($path, '/')) {
                    return (int) $row['id_category'];
                }
            }
        }

        return (int) $rows[0]['id_category'];
    }

    private function categoryPath(int $idCategory): string
    {
        $parts = [];
        foreach ((new \Category($idCategory, $this->idLang, $this->idShop))->getParentsCategories($this->idLang) as $cat) {
            if ((int) $cat['id_category'] !== $idCategory && !in_array((int) $cat['id_category'], \Link::$category_disable_rewrite, true)) {
                $parts[] = $cat['link_rewrite'];
            }
        }

        return implode('/', array_reverse($parts));
    }

    private function simple(string $table, string $key, string $slug): ?int
    {
        $id = \Db::getInstance()->getValue('SELECT l.`' . bqSQL($key) . '`
            FROM `' . _DB_PREFIX_ . bqSQL($table) . '_lang` l
            INNER JOIN `' . _DB_PREFIX_ . bqSQL($table) . '_shop` s ON (s.`' . bqSQL($key) . '` = l.`' . bqSQL($key) . '` AND s.id_shop = ' . $this->idShop . ')
            WHERE l.link_rewrite = "' . pSQL($slug) . '" AND l.id_lang = ' . $this->idLang . '
            ' . (in_array($table, ['cms', 'cms_category'], true) ? 'AND l.id_shop = ' . $this->idShop : '') . '
            ORDER BY l.`' . bqSQL($key) . '` ASC');

        return $id ? (int) $id : null;
    }

    /**
     * Brands and suppliers have no stored slug: PrestaShop builds it from the name.
     */
    private function byName(string $table, string $key, string $slug): ?int
    {
        $rows = \Db::getInstance()->executeS('SELECT t.`' . bqSQL($key) . '` AS id, t.name
            FROM `' . _DB_PREFIX_ . bqSQL($table) . '` t
            INNER JOIN `' . _DB_PREFIX_ . bqSQL($table) . '_shop` s ON (s.`' . bqSQL($key) . '` = t.`' . bqSQL($key) . '` AND s.id_shop = ' . $this->idShop . ')
            ORDER BY t.active DESC, t.`' . bqSQL($key) . '` ASC');
        foreach ($rows ?: [] as $row) {
            if (\Tools::str2url($row['name']) === $slug) {
                return (int) $row['id'];
            }
        }

        return null;
    }

    private function pick(array $rows, string $key, ?callable $prefer): ?int
    {
        if (!$rows) {
            return null;
        }
        if ($prefer !== null) {
            foreach ($rows as $row) {
                if ($prefer($row)) {
                    return (int) $row[$key];
                }
            }
        }

        return (int) $rows[0][$key];
    }
}
