<?php
/**
 * Clean URLs - Remove IDs, 301 Redirects & SEO URLs
 *
 * @author    iamshoaibktk@gmail.com (WhatsApp: +923459016286)
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Iamshoaibktk\Cleanurls;

/**
 * Live XML sitemap: an index, then one file per type and page. Every URL lists its
 * translations (hreflang) and product images. Built on request, no cron job.
 */
class Sitemap
{
    public const PER_PAGE = 1000;
    public const TYPES = ['product', 'category', 'cms', 'manufacturer', 'supplier'];

    public function __construct(private \Context $context)
    {
    }

    /**
     * @return array<string, int> type => number of pages
     */
    public function pages(): array
    {
        $out = [];
        foreach (self::TYPES as $type) {
            $count = count($this->ids($type));
            if ($count) {
                $out[$type] = (int) ceil($count / self::PER_PAGE);
            }
        }

        return $out;
    }

    public function count(): int
    {
        $n = 0;
        foreach (self::TYPES as $type) {
            $n += count($this->ids($type));
        }

        return $n;
    }

    public function index(callable $partUrl): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($this->pages() as $type => $pages) {
            for ($p = 1; $p <= $pages; ++$p) {
                $xml .= '  <sitemap><loc>' . $this->esc($partUrl($type, $p)) . '</loc><lastmod>' . date('Y-m-d') . '</lastmod></sitemap>' . "\n";
            }
        }

        return $xml . '</sitemapindex>' . "\n";
    }

    public function part(string $type, int $page, bool $images): ?string
    {
        if (!in_array($type, self::TYPES, true) || $page < 1) {
            return null;
        }
        $ids = array_slice($this->ids($type), ($page - 1) * self::PER_PAGE, self::PER_PAGE);
        if (!$ids) {
            return null;
        }
        $languages = \Language::getLanguages(true, $this->context->shop->id);
        $multi = count($languages) > 1;
        $link = $this->context->link;

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
        foreach ($ids as $row) {
            $urls = [];
            foreach ($languages as $l) {
                $urls[$l['iso_code']] = $this->url($type, (int) $row['id'], (int) $l['id_lang']);
            }
            foreach ($languages as $l) {
                $xml .= "  <url>\n    <loc>" . $this->esc($urls[$l['iso_code']]) . "</loc>\n";
                if (!empty($row['date_upd'])) {
                    $xml .= '    <lastmod>' . substr($row['date_upd'], 0, 10) . "</lastmod>\n";
                }
                if ($multi) {
                    foreach ($languages as $alt) {
                        $xml .= '    <xhtml:link rel="alternate" hreflang="' . $this->esc($alt['language_code'] ?: $alt['iso_code']) . '" href="' . $this->esc($urls[$alt['iso_code']]) . '"/>' . "\n";
                    }
                }
                if ($images && $type === 'product') {
                    foreach (\Image::getImages((int) $l['id_lang'], (int) $row['id']) as $img) {
                        $xml .= '    <image:image><image:loc>' . $this->esc($link->getImageLink($row['rewrite'] ?: 'product', $row['id'] . '-' . $img['id_image'], \ImageType::getFormattedName('large'))) . "</image:loc></image:image>\n";
                    }
                }
                $xml .= "  </url>\n";
            }
        }
        unset($link);

        return $xml . "</urlset>\n";
    }

    private function url(string $type, int $id, int $idLang): string
    {
        $link = $this->context->link;
        $idShop = (int) $this->context->shop->id;

        return match ($type) {
            'product' => $link->getProductLink($id, null, null, null, $idLang, $idShop),
            'category' => $link->getCategoryLink($id, null, $idLang, null, $idShop),
            'cms' => $link->getCMSLink($id, null, null, $idLang, $idShop),
            'manufacturer' => $link->getManufacturerLink($id, null, $idLang, $idShop),
            'supplier' => $link->getSupplierLink($id, null, $idLang, $idShop),
        };
    }

    /**
     * @return array<int, array{id: int, date_upd?: string, rewrite?: string}>
     */
    private function ids(string $type): array
    {
        static $cache = [];
        $idShop = (int) $this->context->shop->id;
        $idLang = (int) $this->context->language->id;
        if (isset($cache[$type])) {
            return $cache[$type];
        }
        $p = _DB_PREFIX_;
        $sql = match ($type) {
            'product' => "SELECT ps.id_product AS id, ps.date_upd, pl.link_rewrite AS rewrite FROM {$p}product_shop ps
                LEFT JOIN {$p}product_lang pl ON (pl.id_product = ps.id_product AND pl.id_shop = ps.id_shop AND pl.id_lang = $idLang)
                WHERE ps.id_shop = $idShop AND ps.active = 1 AND ps.visibility IN ('both', 'catalog') AND ps.indexed IN (0, 1) ORDER BY ps.id_product",
            'category' => "SELECT c.id_category AS id, c.date_upd FROM {$p}category c INNER JOIN {$p}category_shop cs ON (cs.id_category = c.id_category AND cs.id_shop = $idShop)
                WHERE c.active = 1 AND c.id_parent > 0 AND c.is_root_category = 0 ORDER BY c.id_category",
            'cms' => "SELECT c.id_cms AS id FROM {$p}cms c INNER JOIN {$p}cms_shop cs ON (cs.id_cms = c.id_cms AND cs.id_shop = $idShop)
                WHERE c.active = 1 AND c.indexation = 1 ORDER BY c.id_cms",
            'manufacturer' => "SELECT m.id_manufacturer AS id, m.date_upd FROM {$p}manufacturer m INNER JOIN {$p}manufacturer_shop ms ON (ms.id_manufacturer = m.id_manufacturer AND ms.id_shop = $idShop)
                WHERE m.active = 1 ORDER BY m.id_manufacturer",
            'supplier' => "SELECT s.id_supplier AS id, s.date_upd FROM {$p}supplier s INNER JOIN {$p}supplier_shop ss ON (ss.id_supplier = s.id_supplier AND ss.id_shop = $idShop)
                WHERE s.active = 1 ORDER BY s.id_supplier",
        };

        return $cache[$type] = \Db::getInstance()->executeS($sql) ?: [];
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
