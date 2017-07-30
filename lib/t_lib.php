<?php

/**
  * API Class to connect to Radio Thermostat
  *
  */

class Thermostat_Exception extends Exception{
}


class Stat{
  protected $ch,
            $IP;  // Most likely an URL and port number rather than a strict set of TCP/IP octets.

  // Low level communication adjustments
  protected static  $initialTimeout = 5000,     // Start with a 5 second timeout
                    $timeoutIncrement = 5000,   // Each time that the curl operation times out, add 5 seconds beore trying again
                    $maxRetries = 4;            // Try at most 4 times before giving up (5 + 10 + 15 + 20 = 50 seconds spent trying!)

  private $debug = false;


  // Would prefer these to be private/protected and have get() type functions to return value.
  // But for now, public will do because I am lazy.
  public $temp =  null
        ,$tmode = null
        ,$fmode = null
        ,$override =  null
        ,$hold =  null
        ,$t_cool =  null
        ,$tstate =  null
        ,$fstate =  null
        ,$day = null
        ,$time =  null
        ,$t_type_post = null
        ,$humidity = null
        ,$ZIP = null;

public $dummy_time = null, $dummy_temp = null;

  public $runTimeCool = null,
         $runTimeHeat = null,
         $runTimeCoolYesterday = null,
         $runTimeHeatYesterday = null;

  // Set to -1 before each curl_exec call.  A value of 0 means it worked.  Otherwise it gets the last encountered curl error number
  public $connectOK = null;

  //
  public $errStatus = null;
  //
  public $model = null;

  // System vars
  public $uuid = null,
         $api_version = null,
         $fw_version = null,
         $wlan_fw_version = null,
         $ssid = null,
         $bssid = null,
         $channel = null,
         $security = null,
         $passphrase = null,
         $ipaddr = null,
         $ipmask = null,
         $ipgw = null,
         $rssi = null;

  public function __construct( $thermostatRec ){
    $this->IP = $thermostatRec['ip'];
    $this->ZIP = $thermostatRec['zip_code'];
    $this->ch = curl_init();
    curl_setopt( $this->ch, CURLOPT_USERAGENT, 'A' );
    curl_setopt( $this->ch, CURLOPT_RETURNTRANSFER, 1 );
    curl_setopt( $this->ch, CURLOPT_LOCALPORT, 9000 );
    curl_setopt( $this->ch, CURLOPT_LOCALPORTRANGE, 100 );



    $this->debug = 0;

    // Stat variables initialization
    $this->temp = 0;
    $this->tmode = 0;
    $this->fmode = 0;
    $this->override = 0;
    $this->hold = 0;
    $this->t_cool = 0;
    $this->tstate = 0;
    $this->fstate = 0;
    $this->day = 0;
    $this->time = 0;
    $this->t_type_post = 0;
    $this->humidity = -1;
    //
    $this->runTimeCool = 0;
    $this->runTimeHeat = 0;
    $this->runTimeCoolYesterday = 0;
    $this->runTimeHeatYesterday = 0;
    //
    $this->errStatus = 0;
    //
    $this->model = 0;

    // System variables
    $this->uuid = 0;
    $this->api_version = 0;
    $this->fw_version = 0;
    $this->wlan_fw_version = 0;
    $this->ssid = 0;
    $this->bssid = 0;
    $this->channel = 0;
    $this->security = 0;
    $this->passphrase = 0;
    $this->ipaddr = 0;
    $this->ipmask = 0;
    $this->ipgw = 0;
    $this->rssi = 0;

    // Cloud variables
  }

  public function __destruct(){
    curl_close( $this->ch );
  }

  protected function getStatData( $cmd ){
    global $util;
    $commandURL = 'http://' . $this->IP . $cmd;
    $this->connectOK = -1;
    $newTimeout = self::$initialTimeout;

    // For reference http://www.php.net/curl_setopt
    curl_setopt( $this->ch, CURLOPT_URL, $commandURL );

//$util::logDebug( 't_lib: getStatData trying...' );
    $retry = 0;
    do{
//$util::logDebug( 't_lib: getStatData doing...' );
if( $retry > 0 ) $util::logDebug( "t_lib: getStatData: setting timeout to $newTimeout for try number $retry." );
      curl_setopt( $this->ch, CURLOPT_TIMEOUT_MS, $newTimeout );
      $retry++;
      $outputs = curl_exec( $this->ch );
      if( curl_errno( $this->ch ) != 0 ){
        $util::logWarn( 't_lib: getStatData curl error (' .  curl_error( $this->ch ) .' -> '. curl_errno( $this->ch ) . ") when performing command ($cmd) on try number $retry" );
        if( curl_errno( $this->ch ) == 28 ){
          $newTimeout += self::$timeoutIncrement;
          $util::logDebug( "t_lib: getStatData: changed timeout to $newTimeout because of timeout error in curl command." );
        }
      }
      /** Build in one second sleep after each communication attempt
        * based on code from phareous - he had 2 second delay here and there
        * The thermostat will stop responding for 20 to 30 minutes (until next WiFi reset) if you overload the connection.
        * Previously I was not using a delay and had not problems, but caution is better.
        *
        * Later on, in a many thermostat environment, each stat will need to be queried in a thread so that the delays
        * do not stack up and slow the overall application to a crawl.
        */
      sleep( 1 );
    }
    while( ( curl_errno( $this->ch ) != 0 ) && ($retry < self::$maxRetries) );
    //while( (curl_errno( $this->ch ) == 7) && ($retry < $maxRetries) );
    // curl error #7 CURLE_COULDNT_CONNECT is usually resolved with a simple single retry.
//$util::logDebug( 't_lib: getStatData completed...' );
    if( $retry > 1 ){
      $util::logWarn( "t_lib: Made $retry attempts and last curl status was " . curl_errno( $this->ch ) );
    }

    $this->connectOK = curl_errno( $this->ch ); // Only capture the last status because the retries _might_ have worked!

    if( $this->debug ){
      // Convert to use log?
      echo '<br>commandURL: ' . $commandURL . '<br>';
      echo '<br>Stat says:<br>';
      if( $this->connectOK != 0 ){
        echo var_dump( json_decode( $outputs ) );
      }
      else{
        echo '<br>Communication error - the thermostat did not say ANYTHING!';
      }
      echo '<br><br>';
    }

    if( $this->connectOK != 0 ){
      // Drat some problem.  Now what?
      $util::logError( 't_lib: getStatData communication error.' );
    }

    return $outputs;
  }

  protected function setStatData( $command, $value ){
    $this->connectOK = -1;

    $commandURL = 'http://' . $this->IP . $command;

    curl_setopt( $this->ch, CURLOPT_URL, $commandURL );
    curl_setopt( $this->ch, CURLOPT_POSTFIELDS, $value );

    if( $this->debug  ){
      $util::logDebug( "t_lib: setStatData: commandURL was $commandURL" );
      echo '<br>commandURL: ' . $commandURL . '<br>';
    }

    if( !$outputs = curl_exec( $this->ch ) ){
      throw new Thermostat_Exception( 'setStatData: ' . curl_error($this->ch) );
    }
    $this->connectOK = curl_errno( $this->ch );

    // Need to wait for a response... object(stdClass)#4 (1) { ['success']=> int(0) }

    // Once we actually start using teh set function the error detection, timeout, and retry logic will begin to apply here too.
    return;
  }

  protected function containsTransient( $obj ){
    global $util;
    $retval = false;
    // Aha!  This might be how to detect the missing connection?
//$util::logError( 't_lib: containsTransient looking...' );
    if( is_object( $obj ) ){
      foreach( $obj as $key => &$value ){
  //$util::logDebug( 't_lib: containsTransient key...' );
    // Warning: Invalid argument supplied for foreach() in ~/thermo2/lib/t_lib.php on line 171
    // It was line 171 before I started adding comments!
        if( is_object($value) ){
          foreach( $value as $key2 => &$value2 ){
  //$util::logDebug( 't_lib: containsTransient nested key...' );
            if( $value2 == -1 ){
              //if( $this->debug )
              //echo 'WARNING (' . date(DATE_RFC822) . '): ' . $key2 . " contained a transient\n";
              $util::logWarn( 't_lib: containsTransient WARNING (' . date(DATE_RFC822) . '): ' . $key2 . " contained a transient\n" );
              // NULL the -1 transient
              //$value2 = NULL;
              $retval = true;
            }
          }
        }
        else{
          // Comment out because this message appears even when everything is working!
          // $util::logError( 't_lib: containsTransient: value was NOT an object!' );
        }
        if( $value == -1 ){
          //echo 'WARNING (' . date(DATE_RFC822) . '): ' . $key . " contained a transient\n";
          $util::logWarn( 't_lib: containsTransient WARNING (' . date(DATE_RFC822) . '): ' . $key . " contained a transient\n" );
          // NULL the -1 transient
          //$value = NULL;
          $retval = true;
        }
      }
    }
    else{
      $util::logError( 't_lib: containsTransient: argument obj was NOT an object!' );
      if( is_scalar( $obj ) ){
        $util::logDebug( 't_lib: transient is ' . $obj );
      }
      elseif( is_null( $obj ) ){
        $util::logDebug( 't_lib: transient is NULL' );
      }
      elseif( is_array( $obj ) ){
        $util::logDebug( 't_lib: transient is array (ought to dump it here)' );
      }
    }
//$util::logDebug( 't_lib: containsTransient ending...' );
    return $retval;
  }

  public function showMe(){
    // For now hard coded HTML <br> but later let CSS do that work
    echo '<br><br>Thermostat data (Yaay!  I found the introspection API - hard coding SUCKS)';
    echo '<table id="stat_data">';
    echo '<tr><th>Setting</th><th>Value</th><th>Explanation</th></tr>';

    $rc = new ReflectionClass( 'Stat' );
    $prs = $rc->getProperties();
    $i = 0;
    foreach( $prs as $pr ){
      if( $i == 0 ){
        $i = 1;
        echo '<tr>';
      }
      else{
        $i = 0;
        echo '<tr class="alt">';
      }
      $key = $pr->getName();
      $val = $this->{$pr->getName()};
      if( $key == 'ZIP' || $key == 'ssid' ){
// Once we have password protected pages, allow these to be shown?
        $val = 'MASKED';
      }
      echo '<td>' . $key . '</td><td>' . $val . '</td></tr>';
    }
  }

  // Still need a list of explanation and values to interpret.
  public function showMeOld(){
    // For now hard coded HTML <br> but later let CSS do that work
    echo '<br><br>Thermostat data';
    echo '<table id="stat_data">';
    echo '<tr><th>Setting</th><th>Value</th><th>Explanation</th></tr>';

//    echo '<br><br>From /tstat command';
    echo '<tr><td>this->temp</td><td>' . $this->temp . '</td><td>�F</td></tr>';
// The degree mark is not HTML5 compliant.
// Instead of forcing a degree F, check the mode from config.php

    $statTMode = array( 'Auto?', 'Heating', 'Cooling' );
    echo '<tr class="alt"><td>this->tmode</td><td>' . $this->tmode        . '</td><td> [ ' . $statTMode[$this->tmode] . ' ] </td></tr>';

    $statFanMode = array( 'Auto', 'On' );
    echo '<tr><td>this->fmode</td><td>' . $this->fmode        . '</td><td> [ ' . $statFanMode[$this->fmode] . ' ] </td></tr>';

    echo '<tr class="alt"><td>this->override</td><td>' . $this->override . '</td><td></td></tr>';

    $statHold = array( 'Normal', 'Hold Active' );
    echo '<tr><td>this->hold</td><td>' . $this->hold         . '</td><td> [ ' . $statHold[$this->hold] . ' ] </td></tr>';

    echo '<tr class="alt"><td>this->t_cool</td><td>' . $this->t_cool . '</td><td>�F</td></tr>';

    $statTState = array( 'Off', 'Heating', 'Cooling' );
    echo '<tr class="alt"><td>this->tstate</td><td>' . $this->tstate       . '</td><td> [ ' . $statTState[$this->tstate] . ' ] </td></tr>';

    $statFanState = array( 'Off', 'On' );
    echo '<tr><td>this->fstate</td><td>' . $this->fstate       . '</td><td> [ ' . $statFanState[$this->fstate] . ' ] </td></tr>';

//    echo '<br>this->day       : ' . $this->day          . ' [ ' . jddayofweek($this->day,1) . ' ] </td><td></td></tr>';
    $statDayOfWeek = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );
    echo '<tr class="alt"><td>this->day</td><td>' . $this->day          . '</td><td> [ ' . $statDayOfWeek[$this->day] . ' ] </td></tr>';

    echo '<tr><td>this->time</td><td>' . $this->time . '</td><td></td></tr>';
    echo '<tr class="alt"><td>this->t_type_post</td><td>' . $this->t_type_post . '</td><td></td></tr>';

//    echo '<br><br>From /tstat/datalog command (converted to minutes)';
    echo '<tr><td>this->runTimeCool</td><td>' . $this->runTimeCool . '</td><td></td></tr>';
    echo '<tr class="alt"><td>this->runTimeHeat</td><td>' . $this->runTimeHeat . '</td><td></td></tr>';
    echo '<tr><td>this->runTimeCoolYesterday</td><td>' . $this->runTimeCoolYesterday . '</td><td></td></tr>';
    echo '<tr class="alt"><td>this->runTimeHeatYesterday</td><td>' . $this->runTimeHeatYesterday . '</td><td></td></tr>';

//    echo '<br><br>From /tstat/errorstatus command';
    echo '<tr><td>this->errStatus</td><td>' . $this->errStatus          . '</td><td>[ 0 is OK ]</td></tr>';

//    echo '<br><br>From /tstat/model command';
    echo '<tr class="alt"><td>this->model</td><td>' . $this->model . '</td><td></td></tr>';

    echo '</table>';

    echo '<br><br>System data';

    echo '<table id="sys_data">';
    echo '<tr><th>Setting</th><th>Value</th><th>Explanation</th></tr>';

//    echo '<tr><td>this->uuid</td><td>'            . $this->uuid           . '</td><td> MAC address of thermostat</td></tr>';
echo '<tr><td>this->uuid</td><td>'            . 'MASKED'            . '</td><td> MAC address of thermostat</td></tr>';
    echo '<tr class="alt"><td>this->api_version</td><td>'    . $this->api_version    . '</td><td> 1 (?)</td></tr>';
    echo '<tr><td>this->fw_version</td><td>'      . $this->fw_version     . '</td><td> e.g. 1.03.24</td></tr>';
    echo '<tr class="alt"><td>this->wlan_fw_version</td><td>' . $this->wlan_fw_version . '</td><td> e.g. v10.99839</td></tr>';


//    echo '<tr><td>this->ssid</td><td>'       . $this->ssid       . '</td><td>SSID</td></tr>';
echo '<tr><td>this->ssid</td><td>'       . 'MASKED'      . '</td><td>SSID</td></tr>';
//    echo '<tr class="alt"><td>this->bssid</td><td>'     . $this->bssid      . '</td><td>MAC address of wifi device</td></tr>';
echo '<tr class="alt"><td>this->bssid</td><td>'     . MASKED      . '</td><td>MAC address of wifi device</td></tr>';
    echo '<tr><td>this->channel</td><td>'   . $this->channel    . '</td><td>Current wifi channel e.g. 11</td></tr>';
//    echo '<tr class="alt"><td>this->security</td><td>'   . $this->security   . '</td><td>WiFi security protocol: 1 (WEP Open), 3 (WPA), 4 (WPA2 Personal)</td></tr>';
echo '<tr class="alt"><td>this->security</td><td>'   . 'MASKED'  . '</td><td>WiFi security protocol: 1 (WEP Open), 3 (WPA), 4 (WPA2 Personal)</td></tr>';
//    echo '<tr><td>this->passphrase</td><td>' . $this->passphrase . '</td><td>password (not shown in api_version 113)</td></tr>';
echo '<tr><td>this->passphrase</td><td>' . 'MASKED' . '</td><td>password (not shown in api_version 113)</td></tr>';
    echo '<tr class="alt"><td>this->ipaddr</td><td>'     . $this->ipaddr     . '</td><td>IP address of thermostat (api_version 113 shows "1" ?)</td></tr>';
    echo '<tr><td>this->ipmask</td><td>'     . $this->ipmask     . '</td><td>Netmask (not shown in api_version 113?)</td></tr>';
    echo '<tr class="alt"><td>this->ipgw</td><td>'       . $this->ipgw       . '</td><td>Gateway (not shown in api_version 113?)</td></tr>';
    echo '<tr><td>this->rssi</td><td>'       . $this->rssi       . '</td><td>Received Signal Strength (api_version 113)</td></tr>';
    echo '</table>';


    return;
  }

  public function getStat(){
    global $util;
    /** Query thermostat for data and check the query for transients.
      * If there are transients repeat query up to 5 times for collecting good data
      * Continue when successful.
      *
      */
    for( $i = 1; $i <= 5; $i++ ){
      $outputs = $this->getStatData( '/tstat' );  // getStatData() has it's own retry function.
      // {"temp":80.50,"tmode":2,"fmode":0,"override":0,"hold":0,"t_cool":80.00,"tstate":2,"fstate":1,"time":{"day":2,"hour":18,"minute":36},"t_type_post":0}
      $obj = json_decode( $outputs );

      if( !$this->containsTransient( $obj ) ){
        // It worked?  Get out ouf the retry loop.
        break;
      }
      else{
        if( $i == 5 ){
          $util::logError( 't_lib: Too many thermostat transient communication failuress.' );
          throw new Thermostat_Exception( 'Too many thermostat transient failures' );
        }
        else{
          //echo "Transient (" . date(DATE_RFC822) . ") failure " . $i . " retrying...\n";
          $util::logDebug( "t_lib: Transient (" . date(DATE_RFC822) . ") failure " . $i . " retrying...\n" );
        }
      }

      if( empty( $obj ) ){
        $util::logError( 't_lib: No output from thermostat.' );
        throw new Thermostat_Exception( 'No output from thermostat' );
      }
    }

    // Move fetched data to internal data structure
    $this->temp = $obj->{'temp'};            // Present temp in deg F (or C depending on thermostat setting)
    $this->tmode = $obj->{'tmode'};          // Present t-stat mode
    $this->fmode = $obj->{'fmode'};          // Present fan mode
    $this->override = $obj->{'override'};    // Present override status 0 = off, 1 = on
    $this->hold = $obj->{'hold'};            // Present hold status 0 = off, 1 = on

    if( $this->tmode == 1 ){
      // mode 1 is heat
      $this->t_heat = $obj->{'t_heat'};      // Present heat target temperature in degrees
    }
    else if( $this->tmode == 2 ){
      // mode 2 is cool
      $this->t_cool = $obj->{'t_cool'};      // Present cool target temperature in degrees
    }
    // I kinda wish this was $this->t_target as we only need to distinguish between heat and cool desired temperatures in the schedules.

    $this->tstate = $obj->{'tstate'};        // Present heater/compressor state 0 = off, 1 = heating, 2 = cooling
    $this->fstate = $obj->{'fstate'};        // Present fan state 0 = off, 1 = on

    $var1 = $obj->{'time'};                  // Present time
    $this->day = $var1->{'day'};
//    $this->time = sprintf(' %2d:%02d %s',($var1->{'hour'} % 13) + floor($var1->{'hour'} / 12), $var1->{'minute'} ,$var1->{'hour'}>=12 ? 'PM':'AM');
    $this->time = sprintf(' %2d:%02d',($var1->{'hour'}), $var1->{'minute'} );

    $this->t_type_post = $obj->{'t_type_post'};

    return;
  }

  public function getDataLog(){
    $outputs = $this->getStatData( '/tstat/datalog' );
    $obj = json_decode( $outputs );

    $var1 = $obj->{'today'};
    $var2 = $var1->{'heat_runtime'};
    $this->runTimeHeat = ($var2->{'hour'} * 60) + $var2->{'minute'};

    $var2 = $var1->{'cool_runtime'};
    $this->runTimeCool = ($var2->{'hour'} * 60) + $var2->{'minute'};

    $var1 = $obj->{'yesterday'};
    $var2 = $var1->{'heat_runtime'};
    $this->runTimeHeatYesterday = ($var2->{'hour'} * 60) + $var2->{'minute'};

    $var2 = $var1->{'cool_runtime'};
    $this->runTimeCoolYesterday = ($var2->{'hour'} * 60) + $var2->{'minute'};

    return;
  }

  public function getErrors(){
    $outputs = $this->getStatData( '/tstat/errstatus' );
    $obj = json_decode( $outputs );
    $this->errStatus = $obj->{'errstatus'};

    return;
  }

  public function getEventLog(){
$this->debug = true;
    $outputs = $this->getStatData( '/tstat/eventlog' );
    $obj = json_decode( $outputs );
    $var1 = $obj->{'eventlog'};
$this->debug = false;
    throw new Thermostat_Exception( 'getEventLog() - Not implemented' );

    return;
  }

  // Essentially a duplicate function, but it works
  public function getFMode(){
    $outputs = $this->getStatData( '/tstat/fmode' );
    $obj = json_decode( $outputs );
    $this->fmode = $obj->{'fmode'};          // Present fan mode

    return;
  }

  public function getHelp(){
$this->debug = true;
    $outputs = $this->getStatData( '/tstat/help' );
    $obj = json_decode( $outputs );
$this->debug = false;
    throw new Thermostat_Exception( 'getHelp() - Not implemented' );

    return;
  }

  // Essentially a duplicate function, but it works
  public function getHold(){
    $outputs = $this->getStatData( '/tstat/hold' );
    $obj = json_decode( $outputs );
    $this->hold = $obj->{'hold'};            // Present hold status 0 = off, 1 = on

    return;
  }

  public function getHumidity(){
    $outputs = $this->getStatData( '/tstat/humidity' ); // {"humidity":-1.00} This is example of no sensor.
    $obj = json_decode( $outputs );
    $this->humidity = $obj->{'humidity'};               // Present humidity

    return;
  }

  public function setLED(){
    throw new Thermostat_Exception( 'setLED() - Not implemented' ); // Prevent problems for now

    $outputs = $this->getStatData( '/tstat/led' );
    $obj = json_decode( $outputs );

    return;
  }

  public function getModel(){
    $outputs = $this->getStatData( '/tstat/model' );  // {"model":"CT50 V1.09"}
    $obj = json_decode( $outputs );
    $this->model = $obj->{'model'};

    return;
  }


  public function getSysName(){
    $outputs = $this->getStatData( '/sys/name' ); // {"name":"Home"}
    $obj = json_decode( $outputs );
    $this->sysName = $obj->{'name'};

    return;
  }


  // Essentially a duplicate function, but it works
  public function getOverride(){
    $outputs = $this->getStatData( '/tstat/override' );
    $obj = json_decode( $outputs );

    $this->override = $obj->{'override'};
    return;
  }

  public function getPower(){
    $outputs = $this->getStatData( '/tstat/power' );
    $obj = json_decode( $outputs );

    $this->power = $obj->{'power'}; // Milliamps?
    return;
  }

  public function setBeep(){
    throw new Thermostat_Exception( 'setBeep() - Not implemented' );  // Prevent problems for now

    $outputs = $this->getStatData( '/tstat/beep' );
    $obj = json_decode( $outputs );

    return;
  }

  public function setUMA(){
    throw new Thermostat_Exception( 'setUMA() - Not implemented' ); // Prevent problems for now

    $outputs = $this->getStatData( '/tstat/uma' );
    $obj = json_decode( $outputs );

    return;
  }

  public function setPMA(){
    throw new Thermostat_Exception( 'setPMA() - Not implemented' ); // Prevent problems for now

    $outputs = $this->getStatData( '/tstat/pma' );
    $obj = json_decode( $outputs );

    return;
  }

  // Essentially a duplicate function, but it works
  public function getTemp(){
    $outputs = $this->getStatData( '/tstat/temp' );
    $obj = json_decode( $outputs );
    $this->temp = $obj->{'temp'};            // Present temp in deg F (or C?)

    return;
  }

  // Essentially a duplicate function, but it works
  public function getTimeDay(){
    $outputs = $this->getStatData( '/tstat/time/day' );
    $obj = json_decode( $outputs );

    return;
  }

  // Essentially a duplicate function, but it works
  public function getTimeHour(){
    $outputs = $this->getStatData( '/tstat/time/hour' );
    $obj = json_decode( $outputs );

    return;
  }

  // Essentially a duplicate function, but it works
  public function getTimeMinute(){
    $outputs = $this->getStatData( '/tstat/time/minute' );
    $obj = json_decode( $outputs );

    return;
  }

  // Essentially a duplicate function, but it works
  public function getTime(){
    $outputs = $this->getStatData( '/tstat/time' );
    $obj = json_decode( $outputs );

    return;
  }

  // Hard coded to Tuesday for testing
  public function setTimeDay(){
    throw new Thermostat_Exception( 'setTimeDay() - Not implemented' ); // Prevent problems for now

    $cmd = '/tstat/time/day';
    $value = '{\'day\':2}';  // 2 = Tuesday
    $outputs = $this->setStatData( $cmd, $value );

echo var_dump( json_decode( $outputs ) );
    return;
  }

  public function getSysInfo(){
    global $util;

    $outputs = $this->getStatData( '/sys' );  // '/sys/info' No longer works as of API version ???

    if( $this->connectOK == 0 ){
      // If the connection worked, decode the output
      $obj = json_decode( $outputs );
      // {"uuid":"xxxxxxxxxxxx","api_version":113,"fw_version":"1.04.84","wlan_fw_version":"v10.105576"}

      $this->uuid = $obj->{'uuid'};
      $this->api_version = $obj->{'api_version'};
      $this->fw_version = $obj->{'fw_version'};
      $this->wlan_fw_version = $obj->{'wlan_fw_version'};
    }
    else{
      $util::logDebug( "t_lib: getSysInfo connectOK shows an error ($this->connectOK)" );
    }

    return;
  }

  public function getSysNetwork(){
    global $util;
    $outputs = $this->getStatData( '/sys/network' );

    if( $this->connectOK == 0 ){
      // If the connection worked, decode the output
      $obj = json_decode( $outputs );

      $this->ssid = $obj->{'ssid'};
      $this->bssid = $obj->{'bssid'};
      $this->channel = $obj->{'channel'};
      $this->security = $obj->{'security'};
      $this->passphrase = $obj->{'passphrase'};
      $this->ipaddr = $obj->{'ipaddr'};
      $this->ipmask = $obj->{'ipmask'};
      $this->ipgw = $obj->{'ipgw'};
      $this->rssi = $obj->{'rssi'};
    }
    else{
      $util::logDebug( "t_lib: getSysNetwork connectOK shows an error ($this->connectOK)" );
    }

    return;
  }

  /**
    * Something seriously wrong here.
    * It is telling me that every day is identical and the schedules are all wonky
    * I subscribe to that e5 service and perhaps they are doing this to my schedule?
    * Using the function to grab a single day tells the same story: /tstat/program/heat/wednesday

    /tstat/program/heat
    {
      "0":[600,66,1439,58,1439,58,1439,58],
      "1":[600,66,1439,58,1439,58,1439,58],
      "2":[600,66,1439,58,1439,58,1439,58],
      "3":[600,66,1439,58,1439,58,1439,58],
      "4":[600,66,1439,58,1439,58,1439,58],
      "5":[600,66,1439,58,1439,58,1439,58],
      "6":[600,66,1439,58,1439,58,1439,58]
    }

    /tstat/program/cool
    {
      "0":[600,76,1439,78,1439,78,1439,78],
      "1":[600,78,1439,78,1439,78,1439,78],
      "2":[600,76,1439,78,1439,78,1439,78],
      "3":[600,76,1439,78,1439,78,1439,78],
      "4":[600,76,1439,78,1439,78,1439,78],
      "5":[600,76,1439,78,1439,78,1439,78],
      "6":[600,76,1439,78,1439,78,1439,78]
    }
    */

  // When this routine works the first time, it can take up to 15 seconds
  public function getProgram(){
    global $util;
    $d_time = array( array() );
    $d_temp = array( array() );

    $outputs = $this->getStatData( '/tstat/program/cool' );
    if( $this->connectOK == 0 ){
      // If the connection worked, decode the output
      $obj = json_decode( $outputs );

      if( is_object( $obj ) ){
//echo "\nobj is object";
        foreach( $obj as $day => &$program )
        {// I think this loop will be for each day
//echo "\nforeach got key value as $key $value";
          $period = 0;
          for( $index = 0; $index < 8; $index++ ){
//echo "\nwhen key is $key and i $i then value[i] is $value[$i]";
            $d_time[0][$day][$period] = $program[$index];
            $index++;
            $d_temp[0][$day][$period] = $program[$index];
            $period++;
          }
        }
      }

      $outputs = $this->getStatData( '/tstat/program/heat' );
      if( $this->connectOK == 0 ){
        // If the connection worked, decode the output
        $obj = json_decode( $outputs );

        if( is_object( $obj ) ){
          foreach( $obj as $day => &$program ){
            // I think this loop will be for each day
            $period = 0;
            for( $index = 0; $index < 8; $index++ ){
              $d_time[1][$day][$period] = $program[$index];
              $index++;
              $d_temp[1][$day][$period] = $program[$index];
              $period++;
            }
          }
        }

        // This assignment is conditional - both cool and heat must have worked to use the data
        $this->dummy_time = $d_time;
        $this->dummy_temp = $d_temp;
      }
      else{
        $util::logError( 't_lib: getProgram fetch of HEAT program failed.' );
      }
    }
    else{
      $util::logError( 't_lib: getProgram fetch of COOL program failed.' );
    }


    return;
  }

  public function setProgram(){
    // May have to iterate through all days since the set function seems to be for one day
    // Or perhaps do a get and only send the modified days.

    return;
  }
}

?>