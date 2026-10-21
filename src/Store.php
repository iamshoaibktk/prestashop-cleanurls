<?php
/**
 * Clean URLs - Remove IDs, 301 Redirects & SEO URLs
 *
 * @author    iamshoaibktk@gmail.com (WhatsApp: +923459016286)
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Iamshoaibktk\Cleanurls;

/**
 * Database access for redirects, the 404 log and old slugs.
 */
class Store
{
    public const CODES = [301, 302, 303, 307, 308];
    public const MATCH = ['exact', 'prefix', 'regex'];

    public static function install(): bool
    {
        $p = _DB_PREFIX_;
        $e = _MYSQL_ENGINE_;
        $db = \Db::getInstance();

        return $db->execute("CREATE TABLE IF NOT EXISTS `{$p}cleanurls_redirect` (
                `id_redirect` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NOT NULL,
                `source` VARCHAR(500) NOT NULL,
                `target` VARCHAR(1000) NOT NULL,
                `code` SMALLINT UNSIGNED NOT NULL DEFAULT 301,
                `match_type` VARCHAR(10) NOT NULL DEFAULT 'exact',
                `hits` INT UNSIGNED NOT NULL DEFAULT 0,
                `last_hit` DATETIME NULL,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_redirect`),
                KEY `shop_source` (`id_shop`, `source`(191))
            ) ENGINE=$e DEFAULT CHARSET=utf8mb4")
            && $db->execute("CREATE TABLE IF NOT EXISTS `{$p}cleanurls_404` (
                `id_404` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NOT NULL,
                `path` VARCHAR(500) NOT NULL,
                `referer` VARCHAR(500) NOT NULL DEFAULT '',
                `hits` INT UNSIGNED NOT NULL DEFAULT 1,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_404`),
                UNIQUE KEY `shop_path` (`id_shop`, `path`(191))
            ) ENGINE=$e DEFAULT CHARSET=utf8mb4")
            && $db->execute("CREATE TABLE IF NOT EXISTS `{$p}cleanurls_history` (
                `id_history` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `type` VARCHAR(20) NOT NULL,
                `id_object` INT UNSIGNED NOT NULL,
                `id_lang` INT UNSIGNED NOT NULL,
                `id_shop` INT UNSIGNED NOT NULL,
                `slug` VARCHAR(255) NOT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_history`),
                UNIQUE KEY `old_slug` (`type`, `id_lang`, `id_shop`, `slug`(150), `id_object`)
            ) ENGINE=$e DEFAULT CHARSET=utf8mb4");
    }

    public static function uninstall(): bool
    {
        return \Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'cleanurls_redirect`, `' . _DB_PREFIX_ . 'cleanurls_404`, `' . _DB_PREFIX_ . 'cleanurls_history`');
    }

    /* ---------------- redirects ---------------- */

    /**
     * @return array{target: string, code: int}|null
     */
    public static function matchRedirect(int $idShop, string $path): ?array
    {
        $rows = \Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'cleanurls_redirect`
            WHERE id_shop = ' . $idShop . ' AND active = 1
            ORDER BY FIELD(match_type, "exact", "prefix", "regex"), LENGTH(source) DESC') ?: [];
        foreach ($rows as $r) {
            $source = ltrim($r['source'], '/');
            $target = $r['target'];
            $hit = match ($r['match_type']) {
                'exact' => rtrim($path, '/') === rtrim($source, '/'),
                'prefix' => $source !== '' && str_starts_with($path, $source),
                'regex' => self::regexValid($r['source']) && preg_match(self::regex($r['source']), $path) === 1,
                default => false,
            };
            if (!$hit) {
                continue;
            }
            if ($r['match_type'] === 'regex') {
                $target = (string) preg_replace(self::regex($r['source']), $target, $path);
            }
            \Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'cleanurls_redirect` SET hits = hits + 1, last_hit = NOW() WHERE id_redirect = ' . (int) $r['id_redirect']);

            return ['target' => $target, 'code' => (int) $r['code']];
        }

        return null;
    }

    public static function regex(string $source): string
    {
        return '#' . str_replace('#', '\#', ltrim($source, '/')) . '#u';
    }

    public static function regexValid(string $source): bool
    {
        return @preg_match(self::regex($source), '') !== false;
    }

    public static function addRedirect(int $idShop, string $source, string $target, int $code, string $match): bool
    {
        return \Db::getInstance()->insert('cleanurls_redirect', [
            'id_shop' => $idShop,
            'source' => pSQL(ltrim(trim($source), '/')),
            'target' => pSQL(trim($target)),
            'code' => in_array($code, self::CODES, true) ? $code : 301,
            'match_type' => in_array($match, self::MATCH, true) ? $match : 'exact',
            'date_add' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function redirects(int $idShop): array
    {
        return \Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'cleanurls_redirect` WHERE id_shop = ' . $idShop . ' ORDER BY id_redirect DESC') ?: [];
    }

    public static function deleteRedirect(int $idShop, int $id): bool
    {
        return \Db::getInstance()->delete('cleanurls_redirect', 'id_redirect = ' . $id . ' AND id_shop = ' . $idShop);
    }

    /* ---------------- 404 log ---------------- */

    public static function log404(int $idShop, string $path, string $referer): void
    {
        $now = date('Y-m-d H:i:s');
        \Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'cleanurls_404` (id_shop, path, referer, hits, date_add, date_upd)
            VALUES (' . $idShop . ', "' . pSQL(substr($path, 0, 500)) . '", "' . pSQL(substr($referer, 0, 500)) . '", 1, "' . $now . '", "' . $now . '")
            ON DUPLICATE KEY UPDATE hits = hits + 1, date_upd = VALUES(date_upd), referer = IF(VALUES(referer) = "", referer, VALUES(referer))');
        // keep the table small
        if (random_int(1, 100) === 1) {
            \Db::getInstance()->delete('cleanurls_404', 'date_upd < "' . pSQL(date('Y-m-d H:i:s', strtotime('-90 days'))) . '"');
        }
    }

    public static function notFound(int $idShop, int $limit = 200): array
    {
        return \Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'cleanurls_404` WHERE id_shop = ' . $idShop . ' ORDER BY hits DESC, date_upd DESC LIMIT ' . $limit) ?: [];
    }

    /** Once a missing URL gets a redirect, it no longer belongs in the 404 list. */
    public static function clear404WithPath(int $idShop, string $path): bool
    {
        return \Db::getInstance()->delete('cleanurls_404', 'id_shop = ' . $idShop . ' AND path = "' . pSQL($path) . '"');
    }

    public static function clear404(int $idShop, ?int $id = null): bool
    {
        return \Db::getInstance()->delete('cleanurls_404', 'id_shop = ' . $idShop . ($id ? ' AND id_404 = ' . $id : ''));
    }

    /* ---------------- old slugs ---------------- */

    public static function rememberSlug(string $type, int $id, int $idLang, int $idShop, string $slug): void
    {
        if ($slug === '') {
            return;
        }
        \Db::getInstance()->execute('INSERT IGNORE INTO `' . _DB_PREFIX_ . 'cleanurls_history` (type, id_object, id_lang, id_shop, slug, date_add)
            VALUES ("' . pSQL($type) . '", ' . $id . ', ' . $idLang . ', ' . $idShop . ', "' . pSQL($slug) . '", NOW())');
    }

    public static function findOldSlug(string $type, string $slug, int $idLang, int $idShop): ?int
    {
        $id = \Db::getInstance()->getValue('SELECT id_object FROM `' . _DB_PREFIX_ . 'cleanurls_history`
            WHERE type = "' . pSQL($type) . '" AND slug = "' . pSQL($slug) . '" AND id_lang = ' . $idLang . ' AND id_shop = ' . $idShop . '
            ORDER BY id_history DESC');

        return $id ? (int) $id : null;
    }

    public static function countHistory(int $idShop): int
    {
        return (int) \Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'cleanurls_history` WHERE id_shop = ' . $idShop);
    }
}
