<?php

namespace okapi\views\apps\index;

use okapi\core\Db;
use okapi\core\Okapi;
use okapi\core\Response\OkapiHttpResponse;
use okapi\core\Response\OkapiRedirectResponse;
use okapi\lib\OCSession;
use okapi\Settings;

class View
{
    public static function call()
    {
        $langpref = isset($_GET['langpref']) ? $_GET['langpref'] : Settings::get('SITELANG');
        $langprefs = explode("|", $langpref);
        $login_langpref = (isset($_GET['langpref']) ? $langprefs[0] : null);

        # Determine which user is logged in to OC.

        $OC_user_id = OCSession::get_user_id();

        if ($OC_user_id == null)
        {
            $after_login = "okapi/apps/".(($langpref != Settings::get('SITELANG'))?"?langpref=".$langpref:"");
            $login_url = Okapi::oc_login_url($after_login, $login_langpref);
            return new OkapiRedirectResponse($login_url);
        }

        # Get the list of authorized apps.

        $rs = Db::query("
            select c.`key`, c.name, c.url
            from
                okapi_consumers c,
                okapi_authorizations a
            where
                a.user_id = '".Db::escape_string($OC_user_id)."'
                and c.`key` = a.consumer_key
            order by c.name
        ");
        $vars = array();
        $vars['okapi_base_url'] = Settings::get('SITE_URL')."okapi/";
        $vars['site_url'] = Settings::get('SITE_URL');
        $vars['site_name'] = Okapi::get_normalized_site_name();
        $vars['site_logo'] = Settings::get('SITE_LOGO');
        $vars['apps'] = array();
        while ($row = Db::fetch_assoc($rs))
            $vars['apps'][] = $row;
        Db::free_result($rs);

        $response = new OkapiHttpResponse();
        $response->content_type = "text/html; charset=utf-8";
        ob_start();
        Okapi::gettext_domain_init($langprefs);
        include __DIR__ . '/index.tpl.php';
        $response->body = ob_get_clean();
        Okapi::gettext_domain_restore();
        return $response;
    }
}
