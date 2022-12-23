<?php
/*
Copyright (C) 2022 Zero Waste Paris

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

// challenge/response see: https://api.slack.com/events/url_verification
function challenge_response($json, $log) {
    if(property_exists($json, 'type') and
       $json->type == 'url_verification' and
       property_exists($json, 'token') and
       property_exists($json, 'challenge')) {
        $log->info('Url verification request');
        http_response_code(200);
        header("Content-type: text/plain");
        print($json->challenge);
        exit();
    }
}
