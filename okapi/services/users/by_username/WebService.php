<?php

namespace okapi\services\users\by_username;

use okapi\Core\Exception\InvalidParam;
use okapi\Core\Exception\ParamMissing;
use okapi\Core\Okapi;
use okapi\Core\Request\OkapiInternalRequest;
use okapi\Core\Request\OkapiRequest;
use okapi\OkapiServiceRunner;

class WebService
{
    public static function options()
    {
        return array(
            'min_auth_level' => 1
        );
    }

    public static function call(OkapiRequest $request)
    {
        $username = $request->get_parameter('username');
        if (!$username) throw new ParamMissing('username');

        # Fix for issue 339:
        # Catch pipe chars here, because services/users/by_usernames would split up the name.
        # OC databases do not contain user names with pipe chars.

        if (strstr($username,'|'))
            throw new InvalidParam('username', "There is no user by this username.");
        $fields = $request->get_parameter('fields');

        # There's no need to validate the fields parameter.

        $results = OkapiServiceRunner::call('services/users/by_usernames', new OkapiInternalRequest(
            $request->consumer, $request->token, array('usernames' => $username,
            'fields' => $fields)));
        $result = $results[$username];
        if ($result == null)
            throw new InvalidParam('username', "There is no user by this username.");
        return Okapi::formatted_response($request, $result);
    }
}
