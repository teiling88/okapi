<?php

namespace okapi\views\devel\dbstruct;

use Exception;
use okapi\Core\Consumer\OkapiInternalConsumer;
use okapi\Exception\BadRequest;
use okapi\lib\DbStructUpdater;
use okapi\OkapiServiceRunner;
use okapi\Request\OkapiInternalRequest;
use okapi\Response\OkapiHttpResponse;
use okapi\Settings;

class View
{
    public static function call()
    {
        # This is a hidden page for OKAPI developers. It will output a complete
        # structure of the database. This is useful for making OKAPI compatible
        # across different OC installations.

        $user = Settings::get('DB_USERNAME');
        $password = Settings::get('DB_PASSWORD');
        $dbname = Settings::get('DB_NAME');
        $dbserver = Settings::get('DB_SERVER');

        # Some security measures are taken to hinder us from accidentally dumping
        # database contents:
        #  - try to set memory limit so that no big data chunk can be stored
        #  - reassure that we use the --no-data option
        #  - plausibility test for data amount
        #  - verify that the output does not contain table contents

        ini_set('memory_limit', '16M');
        $shell_arguments = "mysqldump --no-data -h$dbserver -u$user -p$password $dbname";
        if (!strpos($shell_arguments,"--no-data"))
            throw new Exception("wrong database dump arguments");
        $struct = shell_exec($shell_arguments);
        if (strlen($struct) > 1000000)
            throw new Exception("something went terribly wrong while dumping table structures");
        if (stripos($struct,"dumping data") !== FALSE)
            throw new Exception("something went terribly wrong while dumping table structures");

        # Remove the "AUTO_INCREMENT=..." values. They break the diffs.

        $struct = preg_replace("/ AUTO_INCREMENT=([0-9]+)/i", "", $struct);

        # Exclude local tables that are not part of the OC installation

        if (Settings::get('OC_BRANCH') == 'oc.de') {
            $struct = preg_replace("/structure for table `_.*?\n-- Table /s", "", $struct);
        }

        # This method can be invoked with "compare_to" parameter, which points to
        # an alternate database structure (generated by the same script in other
        # *public* OKAPI instance). When invoked this way, we will attempt to
        # generate SQL script which alters LOCAL database is such a way that it
        # will become the other (public) database.

        $response = new OkapiHttpResponse();
        $response->content_type = "text/plain; charset=utf-8";
        if (isset($_GET['compare_to']))
        {
            self::requireSafe($_GET['compare_to']);
            $scheme = parse_url($_GET['compare_to'], PHP_URL_SCHEME);
            if (in_array($scheme, array('http', 'https')))
            {
                try {
                    $alternate_struct = @file_get_contents($_GET['compare_to']);
                } catch (Exception $e) {
                    throw new BadRequest("Failed to load ".$_GET['compare_to']);
                }
                $response->body =
                    "-- Automatically generated database diff. Use with caution!\n".
                    "-- Differences obtained with help of cool library by Kirill Gerasimenko.\n\n".
                    "-- Note: The following script has some limitations. It will render database\n".
                    "-- structure compatible, but not necessarilly EXACTLY the same. It might be\n".
                    "-- better to use manual diff instead.\n\n";
                $updater = new DbStructUpdater();
                if (isset($_GET['reverse']) && ($_GET['reverse'] == 'true'))
                {
                    $response->body .=
                        "-- REVERSE MODE. The following will alter [2], so that it has the structure of [1].\n".
                        "-- 1. ".Settings::get('SITE_URL')."okapi/devel/dbstruct (".md5($struct).")\n".
                        "-- 2. ".$_GET['compare_to']." (".md5($alternate_struct).")\n\n";
                    $alters = $updater->getUpdates($alternate_struct, $struct);
                }
                else
                {
                    $response->body .=
                        "-- The following will alter [1], so that it has the structure of [2].\n".
                        "-- 1. ".Settings::get('SITE_URL')."okapi/devel/dbstruct (".md5($struct).")\n".
                        "-- 2. ".$_GET['compare_to']." (".md5($alternate_struct).")\n\n";
                    $alters = $updater->getUpdates($struct, $alternate_struct);
                }
                # Add semicolons
                foreach ($alters as &$alter_ref)
                    $alter_ref .= ";";
                # Comment out all differences containing "okapi_". These should be executed
                # by OKAPI update scripts.
                foreach ($alters as &$alter_ref)
                {
                    if (strpos($alter_ref, "okapi_") !== false)
                    {
                        $lines = explode("\n", $alter_ref);
                        $alter_ref = "-- Probably you should NOT execute this one. Use okapi/update instead.\n-- {{{\n--   ".
                            implode("\n--   ", $lines)."\n-- }}}";
                    }
                }
                if (count($alters) > 0)
                    $response->body .= implode("\n", $alters)."\n";
                else
                    $response->body .= "-- No differences found\n";
            }
            else
            {
                $response->body = "HTTP(S) only!";
            }
        }
        else
        {
            $response->body = $struct;
        }
        return $response;
    }

    /**
     * Check if the URL can be safely retrieved. See issue #252.
     */
    private static function requireSafe($url)
    {
        $installations = OkapiServiceRunner::call(
            "services/apisrv/installations",
            new OkapiInternalRequest(
                new OkapiInternalConsumer(), null, array()
            )
        );
        $allowed = array();
        foreach ($installations as $i) {
            $allowed_url = $i['okapi_base_url']."devel/dbstruct";
            $allowed[] = $allowed_url;
            if ($url == $allowed_url) {
                return;
            }
            $allowed_url = str_replace("http://", "https://", $allowed_url);
            $allowed[] = $allowed_url;
            if ($url == $allowed_url) {
                return;
            }
        }
        throw new BadRequest(
            "The following URL is not on our whitelist: \"".$url."\".\n\n".
            "Please use one of the following:\n".
            "\"".implode("\",\n\"", $allowed)."\"."
        );
    }
}
