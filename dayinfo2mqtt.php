<?php

require __DIR__ . '/vendor/autoload.php';
require_once dirname(__FILE__) . '/lib/class.Season.php';

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

//Enable asynchronous signal handling
pcntl_async_signals(true);

//turn off output buffering
while (ob_get_level()) ob_end_clean(); 


function logger($message){
    echo (new DateTime())->format(DATE_ATOM).' : '.$message.PHP_EOL;
}

function publish(MqttClient $mqtt, $topic, $payload){
    logger('Publish => topic: "'.$topic.'"; payload: "'.$payload.'"');
    $mqtt->publish($topic,$payload);
}

function publishBase(MqttClient $mqtt, $prefix) {
    publish($mqtt,$prefix.'/base/weekend', ((date('N') >= 6) ? 1 : 0));
    publish($mqtt,$prefix.'/base/weekday', date('w'));
    publish($mqtt,$prefix.'/base/yearday', date('z'));
}

function publishMoon(MqttClient $mqtt, $prefix) {
    $moon = new Solaris\MoonPhase();
    $age = round($moon->getAge(),1); // age de la lune en jour
    $phase = round($moon->getPhase(),2); //0 et 1 nouvelle lune, 0,5 pleine lune
    $illumination = round($moon->getIllumination(),2);
    $distance = round($moon->getDistance(),2);

    $name = $moon->getPhaseName();

    publish($mqtt,$prefix.'/moon/phase', $phase);
    publish($mqtt,$prefix.'/moon/age', $age);
    publish($mqtt,$prefix.'/moon/illumination', $illumination);
    publish($mqtt,$prefix.'/moon/distance', $distance);
    publish($mqtt,$prefix.'/moon/name', $name);
}

function loadCSV($csvPath, $delimiter = ',') {

    if (($csvHandle = fopen($csvPath, "r")) === FALSE) {
        logger('Error : the file "' . $csvPath . '" can\'t be opened !!!');
        exit();
    }

    // read first 3 characters to compare to the BOM string
    if (fgets($csvHandle, 4) !== "\xef\xbb\xbf") {
        // otherwise rewind pointer to start of file
        rewind($csvHandle);
    }

    $rows = [];
    while (($row = fgetcsv($csvHandle, 1000, $delimiter)) !== false) {
        $rows[] = $row;
    }
    fclose($csvHandle);
    // Remove the first one that contains headers
    $headers = array_shift($rows);
    // Combine the headers with each following row
    $array = [];
    foreach ($rows as $row) {
        $array[] = array_combine($headers, $row);
    }
    return $array;
}

function publishSchoolHolidays(MqttClient $mqtt, $prefix, $countryCode, $departmentNumber) {
    //https://www.data.gouv.fr/en/datasets/contours-geographiques-des-academies/
    //https://www.data.gouv.fr/en/datasets/le-calendrier-scolaire/


    if ($countryCode == 'fr') {

        // DOM TOM not supported
        if (strpos($departmentNumber, '97') == true) {
            logger('Error : Calendrier des DOM TOM non pris en charge');
            return;
        }

        // Load France files
        $academies = loadCSV(dirname(__FILE__) . '/resources/fr/academies.csv');
        $calendar = loadCSV(dirname(__FILE__) . '/resources/fr/fr.csv', ';');

        // ----- Find Zone -----

        // return the first item where dep starts with the departmentNumber
        $academy = current(array_filter($academies, function ($item) use ($departmentNumber) {
            return strpos($item['dep'], $departmentNumber) === 0;
        }));

        // get the right Zone (A-B-C) for the academy
        $zone = $academy['vacances'];

        // ----- Prepare the calendar -----

        // filter calendar on zone and population (keep only 'Élèves' and '-')
        $calendar = array_filter($calendar, function ($item) use ($zone) {
            return $item['Zones'] === $zone && ($item['Population'] === '-' || $item['Population'] === 'Élèves');
        });

        // for each item keep only date part (10char) of "Date de début" and "Date de fin"
        $calendar = array_map(function ($item) {
            // convert "Date de début" and "Date de fin" to DateTime object using UTC timezone
            $startDate = new DateTime($item['Date de début'], new DateTimeZone('UTC'));
            $endDate = new DateTime($item['Date de fin'], new DateTimeZone('UTC'));
            // if startDate hour is after 21h then add 1 day
            if ($startDate->format('H') >= 21) {
                $startDate->add(new DateInterval('P1D'));
            }
            // if endDate hour is after 21h then add 1 day
            if ($endDate->format('H') >= 21) {
                $endDate->add(new DateInterval('P1D'));
            }
    
            // save dates back to item
            $item['Date de début'] = $startDate->format('Y-m-d');
            $item['Date de fin'] = $endDate->format('Y-m-d');

            return $item;
        }, $calendar);

        // sort calendar by "Date de début"+"Date de fin"
        usort($calendar, function ($a, $b) {
            return (strtotime($a['Date de début']) + strtotime($a['Date de fin'])) - (strtotime($b['Date de début']) + strtotime($b['Date de fin']));
        });

        // ----- Define holiday infos -----

        // search for current holiday
        $datetoday = new DateTime("today");
        $currentHoliday = current(array_filter($calendar, function ($item) use ($datetoday) {
            return new DateTime($item['Date de début']) <= $datetoday && $datetoday < new DateTime($item['Date de fin']);
        }));

        // search for next holiday
        $nextHoliday = current(array_filter($calendar, function ($item) use ($datetoday) {
            return $datetoday < new DateTime($item['Date de début']);
        }));

        // if today is holiday
        if ($currentHoliday) {
            $holidaytoday = '1';
            $holidaytodaylabel = $currentHoliday['Description'];
            // if today is holiday then nextend is the number of days until the end of the current holiday
            $holidaynextend = $datetoday->diff(new DateTime($currentHoliday['Date de fin']))->days;
        } else {
            $holidaytoday = '0';
            $holidaytodaylabel = '-';
            $holidaynextend = $datetoday->diff(new DateTime($nextHoliday['Date de fin']))->days;
        }

        $holidaynextbegin = $datetoday->diff(new DateTime($nextHoliday['Date de début']))->days;
        $holidaynextlabel = $nextHoliday['Description'];

        publish($mqtt, $prefix . '/schoolholidays/today', $holidaytoday);
        publish($mqtt, $prefix . '/schoolholidays/todaylabel', $holidaytodaylabel);
        publish($mqtt, $prefix . '/schoolholidays/nextbegin', $holidaynextbegin);
        publish($mqtt, $prefix . '/schoolholidays/nextend', $holidaynextend);
        publish($mqtt, $prefix . '/schoolholidays/nextlabel', $holidaynextlabel);



    } else { //other countries

        $calendarName = $countryCode;

        $holiday = '0';
        $nholiday = '-';
        $nextlabel = '-';

        $icaddr = dirname(__FILE__) . '/resources/' . $countryCode . '/' . $calendarName . '.ics';
        $ical   = new ICal\ICal($icaddr);

        $events = $ical->events();
        $datetoday = date_create("today");
        $diffday = 365;
        $diffend = 365;
        $finete = date_create(mktime(0, 0, 0, 1,  1));
        $debutete = $finete;

        foreach ($events as $event) {
            if (isset($event->dtend)) {
                $datehol = date_create($event->dtstart);
                if ($datetoday < $datehol) {
                    //calcul du début prochaines vacances
                    $diff = date_diff($datetoday, $datehol);
                    if ($diff->format('%a') < $diffday && $diff->format('%a') > 0) {
                        $diffday = $diff->format('%a');
                        $nextlabel = $event->summary;
                    }
                }
                $datefin = date_create($event->dtend);
                if ($datetoday < $datefin) {
                    //calcul de la fin des prochaines vacances
                    $diff = date_diff($datetoday, $datefin);
                    if ($diff->format('%a') < $diffend && $diff->format('%a') > 0) {
                        $diffend = $diff->format('%a');
                    }
                }
                if ($datehol <= $datetoday && $datetoday < $datefin) {
                    $holiday = '1';
                    $nholiday = $event->summary;
                }
            } else {
                if (strpos($event->description, 'été') !== false) {
                    //post debut vacances d'été (label vacances, date supérieure et on est bien sur l'année en cours)
                    $datehol = date_create($event->dtstart);
                    if (date_format($datetoday, 'Y') === date_format($datehol, 'Y')) {
                        $debutete = $datehol;
                        //log::add('dayinfo', 'debug', 'Debut ' . $debutete);
                    }
                }
                if ($event->description == "Rentrée scolaire des élèves") {
                    //post reprise (label rentrée, date supérieure)
                    $datehol = date_create($event->dtstart);
                    //log::add('dayinfo', 'debug', 'Fin ' . date_format($datetoday,'Y') . ' ' . date_format($datehol,'Y'));
                    if (date_format($datetoday, 'Y') === date_format($datehol, 'Y')) {
                        $finete = $datehol;
                        //log::add('dayinfo', 'debug', 'Fin ' . $finete);
                    }
                }
            }
        }

        if ($datetoday < $debutete) {
            $diff = date_diff($datetoday, $debutete);
            if ($diff->format('%a') < $diffday && $diff->format('%a') > 0) {
                $diffday = $diff->format('%a');
                $nextlabel = "Vacances d'été";
            }
        }

        if ($datetoday < $finete) {
            $diff = date_diff($datetoday, $finete);
            if ($diff->format('%a') < $diffend && $diff->format('%a') > 0) {
                $diffend = $diff->format('%a');
            }
        }

        if ($debutete <= $datetoday && $datetoday < $finete) {
            $holiday = '1';
            $nholiday = "Vacances d'été";
        }
        publish($mqtt, $prefix . '/schoolholidays/today', $holiday);
        publish($mqtt, $prefix . '/schoolholidays/todaylabel', $nholiday);
        publish($mqtt, $prefix . '/schoolholidays/nextbegin', $diffday);
        publish($mqtt, $prefix . '/schoolholidays/nextend', $diffend);
        publish($mqtt, $prefix . '/schoolholidays/nextlabel', $nextlabel);
    }
}

function getPublicHolidays($countryCode, $departmentNumber, $year=null) {
    if ($year === null) $year = date("Y");

    $easterDate  = easter_date($year);
    $easterDay   = date("j", $easterDate);
    $easterMonth = date("n", $easterDate);
    $easterYear  = date("Y", $easterDate);

    $holidays = array();

    if ($countryCode == "fr") {
        // Dates fixes
        $holidays[mktime(0, 0, 0, 1,  1,  $year)] = 'Jour de l\'An';
        $holidays[mktime(0, 0, 0, 5,  1,  $year)] = 'Fête du Travail';
        $holidays[mktime(0, 0, 0, 5,  8,  $year)] = 'Victoire de 1945';
        $holidays[mktime(0, 0, 0, 7,  14, $year)] = 'Fête nationale';
        $holidays[mktime(0, 0, 0, 8,  15, $year)] = 'Assomption';
        $holidays[mktime(0, 0, 0, 11, 1,  $year)] = 'Toussaint';
        $holidays[mktime(0, 0, 0, 11, 11, $year)] = 'Armistice 1918';
        $holidays[mktime(0, 0, 0, 12, 25, $year)] = 'Noël';

        // Dates variables
        $holidays[mktime(0, 0, 0, $easterMonth, $easterDay + 1,  $easterYear)] = 'Lundi de Pâques';
        $holidays[mktime(0, 0, 0, $easterMonth, $easterDay + 39, $easterYear)] = 'Ascension';
        $holidays[mktime(0, 0, 0, $easterMonth, $easterDay + 50, $easterYear)] = 'Lundi de Pentecôte';

        switch ($departmentNumber) {
            case '57':
            case '67':
            case '68':
            case '90':
                $holidays[mktime(0, 0, 0, $easterMonth, $easterDay - 2,  $easterYear)] = 'Vendredi Saint';
                $holidays[mktime(0, 0, 0, 12, 26, $year)] = 'Saint-Étienne';
                break;

            case '971':
                $holidays[mktime(0, 0, 0, 5, 27, $year)] = 'Abolition de l\'Esclavage';
                $holidays[mktime(0, 0, 0, $easterMonth, $easterDay - 47,  $easterYear)] = 'Mardi Gras';
                $holidays[mktime(0, 0, 0, $easterMonth, $easterDay - 46,  $easterYear)] = 'Cendres';
                $holidays[mktime(0, 0, 0, $easterMonth, $easterDay - 2,  $easterYear)] = 'Vendredi Saint';
                $holidays[mktime(0, 0, 0, 11, 2, $year)] = 'Fête des morts';
                break;

            case '972':
                $holidays[mktime(0, 0, 0, $easterMonth, $easterDay - 2,  $easterYear)] = 'Vendredi Saint';
                $holidays[mktime(0, 0, 0, 5, 22, $year)] = 'Abolition de l\'Esclavage';
                break;

            case '973':
                $holidays[mktime(0, 0, 0, 6, 10, $year)] = 'Abolition de l\'Esclavage';
                break;

            case '974':
                $holidays[mktime(0, 0, 0, 12, 20, $year)] = 'Abolition de l\'Esclavage';
                break;

            default:
                // Nothing more for other department
                break;
        }
    }
    else if ($countryCode == "be")
    {
        // Dates fixes
        $holidays[mktime(0, 0, 0, 1,  1,  $year)] = 'Jour de l\'An';
        $holidays[mktime(0, 0, 0, 5,  1,  $year)] = 'Fête du Travail';
        $holidays[mktime(0, 0, 0, 7,  21, $year)] = 'Fête nationale';
        $holidays[mktime(0, 0, 0, 8,  15, $year)] = 'Assomption';
        $holidays[mktime(0, 0, 0, 11, 1,  $year)] = 'Toussaint';
        $holidays[mktime(0, 0, 0, 11, 11, $year)] = 'Armistice 1918';
        $holidays[mktime(0, 0, 0, 12, 25, $year)] = 'Noël';

        // Dates variables
        $holidays[mktime(0, 0, 0, $easterMonth, $easterDay + 1,  $easterYear)] = 'Lundi de Pâques';
        $holidays[mktime(0, 0, 0, $easterMonth, $easterDay + 39, $easterYear)] = 'Ascension';
        $holidays[mktime(0, 0, 0, $easterMonth, $easterDay + 50, $easterYear)] = 'Lundi de Pentecôte';
    }
    else if ($countryCode == "ca")
    {
        // Dates fixes
        $holidays[mktime(0, 0, 0, 1,  1,  $year)] = 'Jour de l\'An';
        $holidays[strtotime("previous monday", mktime(0, 0, 0, 5, 25,  $year))] = 'Fête de la Reine';
        $holidays[mktime(0, 0, 0, 7,  1,  $year)] = 'Fête du Canada';
        $holidays[strtotime("first monday of september", mktime(0, 0, 0, 1,  1,  $year))] = 'Fête du Travail';
        $holidays[strtotime("second monday of october", mktime(0, 0, 0, 1,  1,  $year))] = 'Action de grâce';
        $holidays[mktime(0, 0, 0, 12, 25, $year)] = 'Noël';

        // Dates variables
        $holidays[mktime(0, 0, 0, $easterMonth, $easterDay - 2,  $easterYear)] = 'Vendredi Saint';
        $holidays[mktime(0, 0, 0, $easterMonth, $easterDay + 1,  $easterYear)] = 'Lundi de Pâques';
    }
    ksort($holidays);
    return $holidays;
}


function publishPublicHolidays(MqttClient $mqtt, $prefix, $countryCode, $departmentNumber){

    $todayTimeStamp = strtotime("today");
    $holidays = getPublicHolidays($countryCode, $departmentNumber, date("Y")) + getPublicHolidays($countryCode, $departmentNumber, date("Y") + 1);

    $today = (array_key_exists($todayTimeStamp, $holidays)) ? 1 : 0;
    $todayLabel = ($today == 1) ? $holidays[$todayTimeStamp] : '';

    //find the next public holiday
    foreach ($holidays as $date => $label) {
        if ($date > $todayTimeStamp) {
            $nextIn = round(($date - $todayTimeStamp) / (60 * 60 * 24));
            $nextLabel = $label;
            break;
        }
    }

    publish($mqtt, $prefix.'/publicholidays/today', $today);
    publish($mqtt, $prefix.'/publicholidays/todaylabel', $todayLabel);
    publish($mqtt, $prefix.'/publicholidays/nextin', $nextIn);
    publish($mqtt, $prefix.'/publicholidays/nextlabel', $nextLabel);
}


function publishSeason(MqttClient $mqtt, $prefix) {
    $seasonObj = new Season();
    $season = $seasonObj->getSeason();
    $nextSeason = $seasonObj->getNextSeason();
    $nextSeasonIn = $seasonObj->getNextSeasonNbDays();
    
    publish($mqtt, $prefix.'/season/current', $season);
    publish($mqtt, $prefix.'/season/next', $nextSeason);
    publish($mqtt, $prefix.'/season/nextin', $nextSeasonIn);
}


//--------------------------------------------------------------------
//------------------------------- MAIN -------------------------------
//--------------------------------------------------------------------

$versionnumber='1.2.0';

echo sprintf('===== dayinfo2mqtt v%s =====',$versionnumber).PHP_EOL;

$timezone = $_ENV["TZ"] ?? "Europe/Paris";
$publishHour = $_ENV["PUBLISHHOUR"] ?? 0;
$featuresList = strtolower($_ENV["FEATURESLIST"] ?? 'base,moon,schoolholidays,publicholidays,season');
$countryCode = strtolower($_ENV["COUNTRY"] ?? 'fr');
$departmentNumber = $_ENV["DEPARTMENT"] ?? '75';
$debugMode = $_ENV["DEBUGMODE"] ?? false;

$mqttprefix = $_ENV["PREFIX"] ?? "dayinfo2mqtt";
$mqtthost = $_ENV["HOST"] ?? "127.0.0.1";
$mqttport = $_ENV["PORT"] ?? 1883;
$mqttclientid = $_ENV["CLIENTID"] ?? "dayinfo2mqtt";
$mqttuser = $_ENV["USER"] ?? null;
$mqttpassword = $_ENV["PASSWORD"] ?? null;

//Set up Timzezone for date/time php functions
if (!date_default_timezone_set($timezone)){
    echo 'Wrong TimeZone : '.$timezone.PHP_EOL.'Exit';
    return;
}

echo '===== Prepare MQTT Client ====='.PHP_EOL;
$mqtt = new MqttClient($mqtthost, $mqttport, $mqttclientid);

$shutdown = function (int $signal, $info) use ($mqtt, $mqttprefix) {
    echo PHP_EOL;
    logger('Exit');
    $mqtt->publish($mqttprefix.'/connected', '0', 0, true);
    $mqtt->interrupt();
};

pcntl_signal(SIGINT, $shutdown);
pcntl_signal(SIGTERM, $shutdown);


$connectionSettings = new ConnectionSettings();

//Configure Testament
$connectionSettings->setLastWillTopic($mqttprefix.'/connected');
$connectionSettings->setLastWillMessage('0');
$connectionSettings->setRetainLastWill(true);

//if there is username or password
if($mqttuser || $mqttpassword){
    if($mqttuser) $connectionSettings->setUsername($mqttuser);
    if($mqttpassword) $connectionSettings->setPassword($mqttpassword);
}

//Connect
$mqtt->connect($connectionSettings);

//Publish connection state
$mqtt->publish($mqttprefix.'/connected', '1', 0, true);

echo '===== MQTT Client Connected ====='.PHP_EOL;
echo 'Now waiting for the next publish time => ';
if (strtotime('today '.$publishHour.':00') > strtotime('now')) echo date(DATE_ATOM, strtotime('today '.$publishHour.':00')).PHP_EOL;
else echo date(DATE_ATOM, strtotime('tomorrow '.$publishHour.':00')).PHP_EOL;

$lastPublishTime = time();

//DEBUG
if ($debugMode) {
    logger('DEBUGMODE Enabled');
    $lastPublishTime = strtotime('yesterday');
}

$loopEventHandler = function (MqttClient $mqtt, float $elapsedTime) use ($publishHour, &$lastPublishTime, $mqttprefix, $featuresList, $countryCode, $departmentNumber, $debugMode) {
    $now = time();
    $todayPublishHour = strtotime('today '.$publishHour.':00');

    //DEBUG
    if ($debugMode) {
        $todayPublishHour = $lastPublishTime + 60;
    }

    //if today publish hour is between $lastPublishTime and $now, then publish
    if($lastPublishTime<$todayPublishHour && $todayPublishHour<=$now){

        logger('Publish Time');
        publish($mqtt, $mqttprefix.'/executionTime', date(DATE_ATOM));

        $explodedFeaturesList = explode(',', $featuresList);

        if(in_array('base',$explodedFeaturesList)) publishBase($mqtt, $mqttprefix);
        if(in_array('moon',$explodedFeaturesList)) publishMoon($mqtt, $mqttprefix);
        if(in_array('schoolholidays',$explodedFeaturesList)) publishSchoolHolidays($mqtt, $mqttprefix, $countryCode, $departmentNumber);
        if(in_array('publicholidays',$explodedFeaturesList)) publishPublicHolidays($mqtt, $mqttprefix, $countryCode, $departmentNumber);
        if(in_array('season',$explodedFeaturesList)) publishSeason($mqtt, $mqttprefix);

        $lastPublishTime = $now;
    }
};

$mqtt->registerLoopEventHandler($loopEventHandler);

$mqtt->loop(true);
$mqtt->disconnect();
