<?php
declare(strict_types=1);

require_once "../slackEvents.php";
require_once "../agenda.php";
require_once "MockEvent.php";

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

final class SlackEventsTest extends TestCase {
    private static Logger $logger;

    public static function setUpBeforeClass() : void {
        self::$logger = new Logger("SlackEventsTest", array(new StreamHandler('php://stdout', Logger::DEBUG)));
    }

    public function test_trimEventTitleTooLong() {
        // Setup
        $eventWithALongName = new MockEvent(array(), array(), str_repeat("x", 100));
        $agenda = $this->createMock(Agenda::class);
        $agenda->method('getEvents')->willReturn(array($eventWithALongName->getSabreObject()));

        $api = $this->createMock(ISlackAPI::class);

        // // Setup the assertion
        $api->expects($this->once())->method('view_open')->with($this->callback(function($data){
              return strlen($data["blocks"][0]["element"]["options"][0]["text"]["text"]) <= 76; // 76 is the max size allowed by the slack API
              }));

        $sut = new SlackEvents($agenda, $api,  self::$logger);

        // Act
        $sut->event_selection("someChanId", "someTriggerId");

        // No assertion here since we already set expectations on the mock
    }

}
