<?php

	require('vendor/autoload.php');	// needs "phrity/websocket", use composer
	
	define('ALLOWED_IDS',' [uid1],[uid2]');	// user ids allowed to send !commands, do not remove leading space or strpos will shit the bed
	define('API_DEFAULT_CHANNEL', 'channels/[channelID]'); // default channel for feedback
	define('API_DEFAULT_GATEWAY','wss://gateway.discord.gg');
	define('API_TARGET_BASE', 'https://discord.com/api/');
	define('API_TARGET_SUFFIX', '/messages');
	define('COMMAND_PREFIX','!');
	define('DEFAULT_MESSAGELIMIT',1900);
	define('DEFAULT_HEARTBEAT',41);
	define('DEFAULT_HEARTBEAT_OFFSET',200); // reduce heartbeat to avoid timeout (ms)
	define('DEFAULT_LINES_PER_MESSAGE',20);
	define('DEFAULT_LOGFILE','discord-bot.log');
	define('DEFAULT_USER_AGENT', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.3z');
	define('SHERLOCK_CACHE_PATH','./sherlock-cache/');
	
	define('SELF_TOKEN', '[your/your bot's discord token]');
	
	$glGateway = API_DEFAULT_GATEWAY;
	$glLogFile = fopen(DEFAULT_LOGFILE, "w"); // need this for lprint alias to echo to file
	$glZoneList=array('America/Costa_Rica','America/Guatemala'); // timezones to read for !time command
	
	$glHeartbeatCounter=NULL;
	$glHeartbeatInterval=DEFAULT_HEARTBEAT;
	$glHeartbeatRunning=0; // need global variables to be able to adjust heartbeat between the listener loop and handler function
	$glSessionId=NULL;
	
	
	
	lprint("[.] starting up...\n");
	lprint("[>] attempting to get discord API gateway... ");			
	
	$jsGateway=json_decode(curlGetGateway(API_TARGET_BASE.'gateway',DEFAULT_USER_AGENT));
	
	if($jsGateway->url){
		$glGateway=$jsGateway->url;
		lprint("success. retrieved ".$glGateway.".\n");
	}
	else{
		lprint("bad thing happen. falling back on hardcoded $glGateway.\n");
	}
	
	lprint("[>] attempting to connect to $glGateway and get hello.\n");
	startWebsocket($glGateway);
	lprint("[.] finished. ending.\n");
	
	function startWebsocket($wsGateway){
		
		$wsSocket=new WebSocket\Client($wsGateway);
		$wsSocket	->addMiddleware(new WebSocket\Middleware\CloseHandler())
					->onText(function (WebSocket\Client $wsSocket, WebSocket\Connection $wsConnectionId, WebSocket\Message\Message $wsLiveMessage) {
						global $glHeartbeatRunning,$glHeartbeatInterval,$glHeartbeatCounter,$glSessionId;	
						
						$jsLiveMessage=json_decode($wsLiveMessage->getContent());
						$wsCurrentOp=$jsLiveMessage->op;

						if($wsCurrentOp==10){							// HELLO message
							$glHeartbeatInterval=(intval(($jsLiveMessage->d->heartbeat_interval-DEFAULT_HEARTBEAT_OFFSET)/1000));	// trim heartbeat, convert to seconds
							lprint("[<] received HELLO message, opcode=$wsCurrentOp, interval=".$glHeartbeatInterval."s.\n");
							
							lprint("[>] sending auth response...\n");	
							$wsSocket->send(new WebSocket\Message\Text(buildIdentify(SELF_TOKEN)));
							
							if(!$glHeartbeatRunning){
								$glHeartbeatRunning++;
								forkHeartbeat($wsSocket);
							}
						}
						if($wsCurrentOp==11){ // HEARTBEAT echo
							lprint("[<] heartbeat received from gateway: ".$wsLiveMessage->getContent()."\n");
						}
						elseif($wsCurrentOp==0){ // EVENT structure
							$jsDispatch=json_decode($wsLiveMessage->getContent());
							lprint("[<] dispatch received from gateway. type=".$jsDispatch->t."\n");
							
							handleDispatch($wsSocket,$jsDispatch);
						}
						elseif($wsCurrentOp==7){ // RECONNECT required
							lprint("[<] received RECONNECT message.\n");
							lprint("[>] sending RESUME response...\n");	
							$wsSocket->send(new WebSocket\Message\Text(buildResume(SELF_TOKEN)));
						}
						else{
							lprint("[<] received message, opcode=$wsCurrentOp.\n");
						}
						
						
					})

					->start();	
	}
	
	function handleDispatch(WebSocket\Client $dsSocket, $dsObject){
		
		global $glSessionId;
		
		if($dsObject->t=="MESSAGE_CREATE"){ // !command code to be parsed
			if(strpos(ALLOWED_IDS,$dsObject->d->author->id) and substr($dsObject->d->content,0,1)==COMMAND_PREFIX){
				parseCommand($dsObject);
			}
		}
		elseif($dsObject->t=="READY"){
			$glSessionId=$dsObject->d->session_id; // need to save this for RESUME
			lprint("[<] READY received, $glSessionId saved.\n");
		}
	}
	
	function parseCommand($pcObject){
		if($pcObject->d->content=='!'){
			lprint("[<] received !.\n");
			lprint("[>] sending commandlist.\n");
			curlSendMessage(SELF_TOKEN,"channels/".$pcObject->d->channel_id,getCommands(),DEFAULT_USER_AGENT);
			print(getLogs());
		}
		elseif($pcObject->d->content=='!printlog'){
			lprint("[<] received !printlog.\n");
			lprint("[>] sending logs.\n");
			curlSendMessage(SELF_TOKEN,"channels/".$pcObject->d->channel_id,getLogs(),DEFAULT_USER_AGENT);
			print(getLogs());
		}
		elseif($pcObject->d->content=='!time'){
			lprint("[<] received !time.\n");
			lprint("[>] sending timezones.\n");
			curlSendMessage(SELF_TOKEN,"channels/".$pcObject->d->channel_id,getTimezones(),DEFAULT_USER_AGENT);
		}
		elseif($pcObject->d->content=='!killbot'){
			lprint("[<] received !killbot.\n");
			lprint("[>] sending notification.\n");
			curlSendMessage(SELF_TOKEN,"channels/".$pcObject->d->channel_id,"Going offline.",DEFAULT_USER_AGENT);
			lprint("[.] shutting down.\n");
			exit();
		}
		elseif($pcObject->d->content=='!torstatus'){
			lprint("[<] received !torstatus.\n");
			lprint("[>] sending status.\n");
			curlSendMessage(SELF_TOKEN,"channels/".$pcObject->d->channel_id,getTorStatus(),DEFAULT_USER_AGENT);
		}
		elseif(substr($pcObject->d->content,0,strlen('!usersearch '))=='!usersearch '){
			lprint("[<] received !usersearch.\n");
			$pcUser=preg_replace('/[^a-z0-9]/i', '_', substr($pcObject->d->content,strlen('!usersearch')+1));
			
			if($pcUser)
			{
				lprint("[.] processing username $pcUser.\n");
				getSherlockData($pcUser,$pcObject);
			}
			else
			{
				lprint("[.] username left blank. discarding command.\n");
			}
		}
		else{
			lprint("[<] received unknown".$pcObject->d->content.", discarding.\n");
		}
	}
	
	function forkHeartbeat(WebSocket\Client $hbSocket){
		global $glHeartbeatRunning,$glHeartbeatInterval,$glHeartbeatCounter;
		
		$hbPid = pcntl_fork();
		
		if($hbPid){
			
			lprint("[.] heartbeat monitor forked to $hbPid, current interval is $glHeartbeatInterval, count is at $glHeartbeatCounter, status is $glHeartbeatRunning.\n");
			$hbLast=time();
			while($glHeartbeatRunning){
				if(time()-$hbLast>=$glHeartbeatInterval){
					lprint("[>] interval of $glHeartbeatInterval seconds hit, resetting counter and sending heartbeat.\n");
					$hbSocket->send(new WebSocket\Message\Text(buildHeartbeat($glHeartbeatCounter)));	// we're actually supposed to read this from the ws heartbeat response but it's not currently giving us a sequence
					$hbLast=time();
					$glHeartbeatCounter++;
				}
				else{
					sleep(1); // only check once per second
				}
			}
		}
	}
	
	function curlSendMessage($smToken,$smChannel,$smMessage,$smAgent){
		$ssApiTarget=API_TARGET_BASE.$smChannel.API_TARGET_SUFFIX;
		$jsMessage=json_encode(array("content"=>"$smMessage"));
		lprint("[.] api target set to $ssApiTarget...\n");
		
		$smHandler=curl_init($ssApiTarget);
		lprint("[.] curl initialised.\n");
		
		curl_setopt($smHandler, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($smHandler, CURLOPT_VERBOSE, false);
		curl_setopt($smHandler, CURLOPT_POST, 1);
//		curl_setopt($smHandler, CURLOPT_USERAGENT, $smAgent); // bots are not allowed to have an agent, triggers 401 forbidden, enable this line if running as selfbot
		curl_setopt($smHandler, CURLOPT_POSTFIELDS, $jsMessage);
		curl_setopt($smHandler, CURLOPT_HTTPHEADER, array(
			"Content-type: application/json",
//			"Authorization: $smToken" // regular token
			"Authorization: Bot $smToken" // bot token
		));
		lprint("[.] http request built.\n");
		lprint("[>] sending... ");
	
		$clResult = curl_exec($smHandler);
		lprint("done.\n");
		curl_close($smHandler);
	}
	
	function getSherlockData($sdUser,$sdObject){
		// sherlock takes a while so we need to fork this
		
		$sdResponse="```generating list for $sdUser. this should take <60sec and will publish when complete.```";
		curlSendMessage(SELF_TOKEN,"channels/".$sdObject->d->channel_id,$sdResponse,DEFAULT_USER_AGENT);
		
		$sdPid = pcntl_fork();
			
		if($sdPid){
			lprint("[.] running user search in new pid:$sdPid.\n");
			shell_exec("sherlock --output ".SHERLOCK_CACHE_PATH.$sdUser." --nsfw $sdUser");
			$sdOutput=readFileIntoArray(SHERLOCK_CACHE_PATH.$sdUser);
			
			foreach($sdOutput as $sdChunk)
			{
				$sdChunk="``` $sdChunk ```";
				curlSendMessage(SELF_TOKEN,"channels/".$sdObject->d->channel_id,$sdChunk,DEFAULT_USER_AGENT);
			}
		}

		return;
	}

	function readFileIntoArray($rfFile){
		
		$rfChunks=array();
		$rfContent=file_get_contents($rfFile);
		$rfLines=explode("\n",$rfContent);
		
		$x=0;
		$rfBuffer='';
		
		foreach($rfLines as $rfLine){
			if($x<DEFAULT_LINES_PER_MESSAGE){
				$rfBuffer="$rfBuffer$rfLine\n";
				$x++;
			}
			else{
				array_push($rfChunks,$rfBuffer);
				$rfBuffer="$rfLine\n";
				$x=1;
				
			}
		}
		
		if($rfBuffer){
			array_push($rfChunks,$rfBuffer);
		}
		return($rfChunks);
	}
	
	function getCommands(){
		$cmList=("```
!               :command list
!printlog       :dump the last 20 items from the log
!time           :return time from multiple timezones
!torstatus      :check status of tor hidden service

!killbot        :terminate bot
```");
		
		return($cmList);
	}
	
	function getTimezones(){
		global $glZoneList;
		$tzTime="";
		
		foreach($glZoneList as $tzZone)
		{
			$tzTime.=("$tzZone: ".exec("TZ=$tzZone date")."\n");
		}
		
		$tzTime="```$tzTime```";
		return($tzTime);
	}
	
	function getTorStatus(){
		$tsHiddenService="```[your hidden service id].onion\n".exec('systemctl status tor|grep -i active\:|sed "s/^[ \t]*//"')."```";	// shoud use `cat /var/lib/tor/hidden_service/hostname` but couldn't work out a way to safely do it without root
		return($tsHiddenService);
	}
	
	function getLogs($glLines=DEFAULT_LINES_PER_MESSAGE){
		$glLogs='```'.shell_exec("tail -n$glLines ./".DEFAULT_LOGFILE).'```';
		return($glLogs);
	}
	
	function buildHeartbeat($bhCounter=NULL){
		$bhBeat=array(
			'op'=>1,
			'd'=>$bhCounter
		);
		
		return(json_encode($bhBeat));
	}
	
	function buildResume($brToken){

		$brIdentity=array(
			'op'=>6,
			'd'=>array(
					'token'=>$brToken,
					'session_id'=>$glSessionId
			)
		);
		
		return(json_encode($brIdentity));
	}
	
	function buildIdentify($biToken){

		$biIdentity=array(
			'op'=>2,
			'd'=>array(
					'token'=>$biToken,
					'properties'=>array(
						'os'=>'linux',
						'browser'=>'nervegas',
						'device'=>'nervegas'
					)
			)
		);
		
		return(json_encode($biIdentity));
	}
		
	function curlGetGateway($ggApiTarget,$ggAgent){
		$smHandler=curl_init($ggApiTarget);
		
		curl_setopt($smHandler, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($smHandler, CURLOPT_HTTPGET, 1);
		curl_setopt($smHandler, CURLOPT_USERAGENT, $ggAgent);
		$clResult = curl_exec($smHandler);
		curl_close($smHandler);
		return($clResult);
	}

	function lprint($lpData){
		global $glLogFile;
		
		fwrite($glLogFile, $lpData);
		print($lpData);
	}

/*		this code calls you a retard the moment you start typing, I removed it because it was super annoying
		goes in handleDispatch() function
		
		if($dsObject->t=="TYPING_START" and ($dsObject->d->channel_id=="[channel removed]")){ 			
			$dsTarget=$dsObject->d->member->user->id;
			$dsChannel="channels/".$dsObject->d->channel_id;
			$dsMessage="SHUT UP RETARD <@$dsTarget>";
			
			lprint("[>] chastising $dsTarget.\n");
			
			curlSendMessage(SELF_TOKEN,$dsChannel,$dsMessage,DEFAULT_USER_AGENT);	
		}
*/
?>
