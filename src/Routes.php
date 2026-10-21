<?php
/**
 * Clean URLs - Remove IDs, 301 Redirects & SEO URLs
 *
 * @author    iamshoaibktk@gmail.com (WhatsApp: +923459016286)
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Iamshoaibktk\Cleanurls;

/**
 * ID-free routes for each page type, installed into the core Dispatcher through the
 * actionAfterLoadRoutes hook. No class overrides.
 */
class Routes
{
    /**
     * Order matters: the catch-all category route must be added last, after the meta pages
     * (contact-us, cart…) and every other route, so it only catches what nothing else wants.
     */
    public const TYPES = [
        'product' => ['route' => 'product_rule', 'controller' => 'product', 'id' => 'id_product', 'default' => '{category:/}{rewrite}.html', 'keywords' => ['category', 'categories']],
        'cms_category' => ['route' => 'cms_category_rule', 'controller' => 'cms', 'id' => 'id_cms_category', 'default' => 'content/category/{rewrite}', 'keywords' => []],
        'cms' => ['route' => 'cms_rule', 'controller' => 'cms', 'id' => 'id_cms', 'default' => 'content/{rewrite}', 'keywords' => []],
        'manufacturer' => ['route' => 'manufacturer_rule', 'controller' => 'manufacturer', 'id' => 'id_manufacturer', 'default' => 'brand/{rewrite}', 'keywords' => []],
        'supplier' => ['route' => 'supplier_rule', 'controller' => 'supplier', 'id' => 'id_supplier', 'default' => 'supplier/{rewrite}', 'keywords' => []],
        'category' => ['route' => 'category_rule', 'controller' => 'category', 'id' => 'id_category', 'default' => '{rewrite}', 'keywords' => ['categories']],
    ];

    private const KEYWORDS = [
        'rewrite' => ['regexp' => '[_a-zA-Z0-9\pL\pS-]+', 'param' => 'cu_rewrite'],
        'category' => ['regexp' => '[_a-zA-Z0-9-\pL]*', 'param' => 'cu_category'],
        'categories' => ['regexp' => '[/_a-zA-Z0-9-\pL]*', 'param' => 'cu_categories'],
    ];

    public static function configKey(string $type): string
    {
        return 'CLEANURLS_RULE_' . strtoupper($type);
    }

    /**
     * Checks a URL pattern a merchant typed. Returns an error key, or null when valid.
     */
    public static function validate(string $type, string $rule): ?string
    {
        if (!isset(self::TYPES[$type])) {
            return 'unknown_type';
        }
        if (!str_contains($rule, '{rewrite}')) {
            return 'missing_rewrite';
        }
        if (preg_match('/\{[^}]*:?id[:}]/', $rule) || str_contains($rule, '{id')) {
            return 'has_id';
        }
        preg_match_all('/\{(?:[^{}:]*:)?([a-z_]+)(?::[^{}]*)?\}/', $rule, $m);
        foreach ($m[1] as $keyword) {
            if ($keyword !== 'rewrite' && !in_array($keyword, self::TYPES[$type]['keywords'], true)) {
                return 'bad_keyword';
            }
        }
        if (!preg_match('#^[a-zA-Z0-9{}:/._-]+$#', $rule) || str_starts_with($rule, '/')) {
            return 'bad_chars';
        }

        return null;
    }

    /**
     * Replaces the core ID-based routes of every language with clean ones.
     *
     * @param array<string, string> $rules type => rule, only for the enabled types
     */
    public static function install(\Dispatcher $dispatcher, int $idShop, array $rules): void
    {
        $languages = \Language::getIDs(false, $idShop);

        foreach (self::TYPES as $type => $def) {
            if (!isset($rules[$type])) {
                continue;
            }
            $keywords = ['rewrite' => self::KEYWORDS['rewrite']];
            foreach ($def['keywords'] as $k) {
                $keywords[$k] = self::KEYWORDS[$k];
            }
            foreach ($languages as $idLang) {
                $dispatcher->removeRoute($def['route'], $idLang, $idShop);
                $dispatcher->addRoute($def['route'], $rules[$type], $def['controller'], $idLang, $keywords, ['cu_type' => $type], $idShop);
            }
        }

        // The core Link class always passes "id" to these routes (other keywords only when the
        // route declares them). Declare "id" as a known-but-unused keyword so createUrl() drops it
        // instead of appending ?id=12. Only the in-memory route table is touched.
        $prop = new \ReflectionProperty(\Dispatcher::class, 'routes');
        $prop->setAccessible(true);
        $routes = $prop->getValue($dispatcher);
        foreach (self::TYPES as $type => $def) {
            if (!isset($rules[$type])) {
                continue;
            }
            foreach ($languages as $idLang) {
                if (isset($routes[$idShop][$idLang][$def['route']])) {
                    $routes[$idShop][$idLang][$def['route']]['keywords']['id'] ??= [
                        'regexp' => '[0-9]+', 'param' => null, 'required' => false, 'prepend' => '', 'append' => '',
                    ];
                }
            }
        }
        $prop->setValue($dispatcher, $routes);
    }
}
