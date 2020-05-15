<?php

use InstagramAPI\Response\Model\DirectThreadItem;
use InstagramAPI\Request\Direct;

/*
 * Usage:
 * # mark item 456 in thread 123 as seen
 * $ curl -i 'http://127.0.0.1:1307/seen?threadId=123&threadItemId=456'
 * # send typing notification to thread 123
 * # send some message to thread 123
 * $ curl -i 'http://127.0.0.1:1307/message?threadId=123&text=Hi!'
 * # share post 456_789 to thread 123
 * # ping realtime http server
 * $ curl -i 'http://127.0.0.1:1307/ping'
 * # stop realtime http server
 * $ curl -i 'http://127.0.0.1:1307/stop'
 */

set_time_limit(0);
date_default_timezone_set('UTC');

require __DIR__.'/vendor/autoload.php';


$verification_method = 0; 	// 0 = SMS 1 = Email per la challange

class ExtendedInstagram extends \InstagramAPI\Instagram {
    public function changeUser($username2, $password2) {$this->_setUser( $username2, $password2 );}
}

function readln( $prompt ) { // funzione per inserire il codice di verifica
    if ( PHP_OS === 'WINNT' ) {echo "$prompt ";return trim( (string) stream_get_line( STDIN, 6, "\n" ) );}
    return trim( (string) readline( "$prompt " ) );
}


try {
        
    /////// CONFIG ///////
    $username = $argv[1];
    $password = $argv[2];
    $httpServerport = $argv[3];
    $urlNotif = $argv[4];
    $debug = false;
    $truncatedDebug = false;
    //////////////////////
    
    $ig = new ExtendedInstagram($debug, $truncatedDebug);
    
    $loginResponse=$ig->login($username, $password);
    if ($loginResponse !== null && $loginResponse->isTwoFactorRequired()) {
        $twoFactorIdentifier = $loginResponse->getTwoFactorInfo()->getTwoFactorIdentifier();
        
        $verificationCode = readln( 'Inserisci il codice de verificación en dos pasos: ');
        $ig->finishTwoFactorLogin($username, $password, $twoFactorIdentifier, $verificationCode);
    }
} catch (\Exception $e) {
    $response = $e->getResponse();
    
  //  var_dump($exception);
    
    if ($response->getErrorType() === 'checkpoint_challenge_required') { // effettuo la richiesta di challange

        sleep(3);
        $checkApiPath = substr( $response->getChallenge()->getApiPath(), 1);
        echo "path: ".$checkApiPath;
        $customResponse = $ig->request($checkApiPath)->setNeedsAuth(false)->addPost('choice', $verification_method)->addPost('_uuid', $ig->uuid)
        ->addPost('guid', $ig->uuid)->addPost('device_id', $ig->device_id)->addPost('_uid', $ig->account_id)->addPost('_csrftoken', $ig->client->getToken())->getDecodedResponse();
        var_dump($customResponse);
    } else { // non posso risolvere il check point della challange
        echo 'Non riesco a risolvere la pre-challange'.PHP_EOL;
        exit();
    }

    try { // faccio inserire il codice ottenuto per verificare la challange
//         if ($customResponse['status'] === 'ok' && $customResponse['action'] === 'close') {
//             exit();
//         }

        $code = readln( 'Inserisci il codice ricevuto via ' . ( $verification_method ? 'email' : 'sms' ) . ':' );
        $ig->changeUser($username, $password);
        
        $customResponse = $ig->request($checkApiPath)->setNeedsAuth(false)->addPost('security_code', $code)->addPost('_uuid', $ig->uuid)->addPost('guid', $ig->uuid)->addPost('device_id', $ig->device_id)->addPost('_uid', $ig->account_id)->addPost('_csrftoken', $ig->client->getToken())->getDecodedResponse();
        var_dump($customResponse);

    }
    catch ( Exception $ex ) {
        echo "excepcion";
        echo $ex->getMessage();
        var_dump($ex);
        
    }
}

// Create main event loop.
$loop = \React\EventLoop\Factory::create();
if ($debug) {
    $logger = new \Monolog\Logger('rtc');
    $logger->pushHandler(new \Monolog\Handler\StreamHandler('php://stdout', \Monolog\Logger::INFO));
} else {
    $logger = null;
}
// Create HTTP server along with Realtime client.
new InstagramConnector($loop, $ig, $logger,$httpServerport, $urlNotif);
// Run main loop.
$loop->run();

class InstagramConnector
{
    const HOST = '127.0.0.1';
    
    const TIMEOUT = 5;

    /** @var \React\Promise\Deferred[] */
    protected $_contexts;

    /** @var \React\EventLoop\LoopInterface */
    protected $_loop;

    /** @var \InstagramAPI\Instagram */
    protected $_instagram;

    /** @var \InstagramAPI\Realtime */
    protected $_rtc;

    /** @var \React\Http\Server */
    protected $_server;

    /** @var \Psr\Log\LoggerInterface */
    protected $_logger;
    
    protected $_httpServerport;
    
    protected $_urlNotif;
    
    private  $_profiles;

    /**
     * Constructor.
     *
     * @param \React\EventLoop\LoopInterface $loop
     * @param \InstagramAPI\Instagram        $instagram
     * @param \Psr\Log\LoggerInterface|null  $logger
     * @param string $httpServerport
     * @param string $urlNotif
     */
    public function __construct(
        \React\EventLoop\LoopInterface $loop,
        \InstagramAPI\Instagram $instagram,
        \Psr\Log\LoggerInterface $logger = null,
        string $httpServerport,
        string $urlNotif)
    {
        $this->_profiles = [];
        $this->_loop = $loop;
        $this->_instagram = $instagram;
        if ($logger === null) {
            $logger = new \Psr\Log\NullLogger();
        }
       
        $this->_loop->addTimer(30, [$this, 'onTimer']);
        

        $this->_httpServerport = $httpServerport;
        $this->_urlNotif = $urlNotif;
        $this->_logger = $logger;
        $this->_contexts = [];
        $this->_rtc = new \InstagramAPI\Realtime($this->_instagram, $this->_loop, $this->_logger);
        $this->_rtc->on('error', [$this, 'onRealtimeFail']);
        $this->_rtc->on('thread-item-created', [$this, 'onMessage']);
               
        $this->_rtc->start();
        
        
        $this->_startHttpServer();
    }

    /**
     * Gracefully stop everything.
     */
    protected function _stop()
    {
        // Initiate shutdown sequence.
        $this->_rtc->stop();
        // Wait 2 seconds for Realtime to shutdown.
        $this->_loop->addTimer(2, function () {
            // Stop main loop.
            $this->_loop->stop();
        });
    }
    
    private function callCURL($post){
        
        $ch = curl_init($this->_urlNotif);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($post))
            );
        
        $result = curl_exec($ch);
        curl_close($ch);
        
        $this->_logger->Info($result);
    }
    
    public function onTimer(){

        $this->_loop->addTimer(rand(30,60), [$this, 'onTimer']);
        
        $pendingInbox=$this->_instagram->direct->getPendingInbox();
        //       var_dump($peticiones);
        //         $peticiones->getInbox()->getThreads()[0]->getThreadId();
        $threads=$pendingInbox->getInbox()->getThreads();
        $threadIds=[];
        foreach ($threads as $thread)
            $threadIds[]=$thread->getThreadId();
        $this->_logger->Info("Pending count: ".count($threadIds));
        if (count($threadIds)>0){
            $this->_instagram->direct->approvePendingThreads($threadIds);
            var_dump($threadIds);
            sleep(5);
            $direct = $this->_instagram->direct->getInbox();
            $threads = $direct->getInbox()->getThreads();
            foreach ($threads as $thread){
                var_dump($thread->getThreadId());
                if (in_array($thread->getThreadId(),$threadIds)){
                    var_dump($thread->getItems());
                    foreach ($thread->getItems() as $itemThread){
                        var_dump($itemThread);
                        $this->onMessage($thread->getThreadId(),$itemThread->getItemId(),$itemThread);
                    }
                }
            }
               
        }
    }
    
    public function onMessage($threadId, $threadItemId, DirectThreadItem $msgData){
        try {
           // var_dump($msgData);
            $profile = $this->getProfileData($msgData->getUserId());
            $res = [];
            $res['threadId'] =  $threadId;
            $res['threadItemId'] = $threadItemId;
            $res['userId'] = $msgData->getUserId();
            $res['itemTypeInstagram'] = $msgData->getItemType();
            $res['reciverUsername'] = $this->_instagram->username;
            $res['userName'] = $profile['userName'];
            $res['name'] = $profile['name'];
            
            switch ($msgData->getItemType()){
                case 'text':
                    $res['type'] = 'text';
                    $res['text'] = $msgData->getText();
                    break;
                case 'link':
                    $res['type'] = 'url';
                    $res['url'] = $msgData->getLink()->getText();
                    $res['title'] = $msgData->getLink()->getLinkContext()->getLinkTitle();
                    $res['summary'] = $msgData->getLink()->getLinkContext()->getLinkSummary();
                    break;
                case 'like':
                    $res['type'] = 'text';
                    $res['text'] = $msgData->getLike();
                    break;
                case 'media':
                    $res['mediaTypeInstagram'] = $msgData->getMedia()->getMediaType();
                    if($msgData->getMedia()->getMediaType() == 1){
                        $res['type'] = 'image';
                        $res['url'] = $msgData->getMedia()->getImageVersions2()->getCandidates()[0]->getUrl();
                    }else{
                        $res['type'] = 'video';
                        $res['url'] = $msgData->getMedia()->getVideoVersions()[0]->getUrl();
                    }
                    break;
                case 'raven_media':
                    //lo tengo que llamar de esta manera y no con get porque no lo convierte a objeto
                    $res['mediaTypeInstagram'] = $msgData->getVisualMedia()['media']['media_type'];
                    if( $msgData->getVisualMedia()['media']['media_type'] == 1){
                        $res['type'] = 'image';
                        $res['url'] = $msgData->getVisualMedia()['media']['image_versions2']['candidates'][0]['url'];
                    }else{
                        $res['type'] = 'video';
                        $res['url'] = $msgData->getVisualMedia()['media']['video_versions'][0]['url'];
                    }
                    break;
                case 'voice_media':
                    $res['type'] = 'audio';
                    $res['url'] = $msgData->getVoiceMedia()->getMedia()->getAudio()->getAudioSrc();
                    break;
                case 'animated_media':
                    $res['type'] = 'sticker';
                    $res['url'] = $msgData->getAnimatedMedia()->getImages()->getFixedHeight()->getUrl();
                    break;
                case 'media_share':
                    $res['type'] = 'media_share';
                    $res['publisherUserName'] = $msgData->getMediaShare()->getUser()->getUsername();
                    $res['text'] = $msgData->getMediaShare()->getCaption()->getText();
                    break;
                case 'story_share':
                    $res['type'] = 'story_share';
                    $res['publisherUserName'] = $msgData->getStoryShare()->getMedia()->getUser()->getUsername();
                    $res['text'] = $msgData->getStoryShare()->getText();
                    break;
                case 'reel_share':
                    $res['type'] = 'reel_share';
                    $res['reel_share_type'] = $msgData->getReelShare()->getType(); //reaction, reply
                    $res['publisherUserName'] = $msgData->getReelShare()->getMedia()->getUser()->getUsername();
                    $res['text'] = $msgData->getReelShare()->getText();
                    break;
                case 'action_log':
                    $res['type'] = 'text';
                    $res['text'] = $msgData->getActionLog()->getDescription();
                    break;
                    
                default: 
                    $res['type'] = 'error';
                    $res['text'] = "unsuported:\n".var_export($msgData,true);
            }
                      
            $strJson = json_encode($res);
            $this->_logger->info($strJson);
            $this->callCURL($strJson);
                    
        } catch (Exception $e) {
            $res['type'] = 'error';
            $res['text'] = "exception:\n".var_export($msgData,true);
            $strJson = json_encode($res);
            $this->_logger->info($strJson);
            $this->callCURL($strJson);
            $this->_logger->error((string) $e);
        }
    }
    
    private function getProfileData($userId){
        if(!isset( $this->_profiles[$userId])){
            $info = $this->_instagram->people->getInfoById($userId);
            $this->_profiles[$userId]['userName'] = $info->getUser()->getUsername();
            $this->_profiles[$userId]['name'] = $info->getUser()->getFull_name();
        }
               
        return $this->_profiles[$userId];
    }
   

    /**
     * Called when fatal error has been received from Realtime.
     *
     * @param \Exception $e
     */
    public function onRealtimeFail(
        \Exception $e)
    {
        $this->_logger->error((string) $e);
        $this->_stop();
    }

    /**
     * @param string|bool $context
     *
     * @return \React\Http\Response|\React\Promise\PromiseInterface
     */
    protected function _handleClientContext(
        $context)
    {
        // Reply with 503 Service Unavailable.
        if ($context === false) {
            return new \React\Http\Response(503);
        }
        // Set up deferred object.
        $deferred = new \React\Promise\Deferred();
        $this->_contexts[$context] = $deferred;
        // Reject deferred after given timeout.
        $timeout = $this->_loop->addTimer(self::TIMEOUT, function () use ($deferred, $context) {
            $deferred->reject();
            unset($this->_contexts[$context]);
        });
        // Set up promise.
        return $deferred->promise()
            ->then(function (\InstagramAPI\Realtime\Payload\Action\AckAction $ack) use ($timeout) {
                // Cancel reject timer.
                $timeout->cancel();
                // Reply with info from $ack.
                return new \React\Http\Response($ack->getStatusCode(), ['Content-Type' => 'text/json'], $ack->getPayload()->asJson());
            })
            ->otherwise(function () {
                // Called by reject timer. Reply with 504 Gateway Time-out.
                return new \React\Http\Response(504);
            });
    }

    /**
     * Handler for incoming HTTP requests.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     *
     * @return \React\Http\Response|\React\Promise\PromiseInterface
     */
    public function onHttpRequest(
        \Psr\Http\Message\ServerRequestInterface $request)
    {
        // Treat request path as command.
        $command = $request->getUri()->getPath();
        // Params validation is up to you.
        $params = $request->getQueryParams();
        
        // Log command with its params.
        $this->_logger->info(sprintf('Received command %s', $command), $params);
        switch ($command) {
            case '/ping':
                return new \React\Http\Response(200, [], 'pong');
            case '/stop':
                $this->_stop();
                return new \React\Http\Response(200);
            case '/seen':
                $context = $this->_rtc->markDirectItemSeen($params['threadId'], $params['threadItemId']);
                return new \React\Http\Response($context !== false ? 200 : 503);
            case '/message':
                return $this->_handleClientContext($this->_rtc->sendTextToDirect($params['threadId'], $request->getParsedBody()['text']));
               
            default:
                $this->_logger->warning(sprintf('Unknown command %s', $command), $params);
                // If command is unknown, reply with 404 Not Found.
                return new \React\Http\Response(404);
        }
    }

    /**
     * Init and start HTTP server.
     */
    protected function _startHttpServer()
    {
        // Create server socket.
        $socket = new \React\Socket\Server(self::HOST.':'.$this->_httpServerport, $this->_loop);
        $this->_logger->info(sprintf('Listening on http://%s', $socket->getAddress()));
        // Bind HTTP server on server socket.
        $this->_server = new \React\Http\Server([$this, 'onHttpRequest']);
        $this->_server->listen($socket);
    }
}
