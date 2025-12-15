<?php

namespace AsyncCurl;

/**
 * Clase de la solicitud en proceso
 *
 * Repositorio {@link https://github.com/yordanny90/AsyncCurl}
 */
class Request{
    private $curl;
    /**
     * @var Response|null
     */
    private $result;
    /**
     * @var string
     */
    private $headers='';
    private $stream;
    private $start;
    /**
     * @var Agent
     */
    private $man;
    private $key=0;
    private $url;
    private $method;

    public function __construct(Agent $manager, $curl, string $method, string $url, $stream=null){
        $this->man=$manager;
        $this->start=time();
        $this->method=$method;
        $this->url=$url;
        $added=$this->man->addCurl($curl, $this);
        if(!$added) return;
        $this->key=$added;
        curl_setopt($curl, CURLOPT_HEADERFUNCTION, Agent::headerFn($this->headers));
        $this->curl=$curl;
        if(is_resource($stream)) $this->stream=$stream;
        $this->man->ready();
    }

    public function __destruct(){
        $this->stop();
    }

    public function is_running(float $timeout=0.0){
        if(!$this->curl) return false;
        if(!$this->man->existsCurl($this->key, $this->curl) || !($active=$this->man->ready($timeout))){
            $this->stop(false);
            return false;
        }
        return !!$active && !!$this->curl;
    }

    /**
     * @param float $timeout Tiempo de espera máximo
     * @return bool
     * @see Request::is_running()
     */
    public function wait(float $timeout=10.0){
        $max=microtime(true)+max($timeout, 0);
        if(!$this->curl) return false;
        do{
            $t=max($max-microtime(true), 0);
            $running=$this->is_running($t);
            if(!$running) return false;
        }while($t>0);
        return $running;
    }

    /**
     * @return Response|null
     */
    public function response(){
        $this->is_running();
        return $this->result;
    }

    /**
     * Aborta/detiene el request actual si aun está en ejecución (Ver {@see Request::is_running()})
     * @return $this
     */
    public function &stop(bool $ready=true){
        if(!$this->curl){
            return $this;
        }
        $curl=$this->curl;
        $this->curl=null;
        if($ready) $this->man->ready();
        $aborted=$this->man->existsCurl($this->key, $curl);
        $errorRemove=$this->man->removeCurl($this->key, $curl);
        $content=curl_multi_getcontent($curl);
        if(is_resource($this->stream)) $content=$this->stream;
        $this->stream=null;
        $info=curl_getinfo($curl);
        $errno=curl_errno($curl);
        $error=curl_error($curl);
        curl_close($curl);
        $this->result=new Response($this->start, $this->method, $this->url, $info, $content, $this->headers, $errno, $error, $aborted);
        $this->headers='';
        return $this;
    }

    public function execution_time(){
        if($this->is_running()){
            $info=curl_getinfo($this->curl);
            return $info['total_time'] ?? null;
        }
        return null;
    }
}