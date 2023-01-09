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
include "inc/header.php";

SlackEvents::ack();


$slick->route("command", "/$GLOBALS[SLASH_COMMAND]", function (array $payload) {
    $api = new SlackAPI($slack_credentials['bot_token'], $slack_credentials['user_token']);
    $agenda = initAgendaFromType($caldav_credentials['url'], $caldav_credentials['username'], $caldav_credentials['password'],
                                 $api, $agenda_args, $log);
    $slack_events = new SlackEvents($agenda, $api, $log);
    $slack_events->event_selection($_POST["channel_id"], $_POST["trigger_id"]);
});
$slick->on404(function () {
    $log->warning("missing command, exiting.");
    exit();
});

$slick->processRequest();

