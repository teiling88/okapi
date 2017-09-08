<?php

namespace okapi\Core\Consumer;

/**
 * Used when debugging methods using DEBUG_AS_USERNAME flag.
 */
class OkapiDebugConsumer extends OkapiConsumer
{
    public function __construct()
    {
        $admins = \get_admin_emails();
        parent::__construct('debug', null, "DEBUG_AS_USERNAME Debugger", null, $admins[0]);
    }
}
