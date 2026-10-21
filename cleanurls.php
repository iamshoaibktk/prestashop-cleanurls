<?php
/**
 * Clean URLs - Remove IDs, 301 Redirects & SEO URLs
 *
 * @author    iamshoaibktk@gmail.com (WhatsApp: +923459016286)
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use Iamshoaibktk\Cleanurls\Duplicates;
use Iamshoaibktk\Cleanurls\Resolver;
use Iamshoaibktk\Cleanurls\Routes;
use Iamshoaibktk\Cleanurls\Sitemap;
use Iamshoaibktk\Cleanurls\Store;

class Cleanurls extends Module
{
    private const FLAGS = [
        'CLEANURLS_AUTO_301' => 1,
        'CLEANURLS_TRACK_SLUGS' => 1,
        'CLEANURLS_LOG_404' => 1,
        'CLEANURLS_404_TARGET' => 'none',
        'CLEANURLS_SITEMAP' => 1,
        'CLEANURLS_SITEMAP_IMAGES' => 1,
    ];

    /** Objects whose URL slug we watch for changes (ObjectModel class => type). */
    private const WATCHED = [
        'Product' => 'product',
        'Category' => 'category',
        'CMS' => 'cms',
        'CMSCategory' => 'cms_category',
        'Manufacturer' => 'manufacturer',
        'Supplier' => 'supplier',
    ];

    public function __construct()
    {
        $this->name = 'cleanurls';
        $this->tab = 'seo';
        $this->version = '1.0.0';
        $this->author = 'iamshoaibktk@gmail.com';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Clean URLs - Remove IDs, 301 Redirects & SEO URLs', [], 'Modules.Cleanurls.Admin');
        $this->description = $this->trans('Remove IDs and numbers from product, category, CMS, brand and supplier URLs, with automatic 301 redirects, a redirect manager, a 404 monitor and an XML sitemap.', [], 'Modules.Cleanurls.Admin');
        $this->confirmUninstall = $this->trans('Your URLs will go back to the PrestaShop format with IDs. Uninstall?', [], 'Modules.Cleanurls.Admin');
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    public function install(): bool
    {
        foreach (Routes::TYPES as $type => $def) {
            Configuration::updateValue(Routes::configKey($type), $def['default']);
            Configuration::updateValue('CLEANURLS_ON_' . strtoupper($type), 1);
        }
        foreach (self::FLAGS as $key => $value) {
            Configuration::updateValue($key, $value);
        }
        $hooks = ['actionAfterLoadRoutes', 'actionDispatcherBefore', 'moduleRoutes', 'actionFrontControllerInitBefore', 'actionFrontControllerSetMedia'];
        foreach (array_keys(self::WATCHED) as $class) {
            $hooks[] = 'actionObject' . $class . 'UpdateBefore';
        }

        return parent::install() && Store::install() && $this->registerHook($hooks);
    }

    public function uninstall(): bool
    {
        foreach (array_keys(Routes::TYPES) as $type) {
            Configuration::deleteByName(Routes::configKey($type));
            Configuration::deleteByName('CLEANURLS_ON_' . strtoupper($type));
        }
        foreach (array_keys(self::FLAGS) as $key) {
            Configuration::deleteByName($key);
        }

        return Store::uninstall() && parent::uninstall();
    }

    /* =================================================================
     * Routing
     * ================================================================= */

    /** @return array<string, string> enabled type => rule */
    public function activeRules(): array
    {
        $rules = [];
        foreach (Routes::TYPES as $type => $def) {
            $rule = (string) Configuration::get(Routes::configKey($type));
            if (Configuration::get('CLEANURLS_ON_' . strtoupper($type)) && Routes::validate($type, $rule) === null) {
                $rules[$type] = $rule;
            }
        }

        return $rules;
    }

    public function hookActionAfterLoadRoutes(array $params): void
    {
        if (!Configuration::get('PS_REWRITING_SETTINGS') || !$this->active) {
            return;
        }
        Routes::install($params['dispatcher'], (int) $params['id_shop'], $this->activeRules());
    }

    public function hookModuleRoutes(): array
    {
        if (!Configuration::get('CLEANURLS_SITEMAP')) {
            return [];
        }
        $params = ['fc' => 'module', 'module' => $this->name];

        return [
            'module-cleanurls-sitemap' => ['controller' => 'sitemap', 'rule' => 'sitemap.xml', 'keywords' => [], 'params' => $params],
            'module-cleanurls-sitemappart' => [
                'controller' => 'sitemap',
                'rule' => 'sitemap-{part}.xml',
                'keywords' => ['part' => ['regexp' => '[a-z_]+-[0-9]+', 'param' => 'part']],
                'params' => $params,
            ],
        ];
    }

    /**
     * Runs after the URL matched a route and before the page controller starts:
     * custom redirects first, then slug → ID, then old ID URLs and renamed slugs → 301.
     */
    public function hookActionDispatcherBefore(array $params): void
    {
        if (($params['controller_type'] ?? 0) !== Dispatcher::FC_FRONT || defined('_PS_ADMIN_DIR_')) {
            return;
        }
        $idShop = (int) $this->context->shop->id;
        $idLang = (int) $this->context->language->id;

        $path = $this->requestPath();
        if ($path !== '' && ($r = Store::matchRedirect($idShop, $path))) {
            $this->redirect($this->absolute($r['target']), $r['code']);
        }

        $type = (string) Tools::getValue('cu_type');
        if (!isset(Routes::TYPES[$type])) {
            return;
        }
        $def = Routes::TYPES[$type];
        $slug = (string) Tools::getValue('cu_rewrite');
        $parent = Tools::getValue('cu_category') ?: (Tools::getValue('cu_categories') ?: null);
        $resolver = new Resolver($idLang, $idShop);

        // Our routing parameters must not leak into links built from $_GET (language switcher…).
        unset($_GET['cu_type'], $_GET['cu_rewrite'], $_GET['cu_category'], $_GET['cu_categories']);

        $id = $resolver->find($type, $slug, $parent === null ? null : (string) $parent);
        if ($id) {
            $_GET[$def['id']] = $id;

            return;
        }

        if (Configuration::get('CLEANURLS_AUTO_301')) {
            $legacy = Resolver::parseLegacy($slug);
            if ($legacy && $resolver->exists($type, $legacy['id'])) {
                $this->redirect($this->objectUrl($type, $legacy['id'], $legacy['ipa']), 301);
            }
            $old = Store::findOldSlug($type, $slug, $idLang, $idShop);
            if ($old && $resolver->exists($type, $old)) {
                $this->redirect($this->objectUrl($type, $old), 301);
            }
        }

        Dispatcher::getInstance()->setController('pagenotfound');
    }

    public function objectUrl(string $type, int $id, int $ipa = 0): string
    {
        $link = $this->context->link;
        $lang = (int) $this->context->language->id;
        $shop = (int) $this->context->shop->id;

        return match ($type) {
            'product' => $link->getProductLink($id, null, null, null, $lang, $shop, $ipa ?: null, false, false, true),
            'category' => $link->getCategoryLink($id, null, $lang, null, $shop),
            'cms' => $link->getCMSLink($id, null, null, $lang, $shop),
            'cms_category' => $link->getCMSCategoryLink($id, null, $lang, $shop),
            'manufacturer' => $link->getManufacturerLink($id, null, $lang, $shop),
            'supplier' => $link->getSupplierLink($id, null, $lang, $shop),
        };
    }

    /** Path after the shop base URI, without the query string: "en/women", "old-page". */
    private function requestPath(): string
    {
        $path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
        $base = (string) $this->context->shop->getBaseURI();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        return ltrim($path, '/');
    }

    private function absolute(string $target): string
    {
        if (preg_match('#^https?://#i', $target)) {
            return $target;
        }

        return $this->context->shop->getBaseURL(true) . ltrim($target, '/');
    }

    private function redirect(string $url, int $code): void
    {
        $query = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
        if ($query !== '' && !str_contains($url, '?')) {
            [$base, $hash] = array_pad(explode('#', $url, 2), 2, null);
            $url = $base . '?' . $query . ($hash !== null ? '#' . $hash : '');
        }
        $texts = [301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 307 => 'Temporary Redirect', 308 => 'Permanent Redirect'];
        header('HTTP/1.1 ' . $code . ' ' . ($texts[$code] ?? 'Moved Permanently'));
        header('Location: ' . $url);
        header('Cache-Control: no-cache');
        exit;
    }

    /**
     * Product pages: pick the combination from the URL anchor (the ID is no longer in the path).
     */
    public function hookActionFrontControllerSetMedia(): void
    {
        if ($this->context->controller instanceof ProductController && Configuration::get('CLEANURLS_ON_PRODUCT')) {
            $this->context->controller->registerJavascript('module-cleanurls-combination', 'modules/' . $this->name . '/views/js/combination.js', ['position' => 'bottom', 'priority' => 150]);
        }
    }

    /* =================================================================
     * 404 monitor
     * ================================================================= */

    public function hookActionFrontControllerInitBefore(array $params): void
    {
        $controller = $params['controller'] ?? null;
        if (!$controller instanceof PageNotFoundController) {
            return;
        }
        $path = $this->requestPath();
        if (preg_match('/\.(?:jpe?g|png|gif|webp|avif|svg|ico|css|js|map|woff2?|ttf)$/i', $path)) {
            return;
        }
        if (Configuration::get('CLEANURLS_LOG_404')) {
            Store::log404((int) $this->context->shop->id, $path, (string) ($_SERVER['HTTP_REFERER'] ?? ''));
        }
        if (Configuration::get('CLEANURLS_404_TARGET') === 'home') {
            $this->redirect($this->context->link->getPageLink('index'), 302);
        }
    }

    /* =================================================================
     * Slug history: renamed pages keep working through a 301
     * ================================================================= */

    public function hookActionObjectProductUpdateBefore(array $params): void
    {
        $this->rememberOldSlugs('product', $params['object'] ?? null);
    }

    public function hookActionObjectCategoryUpdateBefore(array $params): void
    {
        $this->rememberOldSlugs('category', $params['object'] ?? null);
    }

    public function hookActionObjectCMSUpdateBefore(array $params): void
    {
        $this->rememberOldSlugs('cms', $params['object'] ?? null);
    }

    public function hookActionObjectCMSCategoryUpdateBefore(array $params): void
    {
        $this->rememberOldSlugs('cms_category', $params['object'] ?? null);
    }

    public function hookActionObjectManufacturerUpdateBefore(array $params): void
    {
        $this->rememberOldSlugs('manufacturer', $params['object'] ?? null);
    }

    public function hookActionObjectSupplierUpdateBefore(array $params): void
    {
        $this->rememberOldSlugs('supplier', $params['object'] ?? null);
    }

    private function rememberOldSlugs(string $type, $object): void
    {
        if (!Configuration::get('CLEANURLS_TRACK_SLUGS') || !$object instanceof ObjectModel || !$object->id) {
            return;
        }
        $id = (int) $object->id;
        if (in_array($type, ['manufacturer', 'supplier'], true)) {
            $oldName = (string) Db::getInstance()->getValue('SELECT name FROM `' . _DB_PREFIX_ . $type . '` WHERE id_' . $type . ' = ' . $id);
            if ($oldName !== '' && Tools::str2url($oldName) !== Tools::str2url((string) $object->name)) {
                foreach (Shop::getShops(false, null, true) as $idShop) {
                    foreach (Language::getIDs(false, (int) $idShop) as $idLang) {
                        Store::rememberSlug($type, $id, (int) $idLang, (int) $idShop, Tools::str2url($oldName));
                    }
                }
            }

            return;
        }
        $table = $type === 'cms_category' ? 'cms_category' : $type;
        $rows = Db::getInstance()->executeS('SELECT id_lang, id_shop, link_rewrite FROM `' . _DB_PREFIX_ . $table . '_lang` WHERE id_' . $table . ' = ' . $id) ?: [];
        $new = $object->link_rewrite;
        foreach ($rows as $r) {
            $newSlug = is_array($new) ? ($new[(int) $r['id_lang']] ?? null) : $new;
            if ($newSlug !== null && $newSlug !== '' && $newSlug !== $r['link_rewrite']) {
                Store::rememberSlug($type, $id, (int) $r['id_lang'], (int) $r['id_shop'], (string) $r['link_rewrite']);
            }
        }
    }

    /* =================================================================
     * Back office
     * ================================================================= */

    public function getContent(): string
    {
        $idShop = (int) $this->context->shop->id;
        $tab = (string) Tools::getValue('cu_tab', 'settings');
        $out = '';

        if (Tools::isSubmit('submitCleanurlsSettings')) {
            $errors = $this->saveSettings();
            $out .= $errors ? $this->displayError($errors) : $this->displayConfirmation($this->trans('Settings updated.', [], 'Modules.Cleanurls.Admin'));
            $tab = 'settings';
        } elseif (Tools::isSubmit('submitCleanurlsRedirect')) {
            $source = trim((string) Tools::getValue('cu_source'));
            $target = trim((string) Tools::getValue('cu_target'));
            $match = (string) Tools::getValue('cu_match');
            if ($source === '' || $target === '') {
                $out .= $this->displayError($this->trans('Please fill in both the old URL and the new URL.', [], 'Modules.Cleanurls.Admin'));
            } elseif ($match === 'regex' && !Store::regexValid($source)) {
                $out .= $this->displayError($this->trans('This regular expression is not valid.', [], 'Modules.Cleanurls.Admin'));
            } else {
                Store::addRedirect($idShop, $this->toPath($source), $target, (int) Tools::getValue('cu_code'), $match);
                Store::clear404WithPath($idShop, $this->toPath($source));
                $out .= $this->displayConfirmation($this->trans('Redirect added.', [], 'Modules.Cleanurls.Admin'));
            }
            $tab = 'redirects';
        } elseif (Tools::getValue('cu_delete_redirect')) {
            Store::deleteRedirect($idShop, (int) Tools::getValue('cu_delete_redirect'));
            $out .= $this->displayConfirmation($this->trans('Redirect deleted.', [], 'Modules.Cleanurls.Admin'));
            $tab = 'redirects';
        } elseif (Tools::isSubmit('cu_clear_404')) {
            Store::clear404($idShop);
            $out .= $this->displayConfirmation($this->trans('The 404 log has been cleared.', [], 'Modules.Cleanurls.Admin'));
            $tab = 'notfound';
        } elseif (Tools::isSubmit('cu_fix_duplicates')) {
            $n = Duplicates::fix($idShop);
            $out .= $this->displayConfirmation($this->trans('%count% URLs were renamed. Their old addresses now redirect.', ['%count%' => $n], 'Modules.Cleanurls.Admin'));
            $tab = 'duplicates';
        } elseif (Tools::isSubmit('cu_add_robots')) {
            $out .= $this->addSitemapToRobots()
                ? $this->displayConfirmation($this->trans('The sitemap line was added to robots.txt.', [], 'Modules.Cleanurls.Admin'))
                : $this->displayError($this->trans('robots.txt could not be written. Add the line by hand.', [], 'Modules.Cleanurls.Admin'));
            $tab = 'sitemap';
        }

        if (!Configuration::get('PS_REWRITING_SETTINGS')) {
            $out .= $this->displayWarning($this->trans('Friendly URLs are turned off. Turn them on in Shop Parameters > Traffic & SEO, or this module has no effect.', [], 'Modules.Cleanurls.Admin'));
        }

        $base = AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules');
        $duplicates = Duplicates::find($idShop);
        $sitemap = new Sitemap($this->context);
        $docs = [];
        foreach (glob(__DIR__ . '/docs/' . $this->name . '-*.pdf') ?: [] as $file) {
            $docs[substr(basename($file, '.pdf'), strlen($this->name) + 1)] = $this->_path . 'docs/' . basename($file);
        }
        ksort($docs);
        $this->context->smarty->assign([
            'cu_docs' => $docs,
            'cu_doc_url' => $docs[$this->context->language->iso_code] ?? ($docs['en'] ?? ''),
            'cu_tab' => $tab,
            'cu_base' => $base,
            'cu_settings_form' => $tab === 'settings' ? $this->renderSettings() : '',
            'cu_examples' => $tab === 'settings' ? $this->examples() : [],
            'cu_redirects' => Store::redirects($idShop),
            'cu_codes' => Store::CODES,
            'cu_prefill' => (string) Tools::getValue('cu_prefill'),
            'cu_404' => Store::notFound($idShop),
            'cu_duplicates' => $duplicates,
            'cu_dup_count' => count($duplicates),
            'cu_404_count' => count(Store::notFound($idShop, 1000)),
            'cu_history_count' => Store::countHistory($idShop),
            'cu_sitemap_on' => (bool) Configuration::get('CLEANURLS_SITEMAP'),
            'cu_sitemap_url' => $this->context->link->getBaseLink($idShop) . 'sitemap.xml',
            'cu_sitemap_count' => $tab === 'sitemap' ? $sitemap->count() : 0,
            'cu_robots_has' => $this->robotsHasSitemap(),
            'cu_shop_base' => $this->context->shop->getBaseURL(true),
            'cu_admin_links' => [
                'product' => $this->context->link->getAdminLink('AdminProducts', true, ['route' => 'admin_products_index']),
                'category' => $this->context->link->getAdminLink('AdminCategories'),
                'cms' => $this->context->link->getAdminLink('AdminCmsContent'),
                'cms_category' => $this->context->link->getAdminLink('AdminCmsContent'),
            ],
        ]);

        return $this->renderAuthor() . $out . $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }

    private function renderAuthor(): string
    {
        $this->context->smarty->assign('author_img', $this->_path . 'views/img/author.jpg');

        return $this->display(__FILE__, 'views/templates/admin/author.tpl');
    }

    private function toPath(string $url): string
    {
        $base = $this->context->shop->getBaseURL(true);
        $baseHttp = $this->context->shop->getBaseURL(false);
        foreach ([$base, $baseHttp, $this->context->shop->getBaseURI()] as $prefix) {
            if ($prefix !== '' && str_starts_with($url, $prefix)) {
                return ltrim(substr($url, strlen($prefix)), '/');
            }
        }

        return ltrim($url, '/');
    }

    /** @return string[] */
    private function saveSettings(): array
    {
        $errors = [];
        $messages = [
            'missing_rewrite' => $this->trans('The URL pattern must contain {rewrite}.', [], 'Modules.Cleanurls.Admin'),
            'has_id' => $this->trans('The URL pattern must not contain {id}: removing it is the point of this module.', [], 'Modules.Cleanurls.Admin'),
            'bad_keyword' => $this->trans('The URL pattern uses a keyword that is not available for this page type.', [], 'Modules.Cleanurls.Admin'),
            'bad_chars' => $this->trans('The URL pattern may only contain letters, numbers, slashes, dots, dashes and keywords, and must not start with a slash.', [], 'Modules.Cleanurls.Admin'),
        ];
        foreach (array_keys(Routes::TYPES) as $type) {
            $rule = trim((string) Tools::getValue(Routes::configKey($type)));
            $err = Routes::validate($type, $rule);
            if ($err !== null) {
                $errors[] = $this->typeLabel($type) . ': ' . ($messages[$err] ?? $err);
                continue;
            }
            Configuration::updateValue(Routes::configKey($type), $rule);
            Configuration::updateValue('CLEANURLS_ON_' . strtoupper($type), (int) Tools::getValue('CLEANURLS_ON_' . strtoupper($type)));
        }
        foreach (array_keys(self::FLAGS) as $key) {
            $value = Tools::getValue($key, Configuration::get($key));
            Configuration::updateValue($key, $key === 'CLEANURLS_404_TARGET' ? (in_array($value, ['none', 'home'], true) ? $value : 'none') : (int) $value);
        }

        return $errors;
    }

    public function typeLabel(string $type): string
    {
        return match ($type) {
            'product' => $this->trans('Products', [], 'Modules.Cleanurls.Admin'),
            'category' => $this->trans('Categories', [], 'Modules.Cleanurls.Admin'),
            'cms' => $this->trans('CMS pages', [], 'Modules.Cleanurls.Admin'),
            'cms_category' => $this->trans('CMS categories', [], 'Modules.Cleanurls.Admin'),
            'manufacturer' => $this->trans('Brands', [], 'Modules.Cleanurls.Admin'),
            'supplier' => $this->trans('Suppliers', [], 'Modules.Cleanurls.Admin'),
            default => $type,
        };
    }

    /** One real before/after example per type for the settings page. */
    private function examples(): array
    {
        $lang = (int) $this->context->language->id;
        $out = [];
        $samples = [
            'product' => (int) Db::getInstance()->getValue('SELECT id_product FROM `' . _DB_PREFIX_ . 'product_shop` WHERE active = 1 AND id_shop = ' . (int) $this->context->shop->id . ' ORDER BY id_product'),
            'category' => (int) Db::getInstance()->getValue('SELECT id_category FROM `' . _DB_PREFIX_ . 'category` WHERE level_depth = 2 AND active = 1 ORDER BY id_category'),
            'cms' => (int) Db::getInstance()->getValue('SELECT id_cms FROM `' . _DB_PREFIX_ . 'cms` WHERE active = 1 ORDER BY id_cms'),
            'manufacturer' => (int) Db::getInstance()->getValue('SELECT id_manufacturer FROM `' . _DB_PREFIX_ . 'manufacturer` WHERE active = 1 ORDER BY id_manufacturer'),
        ];
        foreach ($samples as $type => $id) {
            if (!$id) {
                continue;
            }
            $before = match ($type) {
                'product' => $this->legacyProductPath($id, $lang),
                'category' => $id . '-' . Db::getInstance()->getValue('SELECT link_rewrite FROM `' . _DB_PREFIX_ . 'category_lang` WHERE id_category = ' . $id . ' AND id_lang = ' . $lang),
                'cms' => 'content/' . $id . '-' . Db::getInstance()->getValue('SELECT link_rewrite FROM `' . _DB_PREFIX_ . 'cms_lang` WHERE id_cms = ' . $id . ' AND id_lang = ' . $lang),
                'manufacturer' => 'brand/' . $id . '-' . Tools::str2url((string) Manufacturer::getNameById($id)),
            };
            $after = Configuration::get('CLEANURLS_ON_' . strtoupper($type)) ? $this->objectUrl($type, $id) : '';
            $out[] = [
                'label' => $this->typeLabel($type),
                'before' => $this->context->shop->getBaseURL(true) . $before,
                'after' => $after,
            ];
        }

        return $out;
    }

    private function legacyProductPath(int $id, int $lang): string
    {
        $ipa = (int) Product::getDefaultAttribute($id);

        return $id . ($ipa ? '-' . $ipa : '') . '-' . Db::getInstance()->getValue('SELECT link_rewrite FROM `' . _DB_PREFIX_ . 'product_lang` WHERE id_product = ' . $id . ' AND id_lang = ' . $lang) . '.html';
    }

    private function renderSettings(): string
    {
        $t = fn (string $s) => $this->trans($s, [], 'Modules.Cleanurls.Admin');
        $switch = fn (string $name, string $label, string $desc = '') => [
            'type' => 'switch', 'label' => $label, 'name' => $name, 'desc' => $desc, 'is_bool' => true,
            'values' => [['id' => $name . '_on', 'value' => 1, 'label' => $t('Yes')], ['id' => $name . '_off', 'value' => 0, 'label' => $t('No')]],
        ];
        $patterns = [];
        $hints = [
            'product' => $t('Keywords: {rewrite} (required), {category:/} and {categories:/}. Default: {category:/}{rewrite}.html'),
            'category' => $t('Keywords: {rewrite} (required) and {categories:/} for the parent path. Default: {rewrite}'),
            'cms' => $t('Keyword: {rewrite}. Default: content/{rewrite}'),
            'cms_category' => $t('Keyword: {rewrite}. Default: content/category/{rewrite}'),
            'manufacturer' => $t('Keyword: {rewrite}. Default: brand/{rewrite}'),
            'supplier' => $t('Keyword: {rewrite}. Default: supplier/{rewrite}'),
        ];
        foreach (array_keys(Routes::TYPES) as $type) {
            $patterns[] = $switch('CLEANURLS_ON_' . strtoupper($type), $this->typeLabel($type));
            $patterns[] = ['type' => 'text', 'label' => $t('URL pattern'), 'name' => Routes::configKey($type), 'desc' => $hints[$type], 'prefix' => '/'];
        }

        $forms = [
            ['form' => [
                'legend' => ['title' => $t('URL patterns'), 'icon' => 'icon-link'],
                'input' => $patterns,
                'submit' => ['title' => $t('Save')],
            ]],
            ['form' => [
                'legend' => ['title' => $t('Redirects and monitoring'), 'icon' => 'icon-random'],
                'input' => [
                    $switch('CLEANURLS_AUTO_301', $t('Redirect old URLs with IDs'), $t('Old addresses like /12-blue-shirt.html send visitors and search engines to the new URL with a 301.')),
                    $switch('CLEANURLS_TRACK_SLUGS', $t('Remember renamed URLs'), $t('When you change a friendly URL, the old one keeps working with a 301.')),
                    $switch('CLEANURLS_LOG_404', $t('Log 404 errors'), $t('Lists missing pages so you can redirect them in one click.')),
                    [
                        'type' => 'select', 'label' => $t('When a page is not found'), 'name' => 'CLEANURLS_404_TARGET',
                        'desc' => $t('Showing the 404 page is best for SEO. Sending everything to the home page can hide real problems.'),
                        'options' => ['id' => 'id', 'name' => 'name', 'query' => [
                            ['id' => 'none', 'name' => $t('Show the 404 page (recommended)')],
                            ['id' => 'home', 'name' => $t('Send visitors to the home page')],
                        ]],
                    ],
                ],
                'submit' => ['title' => $t('Save')],
            ]],
            ['form' => [
                'legend' => ['title' => $t('XML sitemap'), 'icon' => 'icon-sitemap'],
                'input' => [
                    $switch('CLEANURLS_SITEMAP', $t('Enable sitemap.xml'), $t('A live sitemap with all languages, always up to date. No cron job needed.')),
                    $switch('CLEANURLS_SITEMAP_IMAGES', $t('Include product images')),
                ],
                'submit' => ['title' => $t('Save')],
            ]],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name . '&cu_tab=settings';
        $helper->submit_action = 'submitCleanurlsSettings';
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        foreach (array_keys(Routes::TYPES) as $type) {
            $helper->fields_value[Routes::configKey($type)] = Tools::getValue(Routes::configKey($type), Configuration::get(Routes::configKey($type)));
            $helper->fields_value['CLEANURLS_ON_' . strtoupper($type)] = (int) Configuration::get('CLEANURLS_ON_' . strtoupper($type));
        }
        foreach (array_keys(self::FLAGS) as $key) {
            $helper->fields_value[$key] = Configuration::get($key);
        }

        return $helper->generateForm($forms);
    }

    private function robotsPath(): string
    {
        return _PS_ROOT_DIR_ . '/robots.txt';
    }

    private function robotsHasSitemap(): bool
    {
        $file = $this->robotsPath();

        return is_file($file) && str_contains((string) file_get_contents($file), 'sitemap.xml');
    }

    private function addSitemapToRobots(): bool
    {
        $file = $this->robotsPath();
        if ($this->robotsHasSitemap()) {
            return true;
        }
        if ((is_file($file) && !is_writable($file)) || (!is_file($file) && !is_writable(dirname($file)))) {
            return false;
        }

        return (bool) file_put_contents($file, "\nSitemap: " . $this->context->link->getBaseLink() . "sitemap.xml\n", FILE_APPEND);
    }
}
