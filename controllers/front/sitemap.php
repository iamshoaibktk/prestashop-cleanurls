<?php
/**
 * Clean URLs - Remove IDs, 301 Redirects & SEO URLs
 *
 * @author    iamshoaibktk@gmail.com (WhatsApp: +923459016286)
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
use Iamshoaibktk\Cleanurls\Sitemap;

/**
 * /sitemap.xml (index) and /sitemap-<type>-<page>.xml (one page of URLs, all languages).
 *
 * @property Cleanurls $module
 */
class CleanurlsSitemapModuleFrontController extends ModuleFrontController
{
    public function initContent(): void
    {
        if (!Configuration::get('CLEANURLS_SITEMAP')) {
            Tools::redirect('index.php?controller=404');
        }
        $sitemap = new Sitemap($this->context);
        $part = (string) Tools::getValue('part');
        $base = $this->context->link->getBaseLink((int) $this->context->shop->id);

        if ($part === '') {
            $xml = $sitemap->index(fn (string $type, int $page) => $base . 'sitemap-' . $type . '-' . $page . '.xml');
        } elseif (preg_match('/^([a-z_]+)-([0-9]+)$/', $part, $m)) {
            $xml = $sitemap->part($m[1], (int) $m[2], (bool) Configuration::get('CLEANURLS_SITEMAP_IMAGES'));
        } else {
            $xml = null;
        }
        if ($xml === null) {
            header('HTTP/1.1 404 Not Found');
            exit;
        }
        header('Content-Type: application/xml; charset=utf-8');
        header('X-Robots-Tag: noindex');
        exit($xml);
    }
}
