<?php

class SlackEvents {
    protected $agenda;
    protected $log;
    protected $api;
    
    function __construct($agenda, $api, $log) {
        $this->agenda = $agenda;
        $this->log = $log;
        $this->api = $api;
    }

    protected function render_event($parsed_event, $description=false, $with_attendees=true) {

        $infos  = '*' . (string)$parsed_event["vCalendar"]->VEVENT->SUMMARY . '* ' . format_emoji($parsed_event) . PHP_EOL;
        $infos .= '*Quand:* ' . format_date($parsed_event["vCalendar"]->VEVENT->DTSTART->getDateTime(), $parsed_event["vCalendar"]->VEVENT->DTEND->getDateTime()) . PHP_EOL;
        if(isset($parsed_event["vCalendar"]->VEVENT->LOCATION) and strlen((string)$parsed_event["vCalendar"]->VEVENT->LOCATION) > 0) {
            $infos .= '*Ou:* ' . (string)$parsed_event["vCalendar"]->VEVENT->LOCATION . " (<https://www.openstreetmap.org/search?query=".(string)$parsed_event["vCalendar"]->VEVENT->LOCATION."|voir>)" . PHP_EOL;
        }
        if($with_attendees) {
            $infos .= "*Liste des participants " . format_number_of_attendees($parsed_event["attendees"], $parsed_event["number_volunteers_required"])."*: " . format_userids($parsed_event["attendees"], $parsed_event["unknown_attendees"]);
        }
        
        if($description) {
            $infos .= PHP_EOL . PHP_EOL . '*Description:*' . PHP_EOL . PHP_EOL . (string)$parsed_event["vCalendar"]->VEVENT->DESCRIPTION;
        }

        $block = [
            'type' => 'section', 
            'text' => [ 
                'type' => 'mrkdwn', 
                'text' => $infos
            ]            
        ];

        return $block;
    }
    
    function app_home_page($userid, $filters_to_apply = array()) {
        $this->log->info('event: app_home_opened received');
        
        $events = $this->agenda->getUserEventsFiltered(new DateTimeImmutable('NOW'), $userid, $filters_to_apply);
        
        $blocks = [];
        $default_filters = [
            [
                "text" => [
                    "type" => "plain_text",
                    "text" => "Mes évènements"
                ],
                "value" => Agenda::MY_EVENTS_FILTER
            ],
            [
                "text" => [
                    "type" => "plain_text",
                    "text" => "Besoin de bénévoles"
                ],
                "value" => Agenda::NEED_VOLUNTEERS_FILTER
            ]
        ];
                
        $all_filters = array();
        foreach($events as $file=>$parsed_event) {
            $all_filters = array_merge($all_filters, $parsed_event["categories"]);
            
            $block = $this->render_event($parsed_event, false);
            if(json_encode($block) === false) {
                $this->log->warning("Event $file is not JSON serializable" . (string)$parsed_event["vCalendar"]->VEVENT->SUMMARY, $block);
                continue;
            }
            
            $blocks[] = $block;
            $blocks[] = [
                'type'=> 'actions',
                'block_id'=> $file,
                'elements'=> array(
                    $this->getRegistrationButton($parsed_event["is_registered"]),
                    array(
                        'type'=> 'button',
                        'action_id'=> 'more',
                        'text'=> array(
                            'type'=> 'plain_text',
                            'text'=> 'Plus d\'informations',
                            'emoji'=> true
                        ),
                        'value'=> 'more'
                    )
                )
            ];
            
            $blocks[] = [
                'type' => 'divider'            
            ];
        }
    
        $header_block = [
            "type"=> "header",
            "text"=> [
                "type"=> "plain_text",
                "text"=> "Évènements à venir"
            ]
        ];

        $all_filters = array_unique($all_filters);
        foreach($GLOBALS['CATEGORIES'] as $category) {
            $value = (isset($category["short_name"])) ? $category["short_name"] : $category["name"];
            array_push($default_filters, [
                "text" => [
                    "type" => "plain_text",
                    "text" => "$category[name] $category[emoji]"
                ],
                "value" => $value
            ]);

            if (($key = array_search($value, $all_filters)) !== false) {
                unset($all_filters[$key]);
            }
        }
        
        foreach($all_filters as $filter) {
            $block = [
                "text" => [
                    "type" => "plain_text",
                    "text" => $filter
                ],
                "value" => $filter
            ];
            
            if(json_encode($block) === false) {
                $this->log->warning("Filter ($filter) is not JSON serializable");
                continue;
            }
            array_push($default_filters, $block);
        }
        
        $filter_block = [
            "type"=> "section",
            "block_id"=> "filter_section",
            "text"=> [
                "type"=> "mrkdwn",
                "text"=> "Choisissez vos filtres"
            ],
            
            "accessory"=> [
                "action_id"=> "filters_has_changed",
                "type"=> "multi_static_select",
                "placeholder"=> [
                    "type"=> "plain_text",
                    "text"=> "Filtres"
                ],
                "options"=> $default_filters
            ]
        ];

        if(isset($GLOBALS['PREPEND_BLOCK'])) {
            if(json_encode($GLOBALS['PREPEND_BLOCK']) !== false) {
                array_unshift($blocks, $GLOBALS['PREPEND_BLOCK'], $header_block, $filter_block, ["type"=> "divider"]);
            } else {
                $this->log->warning("PREPEND_BLOCK is not JSON serializable");
                array_unshift($blocks, $header_block, $filter_block, ["type"=> "divider"]);
            }
        } else {
            array_unshift($blocks, $header_block, $filter_block, ["type"=> "divider"]);
        }
        
        if(isset($GLOBALS['APPEND_BLOCK']) && json_encode($GLOBALS['APPEND_BLOCK']) !== false) {
            array_push($blocks, $GLOBALS['APPEND_BLOCK']);
        } else {
            $this->log->warning("APPEND_BLOCK is not JSON serializable");
        }
        
        $data = [
            'user_id' => $userid,
            'view' => [
                'type' => 'home',
                'blocks' => $blocks
            ]
        ];
        
        $this->api->views_publish($data);
    }

    protected function getRegistrationButton($in) {
        return array(
            'type'=> 'button',
            'action_id'=> (!$in) ? 'getin' : 'getout',
            'text'=> array(
                'type'=> 'plain_text',
                'text'=> (!$in) ? 'Je  viens !' : 'Me déinscrire',
                'emoji'=> true
            ),
            'style'=> 'primary',
            'value'=> 'approve'
        );
    }
    
    function more($vCalendarFilename, $request) {
        $userid = $request->user->id;
        $parsed_event = $this->agenda->getParsedEvent($vCalendarFilename, $userid);
        $trigger_id = $request->trigger_id;
        
        $block = $this->render_event($parsed_event, true);
        
        $data = [
            "type" =>  "modal",
            "title" =>  [
                "type" =>  "plain_text",
                "text" =>  "Informations"
            ],
            "close" =>  [
                "type" =>  "plain_text",
                "text" =>  "Fermer"
            ],
            
            "blocks" =>  [$block],
        ];
        $this->api->view_open($data, $trigger_id);
    }

    function more_inchannel($vCalendarFilename, $request, $update = false, $register = null) {
        $userid = $request->user->id;
        $parsed_event = $this->agenda->getParsedEvent($vCalendarFilename, $userid);
        $trigger_id = $request->trigger_id;

        if(is_null($register)) {
            $register = $parsed_event['is_registered'];
        } else {
            $user = $this->api->users_info($userid);
            if(is_null($user)) {
                $this->log->error("Can't determine user mail from the Slack API");
                exit(); // @TODO maybe throw something here
            }
            $profile = $user->profile;
            $this->log->debug("register from channel mail $profile->email $profile->first_name $profile->last_name");
            if($register) {
                $parsed_event["attendees"][] = $userid;
            } else {
                $parsed_event["attendees"] = array_filter($parsed_event["attendees"],
                                                          function($attendee) use ($userid) {
                                                              return $attendee !== $userid;
                                                          }
                );
            }
        }
        
        $block = $this->render_event($parsed_event, true);
        
        $data = [
            "type" =>  "modal",
            "title" =>  [
                "type" =>  "plain_text",
                "text" =>  "Informations"
            ],
            "close" =>  [
                "type" =>  "plain_text",
                "text" =>  "Fermer"
            ],
            "submit" =>  [
                "type" =>  "plain_text",
                "text"=> (!$register) ? 'Je  viens !' : 'Me déinscrire',
            ],
            "blocks" => [$block],
            "private_metadata" => $vCalendarFilename,
            "callback_id" => (!$register) ? "getin-fromchannel" : "getout-fromchannel"
        ];
        
        if(!$update) {
            $this->api->view_open($data, $trigger_id);
        } else {
            //@see: https://api.slack.com/surfaces/modals/using#updating_response
            $response = [
                "response_action" => "update",
                "view"=> $data
            ];
            header("Content-type:application/json");
            echo json_encode($response);
            fastcgi_finish_request();
            $response = $this->agenda->updateAttendee($vCalendarFilename,
                                                      $profile->email,
                                                      $register,
                                                      $profile->first_name . ' ' . $profile->last_name);
        }
    }
    
    // update just the modified event
    protected function register_fast_rendering($vCalendarFilename, $userid, $usermail, $register, $request, $event) {
        $i = 0;
        foreach($request->view->blocks as $block) { //looking for the block of interest
            if($block->block_id === $vCalendarFilename) {
                break;
            }
            $i++;
        }
        
        if($register) {
            $event["attendees"][] = $userid;
        } else {
            $event["attendees"] = array_filter($event["attendees"],
                                               function($attendee) use ($userid) {
                                                   return $attendee !== $userid;
                                               }
            );
        }
        
        $request->view->blocks[$i-1] = $this->render_event($event);
        $request->view->blocks[$i]->elements[0] = $this->getRegistrationButton($register);
        
        $data = [
            'user_id' => $userid,
            'view' => [
                'type' => 'home',
                'blocks' => $request->view->blocks
            ]
        ];
        $this->api->views_publish($data);
    }

    function register($vCalendarFilename, $userid, $register, $request) {
        $user = $this->api->users_info($userid);
        if(is_null($user)) {
            $this->log->error("Can't determine user mail from the Slack API");
            exit(); // @TODO maybe throw something here
        }
        $profile = $user->profile;
        $this->log->debug("register mail $profile->email $profile->first_name $profile->last_name");
        $parsed_event = $this->agenda->getParsedEvent($vCalendarFilename, $userid);
        slackEvents::ack();
        $this->register_fast_rendering($vCalendarFilename, $userid, $profile->email, $register, $request, $parsed_event);
        
        $response = $this->agenda->updateAttendee($vCalendarFilename, $profile->email, $register, $profile->first_name . ' ' . $profile->last_name);

        if(is_null($response)) { //nothing to do
            return;
        }
        
        if($response === false) {
            trigger_error("Event update failed", E_USER_ERROR); // it will: call error_handler, inform user and exit.
        }

        $vCalendar = $parsed_event['vCalendar']->VEVENT;
        $datetime = $vCalendar->DTSTART->getDateTime();
        $datetime = $datetime->modify("-1 day");
        
        if($register) {
            $summary = (string)$vCalendar->SUMMARY;
            $this->agenda->AddReminder($userid, $vCalendarFilename, $summary, $datetime);
        } else {
            $this->agenda->DeleteReminder($userid, $vCalendarFilename);
        }
    }
    
    function filters_has_changed($action, $userid) {
        $filters_to_apply = array();
        foreach($action->selected_options as $filter) {
            $filters_to_apply[] = $filter->value;
        }
        $this->app_home_page($userid, $filters_to_apply);
    }
    
    function in_channel_event_show($channel, $userid, $vCalendarFilename) {
        $parsed_event = $this->agenda->getParsedEvent($vCalendarFilename, $userid);
        $render = $this->render_event($parsed_event, false, false);
        $this->api->chat_postMessage($channel, array(
            $render,
            [
                'type'=> 'actions',
                'block_id'=> $vCalendarFilename,
                'elements'=> array(
                    array(
                        'type'=> 'button',
                        'action_id'=> 'more-inchannel',
                        'text'=> array(
                            'type'=> 'plain_text',
                            'text'=> 'Inscription/Plus d\'informations',
                            'emoji'=> true
                        ),
                        'value'=> 'more'
                    )
                )
            ]
        )
        );
    }

    public function event_selection($channel_id, $trigger_id) {
        $options = [];
        foreach($this->agenda->getEvents(new DateTimeImmutable('NOW')) as $vCalendarFilename => $vCalendar) {
            $options[] = [
                "text"=> [
                    "type"  => "plain_text",
                    "text"  => $vCalendar->VEVENT->DTSTART->getDateTime()->format('Y-m-d H:i:s') . " " .(string)$vCalendar->VEVENT->SUMMARY,
                    "emoji" => true
                ],
                "value" => $vCalendarFilename
            ];
        }
        
        $data = [
            "callback_id" => "show-fromchannel",
            "private_metadata" => $channel_id,
            "type"=> "modal",
            "title"=> [
                "type"=> "plain_text",
                "text"=> "ZWP Agenda",
                "emoji"=> true
            ],
            "submit"=> [
                "type"=> "plain_text",
                "text"=> "Submit",
                "emoji"=> true
            ],
            "close"=> [
                "type"=> "plain_text",
                "text"=> "Cancel",
                "emoji"=> true
            ],
            "blocks"=> [
                [
                    "type"=> "input",
                    "block_id"=> "vCalendarFilename",
                    "element"=> [
                        "type"=> "static_select",
                        "placeholder"=> [
                            "type"=> "plain_text",
                            "text"=> "Select an item",
                            "emoji"=> true
                        ],
                        "options"=> $options,
                        "action_id"=> "vCalendarFilename"
                    ],
                    "label"=> [
                        "type"=> "plain_text",
                        "text"=> "Choix de l'évènement",
                        "emoji"=> true
                    ]
                ]
            ]
        ];
        $this->api->view_open($data, $trigger_id);
    }
    
    // @SEE https://api.slack.com/interactivity/handling#acknowledgment_response
    static function ack() {
        http_response_code(200);
        fastcgi_finish_request(); //Ok for php-fpm
        //need to find a solution for mod_php (ob_flush(), flush(), etc. does not work)
    }
}
