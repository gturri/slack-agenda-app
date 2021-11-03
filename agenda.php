<?php

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Sabre\VObject;

class NotImplementedException extends BadMethodCallException {}

require __DIR__ . '/vendor/autoload.php';

class Agenda {
    protected $url;
    protected $username;
    protected $password;
    //protected $timezone = new DateTime('NOW');
    
    public function __construct($url, $username, $password) {
        $this->log = new Logger('Agenda');
        //$this->log->pushHandler(new StreamHandler('access.log', Logger::DEBUG));
        $this->log->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

        $this->url = $url;
        $this->username = $username;
        $this->password = $password;
        $this->update();
    }

    // init cURL request
    protected function init_curl_request() {
        $ch = curl_init();   
        curl_setopt($ch, CURLOPT_URL, $this->url);
        curl_setopt($ch, CURLOPT_USERPWD, $this->username . ":" . $this->password);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        return $ch;
    }

    function getEvents() {
        $events = [];
        $it = new RecursiveDirectoryIterator("./data/");
        foreach(new RecursiveIteratorIterator($it) as $file) {
            if($this->isNonEventFile($file)) {
                continue;
            }
            
            $vcal = \Sabre\VObject\Reader::read(file_get_contents_safe($file));
            $startDate = $vcal->VEVENT->DTSTART->getDateTime();
                
            if($startDate < new DateTime('NOW')) {
                $this->log->debug("Event is in the past, skiping");
                continue;
            }
            $events[] = $vcal;
        }
        return $events;
    }
    
    // update agenda
    protected function update() {
        $remote_ctag = $this->getctag();
        
        // check if we need to update events from the server
        if(is_file('./data/ctag')) {
            $local_ctag = file_get_contents_safe('./data/ctag');
            $this->log->debug("ctags", ["remote" => $remote_ctag, "local" => $local_ctag]);
            // remote and local ctag are equal, there is no need to update the agenda
            if($remote_ctag == $local_ctag) {
                return;
            }
        }
        
        $this->log->debug("Agenda update needed");
        
        $etags = $this->getetags();
        $this->updateInternalState($etags);

        file_put_contents_safe("./data/ctag", $remote_ctag);
    }

    // 
    protected function updateInternalState($etags) {
        $url_to_update = [];
        foreach($etags as $url => $remote_etag) {
            $tmp = explode("/", $url);
            if(is_file("./data/".end($tmp)) and is_file("./data/".end($tmp) . ".etag")) {
                $local_etag = file_get_contents_safe("./data/" . end($tmp) . ".etag");
                $this->log->debug(end($tmp), ["remote_etag"=>$remote_etag, "local_etag" => $local_etag]);
                
                if($local_etag != $remote_etag) {
                    // local and remote etag differs, need update
                    $url_to_update[$url] = $etag;
                }
            } else {
                $url_to_update[] = $url;
            }
        }
        
        if(count($url_to_update) > 0) {
            $this->updateEvents($url_to_update);
        }
        
        $this->removeDeletedEvents($etags);
    }
    
    // delete local events that have been deleted on the server
    protected function removeDeletedEvents($etags) {
        $urls = [];
        foreach($etags as $url => $etag) {
            $urls[] = basename($url);
        }
        
        $it = new RecursiveDirectoryIterator("./data/");
        foreach(new RecursiveIteratorIterator($it) as $file) {
            if($this->isNonEventFile($file)) {
                continue;
            }
            
            if(in_array(basename($file), $urls)) {
                $this->log->debug("No need to remove ". basename($file));
            } else {
                $this->log->info("Need to remove ". basename($file));
                
                if(!unlink($file)) {
                    $this->log->error("Failed to delete:" . $file . ".etag");
                }
                
                if(!unlink($file . ".etag")) {
                    $this->log->error("Failed to delete:" . $file . ".etag");
                }
            }
        }
    }

    private function isNonEventFile($filename){
      return strpos($file, '.etag') > 0 ||
        strcmp($file, "./data/ctag") == 0 ||
        strcmp($file, "./data/.") == 0 ||
        strcmp($file, "./data/..") == 0 ||
        strcmp($file, "..") == 0 ;
    }
    
    // url that need to be updated
    protected function updateEvents($urls) {
        $ch = $this->init_curl_request();
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "REPORT");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Depth:1",
            "Prefer: return-minimal",
            "Content-Type: application/xml; charset=utf-8")
        );
        
        $str = "";
        foreach($urls as $url) {
            $str .= "<d:href>".$url."</d:href>\n";
        }
        
        curl_setopt($ch, CURLOPT_POSTFIELDS,'        
<c:calendar-multiget xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
    <d:prop>
        <d:getetag />
        <c:calendar-data />
    </d:prop>'.$str.'
</c:calendar-multiget>');

        $output = curl_exec($ch);

        $service = new Sabre\Xml\Service();
        
        $service->elementMap = [
            '{DAV:}response' => function(Sabre\Xml\Reader $reader) {
                return Sabre\Xml\Deserializer\keyValue($reader, 'DAV:');
            },
            '{DAV:}propstat' => function(Sabre\Xml\Reader $reader) {
                return Sabre\Xml\Deserializer\keyValue($reader, 'DAV:');
            },
            '{DAV:}prop' => function(Sabre\Xml\Reader $reader) {
                return Sabre\Xml\Deserializer\keyValue($reader, 'DAV:');
            },
        ];
        
        $xml = $service->parse($output);

        foreach($xml as $event) {
            if(isset($event['value']['propstat']['prop']['{urn:ietf:params:xml:ns:caldav}calendar-data'])) {
                $filename = basename($event['value']['href']);
                $this->log->info("Adding event " . $filename);
                
                if(is_file("./data/" . $filename)) {
                    $this->log->debug("Deleting " . $filename . " as it has changed.");
                    unlink("./data/" . $filename);
                }
                
                if(is_file("./data/" . $filename . ".etag")) {
                    $this->log->debug("Deleting " . $filename . ".etag as it has changed.");
                    unlink("./data/" . $filename . ".etag");
                }
                
                // parse event to get its DTSTART
                $vcal = \Sabre\VObject\Reader::read($event['value']['propstat']['prop']['{urn:ietf:params:xml:ns:caldav}calendar-data']);
                $startDate = $vcal->VEVENT->DTSTART->getDateTime();
                
                if($startDate < new DateTime('NOW')) {
                    $this->log->debug("Event is in the past, skiping");
                    continue;
                }
                
                file_put_contents_safe("./data/" . $filename, $event['value']['propstat']['prop']['{urn:ietf:params:xml:ns:caldav}calendar-data']);
                file_put_contents_safe("./data/" . $filename . ".etag", trim($event['value']['propstat']['prop']['getetag'], '"'));
            }
        }
    }
    
    // get event etags from the server
    protected function getetags() {
        $ch = $this->init_curl_request();
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "REPORT");
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Depth:1",
            "Prefer: return-minimal",
            "Content-Type: application/xml; charset=utf-8",
            
        ));
        
        curl_setopt($ch, CURLOPT_POSTFIELDS,'
<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
    <d:prop>
        <d:getetag />
    </d:prop>
    <c:filter>
        <c:comp-filter name="VCALENDAR" />
    </c:filter>
</c:calendar-query>');

        $output = curl_exec($ch);

        $service = new Sabre\Xml\Service();
        
        $service->elementMap = [
            '{DAV:}response' => function(Sabre\Xml\Reader $reader) {
                return Sabre\Xml\Deserializer\keyValue($reader, 'DAV:');
            },
            '{DAV:}propstat' => function(Sabre\Xml\Reader $reader) {
                return Sabre\Xml\Deserializer\keyValue($reader, 'DAV:');
            },
            '{DAV:}prop' => function(Sabre\Xml\Reader $reader) {
                return Sabre\Xml\Deserializer\keyValue($reader, 'DAV:');
            },
        ];
        
        // Array as:
        // [url1] => etag1
        // [url2] => etag2
        // ...
        $data = [];
        foreach($service->parse($output) as $event) {
            $data[$event['value']['href']] = trim($event['value']['propstat']['prop']['getetag'], '"');
        }
        return $data;
    }
    
    // get the ctag of the calendar on the server
    // @see https://sabre.io/dav/building-a-caldav-client/
    protected function getctag() {
        $ch = $this->init_curl_request();
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PROPFIND");
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Depth:0",
            "Prefer: return-minimal",
            "Content-Type: application/xml; charset=utf-8",
        ));
        
        curl_setopt($ch, CURLOPT_POSTFIELDS,'
<d:propfind xmlns:d="DAV:"  xmlns:cs="http://calendarserver.org/ns/">
  <d:prop>
    <cs:getctag/>
  </d:prop>
</d:propfind>');
    
        $output = curl_exec($ch);

        curl_close($ch);

        $service = new Sabre\Xml\Service();
        
        $service->elementMap = [
            '{DAV:}response' => function(Sabre\Xml\Reader $reader) {
                return Sabre\Xml\Deserializer\keyValue($reader, 'DAV:');
            },
            '{DAV:}propstat' => function(Sabre\Xml\Reader $reader) {
                return Sabre\Xml\Deserializer\keyValue($reader, 'DAV:');
            },
            '{DAV:}prop' => function(Sabre\Xml\Reader $reader) {
                return Sabre\Xml\Deserializer\keyValue($reader, 'DAV:');
            },
        ];

        $parsed_data = $service->parse($output);
        
        if(isset($parsed_data[0]['value']['propstat']['prop']['{http://calendarserver.org/ns/}getctag'])) {
            return $parsed_data[0]['value']['propstat']['prop']['{http://calendarserver.org/ns/}getctag'];
        }
        
        return NULL;
    }
    
    protected function clearEvents() {
        throw new NotImplementedException();
    }
}
