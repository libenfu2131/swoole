<?php
class Server
{
    private $serv;
    private $redis;
    public function __construct() {
        $this->serv = new swoole_server("0.0.0.0", 9501);
        $this->serv->set(array(
            'ractor_num'    => 2,    //主进程中线程数量
            'worker_num' => 4,   //一般设置为服务器CPU数的1-4倍
            'daemonize' => false,   //是否守护进程
            'max_request' => 10000,
            'dispatch_mode' => 2,  //1平均分配，2按FD取摸固定分配，3抢占式分配，默认为取模(dispatch=2)'
            'debug_mode'=> 1,
            'task_worker_num' => 10,  //task进程的数量
            "task_ipc_mode " => 3 ,  //使用消息队列通信，并设置为争抢模式
            "log_file" => "./MailSwoole.log" ,//日志
        ));
        include_once('./RedisCluster.php');
        $this->redis = new RedisCluster();
        $this->redis->connect(array('host'=>'172.16.0.126','port'=>6379));
	    $this->serv->on('Start', array($this, 'onStart'));
        $this->serv->on('Connect', array($this, 'onConnect'));
        $this->serv->on('Receive', array($this, 'onReceive'));
        // bind callback
        $this->serv->on('Task', array($this, 'onTask'));
        $this->serv->on('Finish', array($this, 'onFinish'));
        $this->serv->start();
    }
    public function onStart( $serv ) {
        echo "Start\n";
    }
    public function onConnect( $serv, $fd, $from_id ) {
        echo "Client {$fd} connect  form_id {$from_id}\n";
    }
    public function onReceive( $serv, $fd, $from_id, $data ) {
        $this->redis->set('startTime',time());
        file_put_contents('./data_Receive',"1\n", FILE_APPEND);
        $serv->task( $data ); 
    }
    public function onTask($serv,$task_id,$from_id, $data) {
        $json = $this->redis->lranges('orderTask',0,1);
        $arr = json_decode($json[0],true);
        if( $data == 'begin'){
            $this->redis->set('page',0);
            $first = $arr[0];
            if(!$first){
                return;
            }
            $data = json_encode( $first );
        }

        file_put_contents('./data_Task',"{$data}\n", FILE_APPEND);

        $data = json_decode( $data,true );
        if( $data['url'] ){
            $res = $this->httpGet($data['url'],$data['param']);
            file_put_contents('./data_error','执行结果---:'.$res."\n", FILE_APPEND);
            $this->redis->incr('page');
            $nextxh = $this->redis->get('page');
            $length = count($arr);
            if($nextxh == $length){
                return true;
            }
            $next = $arr[$nextxh];
            if($next){
                $client = new \swoole_client(SWOOLE_SOCK_TCP);
                if(!$client->connect("127.0.0.1",9501,1)){
                    echo "Connect Error";
                }
                $sendRes = $client->send( json_encode( $next ) );
                file_put_contents('./data_error','client-send的结果:'.$sendRes.'x---'."\n", FILE_APPEND);
            }else{
                $start = $this->redis->get('startTime');
                $end = time() - $start;
                $usetime = ($end)/60;
                file_put_contents('./data_error',"任务执行完毕,使用分数:{$usetime}m,秒数:{$end}s\n", FILE_APPEND);
            }
            // return $res;
        }else{
            file_put_contents('./data_error',"参数格式错误\n", FILE_APPEND);
        }
    }
    public function onFinish($serv,$task_id, $data) {
    }
    protected function httpGet($url,$data){
	    return json_encode( array('status'=>rand(1,1000),'msg'=>'请求curl执行结果') );
        if ($data) {
            $url .='?'.http_build_query($data) ;
        }
        $curlObj = curl_init();    //初始化curl，
        curl_setopt($curlObj, CURLOPT_URL, $url);   //设置网址
        curl_setopt($curlObj, CURLOPT_RETURNTRANSFER, 1);  //将curl_exec的结果返回
        curl_setopt($curlObj, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curlObj, CURLOPT_SSL_VERIFYHOST, FALSE);   
        curl_setopt($curlObj, CURLOPT_HEADER, 0);         //是否输出返回头信息
        $response = curl_exec($curlObj);   //执行
        curl_close($curlObj);          //关闭会话
        return $response;
    }
}
$server = new Server();
