<?php

namespace AsyncCurl;

if(!defined('ASYNCCURL_BASE_VERSION')) define('ASYNCCURL_BASE_VERSION', curl_version()['version'] ?? '0');

/**
 * Clase para el consumo CURL
 * PHP: +7.4, +8.0
 */
class Agent{
    const APP_VERSION='1.1';
    const APP_NAME='PHPEasyCurl';
    const APP_USERAGENT=('curl/'.ASYNCCURL_BASE_VERSION.' '.self::APP_NAME.'/'.self::APP_VERSION);
    const CT_JSON='application/json';
    const CT_FORM_URLENCODED='application/x-www-form-urlencoded';
    const CT_FORM_DATA='multipart/form-data';
    const CT_PLAIN='text/plain';
    const CT_XML='application/xml';
    const CT_OCTET_STREAM='application/octet-stream';
    const STATUS_GROUP=[
        1=>'Information',
        2=>'Success',
        3=>'Redirect',
        4=>'Client Error',
        5=>'Server Error',
    ];
    const STATUS_CODE=[
        100=>'Continue',
        101=>'Switching Protocols',
        102=>'Processing',
        103=>'Checkpoint',
        200=>'OK',
        201=>'Created',
        202=>'Accepted',
        203=>'Non-Authoritative Information',
        204=>'No Content',
        205=>'Reset Content',
        206=>'Partial Content',
        207=>'Multi-Status',
        208=>'Already Reported',
        226=>'IM Used',
        300=>'Multiple Choices',
        301=>'Moved Permanently',
        302=>'Found',
        303=>'See Other',
        304=>'Not Modified',
        305=>'Use Proxy',
        306=>'Switch Proxy',
        307=>'Temporary Redirect',
        308=>'Permanent Redirect',
        400=>'Bad Request',
        401=>'Unauthorized',
        402=>'Payment Required',
        403=>'Forbidden',
        404=>'Not Found',
        405=>'Method Not Allowed',
        406=>'Not Acceptable',
        407=>'Proxy Authentication Required',
        408=>'Request Timeout',
        409=>'Conflict',
        410=>'Gone',
        411=>'Length Required',
        412=>'Precondition Failed',
        413=>'Content Too Large',
        414=>'URI Too Long',
        415=>'Unsupported Media Type',
        416=>'Range Not Satisfiable',
        417=>'Expectation Failed',
        418=>'I\'m a teapot',
        421=>'Misdirected Request',
        422=>'Unprocessable Content',
        423=>'Locked',
        424=>'Failed Dependency',
        425=>'Too Early',
        426=>'Upgrade Required',
        428=>'Precondition Required',
        429=>'Too Many Requests',
        431=>'Request Header Fields Too Large',
        451=>'Unavailable For Legal Reasons',
        500=>'Internal Server Error',
        501=>'Not Implemented',
        502=>'Bad Gateway',
        503=>'Service Unavailable',
        504=>'Gateway Timeout',
        505=>'HTTP Version Not Supported',
        506=>'Variant Also Negotiates',
        507=>'Insufficient Storage',
        508=>'Loop Detected',
        509=>'Bandwidth Limit Exceeded',
        510=>'Not Extended',
        511=>'Network Authentication Required',
        512=>'Not updated',
        521=>'Version Mismatch',
    ];

    private static $defaultOptions=[
        CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_TIMEOUT=>60,
        CURLOPT_USERAGENT=>self::APP_USERAGENT,
        // Activa todas las codificaciones soportadas (gzip, deflate, br, ...)
        CURLOPT_ENCODING=>'',
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_MAXREDIRS=>10,
    ];

    private $cfg=[
        'toStream'=>false,
        'charset'=>'utf-8',
    ];
    private $security=[
        'user'=>'',
        'pass'=>'',
    ];
    private $options=[];
    private $headers=[];
    private $multi_handle;

    function __construct(string $uri='', $user=null, $pass=null){
        $this->set_charset(ini_get('default_charset'))->setUri($uri)->setUser($user)->setPassword($pass);
        $this->multi_handle??=curl_multi_init();
    }

    /**
     * Agrega opciones de {@see curl_multi_setopt()}
     * @param $option
     * @param $value
     * @return bool
     */
    public function multi_setopt(int $option, $value){
        return curl_multi_setopt($this->multi_handle, $option, $value);
    }

    public function &saveToStream(bool $val=true){
        $this->cfg['toStream']=$val;
        return $this;
    }

    /**
     * Crea un archivo basado en un string para enviarlo por un request
     * @param string $data
     * @param string $postname
     * @param string $mime
     * @return StringFile
     */
    static function curl_string_file(string $data, string $postname, string $mime='application/octet-stream'){
        return new StringFile($data, $postname, $mime);
    }

    /**
     * Genera un UserAgent por defecto a partir del inicial {@see Agent::APP_USERAGENT}
     * @param string $appURL URL del sistema que se incluye en el UserAgent para su identificación
     * @param string $extras Datos adicionales que se agregan al final de del UserAgent
     * @return void
     */
    static function setDefaultAppUserAgent(string $appURL, string $extras=''){
        $ua=self::APP_USERAGENT;
        if($appURL!='') $ua=trim($ua.' (+'.$appURL.')');
        if($extras!='') $ua=trim($ua.' '.$extras);
        self::setDefaultOption(CURLOPT_USERAGENT, $ua);
    }

    /**
     * Default: NONE
     * @param bool $verify
     * @return void
     */
    static function setDefaultVerifyPeer(bool $verify){
        self::setDefaultOption(CURLOPT_SSL_VERIFYPEER, $verify);
    }

    /**
     * Default: 60
     * @param int $timeout
     * @return void
     */
    static function setDefaultTimeout(int $timeout){
        self::setDefaultOption(CURLOPT_TIMEOUT, $timeout);
    }

    /**
     * Default: 10
     * @param int $timeout
     * @return void
     */
    static function setDefaultConnectTimeout(int $timeout){
        self::setDefaultOption(CURLOPT_CONNECTTIMEOUT, $timeout);
    }

    /**
     * Default: 10
     * @param int $max
     * @return void
     */
    static function setDefaultMaxRedirs(int $max){
        self::setDefaultOption(CURLOPT_MAXREDIRS, $max);
    }

    /**
     * Establece opciones curl por defecto para todos los request
     *
     * Ver parametros de {@see curl_setopt()}
     * @param int $option
     * @param $value
     * @return void
     */
    static function setDefaultOption(int $option, $value){
        self::$defaultOptions[$option]=$value;
    }

    static function getDefaultOption(int $option){
        return self::$defaultOptions[$option] ?? null;
    }

    static function delDefaultOption(int $option){
        unset(self::$defaultOptions[$option]);
    }

    /**
     * @param float|null $version Posibles valores: null, 0, 1, 1.1, 2, 3
     * @return bool|void
     */
    function setHttpVersion(?float $version){
        if(!defined('CURLOPT_HTTP_VERSION')) return false;
        if($version===null){
            $this->addOption(CURLOPT_HTTP_VERSION, null);
            return true;
        }
        if($version==0){
            if(!defined('CURL_HTTP_VERSION_NONE')) return false;
            $this->addOption(CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_NONE);
            return true;
        }
        if($version==1){
            if(!defined('CURL_HTTP_VERSION_1_0')) return false;
            $this->addOption(CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0);
            return true;
        }
        if($version==1.1){
            if(!defined('CURL_HTTP_VERSION_1_1')) return false;
            $this->addOption(CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            return true;
        }
        if($version==2){
            if(!defined('CURL_HTTP_VERSION_2_0')) return false;
            $this->addOption(CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
            return true;
        }
        if($version==3){
            if(!defined('CURL_HTTP_VERSION_3')) return false;
            $this->addOption(CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_3);
            return true;
        }
        return false;
    }

    function &setUri(string $uri){
        $this->addOption(CURLOPT_URL, $uri);
        return $this;
    }

    function getUri(){
        return $this->getOption(CURLOPT_URL);
    }

    function &setUser(?string $user){
        $this->security['user']=strval($user);
        return $this;
    }

    function &setPassword(?string $pass){
        $this->security['pass']=strval($pass);
        return $this;
    }

    function getHeaders(){
        return $this->headers;
    }

    function getHeader($key){
        return $this->headers[$key];
    }

    function &delHeader($key){
        unset($this->headers[$key]);
        return $this;
    }

    function &addHeader($key, $value, bool $replace=true){
        if(!is_null($value)){
            if($replace){
                $this->headers[$key]=$value;
            }
            else{
                $this->headers[]=trim($key).': '.$value;
            }
        }
        return $this;
    }

    function &addHeaders(array $headers){
        foreach($headers as $k=>&$v){
            $this->addHeader($k, $v);
        }
        return $this;
    }

    function getOptions(){
        return $this->options;
    }

    function getOption($key){
        return $this->options[$key] ?? null;
    }

    function &delOption($key){
        unset($this->options[$key]);
        return $this;
    }

    function &addOption($key, $value){
        $this->options[$key]=&$value;
        return $this;
    }

    function &addOptions(array $options){
        foreach($options as $k=>&$v){
            $this->addOption($k, $v);
        }
        return $this;
    }

    function get_charset(){
        return $this->cfg['charset'];
    }

    /**
     * Asigna la codificación de caracteres que se utilizará en el request de la API
     * @param string $charset
     * @return $this
     */
    function &set_charset(string $charset){
        $this->cfg['charset']=$charset;
        return $this;
    }

    public static function headerFn(string &$headers=''){
        $headers='';
        $redir=0;
        return function($ch, $line) use (&$headers, &$last, &$redir){
            if(curl_getinfo($ch, CURLINFO_REDIRECT_COUNT)!=$redir){
                $redir=curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
                $headers='';
            }
            $headers.=$line;
            return strlen($line);
        };
    }

    static function toFields($data, $sup=null){
        $res=[];
        if($data && (is_array($data) || is_object($data))){
            foreach($data as $k=>$v){
                if(is_string($sup) && strlen($sup)) $k=$sup.'['.$k.']';
                if(!is_array($v) && !is_object($v)){
                    $res[$k]=$v;
                }
                elseif(self::validValue($v)){
                    $res[$k]=$v;
                }
                else{
                    $res=array_merge($res, self::toFields($v, $k));
                }
            }
        }
        return $res;
    }

    static function validValue($value){
        return is_scalar($value) || is_a($value, CURLFile::class) || is_a($value, CURLStringFile::class);
    }

    /**
     * Genera las opciones CURL para el recurso curl
     * @param null|string $method Si se indica, establece el method de la petición (GET, POST, PUT, DELETE, HEAD, ...). Si no se especifica, se utiliza GET o POST según los datos que se envíen
     * @param array|object|string|null $paramGET Parámetros GET que se envian en la solicitud. Si es array, se generará el string para concatenarlo a la URL.
     * @param string|null $contentType Default: {@see Agent::CT_FORM_URLENCODED}. El data se convierte automáticamente a si usa {@see Agent::CT_JSON}, {@see Agent::CT_FORM_URLENCODED}, o {@see Agent::CT_FORM_DATA}
     * @param array|object|string|null $data Se recomienda un array/object.
     *
     * Para enviar archivos adjuntos se usa {@see curl_file_create()}, {@see CURLFile} y {@see CURLStringFile}
     * y el contentType debe ser {@see Agent::CT_FORM_DATA}
     * @param array|null $addHeaders Headers adicionales para este request. No se conservan para otros request
     * @param array|null $addOpts Opciones CURL adicionales para este request. No se conservan para otros request
     * @return int[]|null Opciones para CURL
     */
    private function prepare_curl_options(string $method, ?string $url_endpoint, $paramGET=null, ?string $contentType=null, $data=null, ?array $addHeaders=null, ?array $addOpts=null){
        if(is_array($data) || is_object($data)){
            $contentType=strtolower($contentType ?? self::CT_FORM_URLENCODED);
            if($contentType==self::CT_FORM_URLENCODED){
                $data=http_build_query($data);
            }
            elseif($contentType==self::CT_JSON){
                $data=json_encode($data);
                if($data===false) return null;
            }
            else{
                $data=self::toFields($data);
            }
        }
        $contentType??=self::CT_OCTET_STREAM;
        $addHeaders??=[];
        $addOpts??=[];
        $addHeaders['Content-Type']=$contentType.($this->cfg['charset']?'; charset='.$this->cfg['charset']:'');
        if(is_string($data)){
            $addHeaders['Content-Length']=strlen($data);
        }
        $headers=array_merge($this->headers, $addHeaders);
        foreach($headers as $k=>$v){
            if(isset($v['name']) && isset($v['value'])) $headers[$k]=trim($v['name']).': '.$v['value'];
            elseif(is_string($k)) $headers[$k]=trim($k).': '.$v;
        }
        $headers=array_values($headers);
        $opts=self::$defaultOptions;
        foreach($this->options as $k=>$v){
            $opts[$k]=$v;
        }
        foreach($addOpts as $k=>$v){
            $opts[$k]=$v;
        }
        $url=$opts[CURLOPT_URL] ?? '';
        if(is_string($url_endpoint) && $url_endpoint!==''){
            if(preg_match('/^\w+\:\/\/.*/', $url_endpoint)){
                $url=$url_endpoint;
            }
            else{
                $url.=$url_endpoint;
            }
        }
        if(is_array($paramGET) || is_object($paramGET)) $paramGET=http_build_query($paramGET);
        if(is_string($paramGET) && strlen($paramGET)>0){
            $lastCh=substr($url, -1);
            if($lastCh!='?'){
                if(strpos($url, '?')===false) $url.='?';
                elseif($lastCh!='&') $url.='&';
            }
            $url.=$paramGET;
        }
        $opts[CURLOPT_URL]=$url;
        if(!is_null($data)){
            $opts[CURLOPT_POSTFIELDS]=$data;
        }
        if($method==='') $method=$opts[CURLOPT_CUSTOMREQUEST] ?? 'GET';
        $opts[CURLOPT_CUSTOMREQUEST]=$method=strtoupper($method);
        if($method=='HEAD') $opts[CURLOPT_NOBODY]=true;
        $opts[CURLOPT_HTTPHEADER]=$headers;
        if($this->security['user'] || $this->security['pass']){
            if(defined('CURLOPT_USERNAME')){
                $opts[CURLOPT_USERNAME]=$this->security['user'];
                $opts[CURLOPT_USERPWD]=$this->security['pass'];
            }
            else{
                $opts[CURLOPT_USERPWD]=$this->security['user'].':'.$this->security['pass'];
            }
        }
        if($this->cfg['toStream']){
            $opts[CURLOPT_RETURNTRANSFER]=false;
            $opts[CURLOPT_FILE]=tmpfile();
            if(!$opts[CURLOPT_FILE]) return null;
        }
        else{
            $opts[CURLOPT_RETURNTRANSFER]=true;
            unset($opts[CURLOPT_FILE]);
        }
        $opts=array_filter($opts, function($a){ return !is_null($a); });
        return $opts;
    }

    /**
     * Realiza el request asíncrono y devuelve un objeto que esperar la respuesta
     *
     * Ver parámetros de {@see Agent::prepare_curl_options()}
     * @param string|null $method
     * @param array|object|string|null $paramGET
     * @param string|null $contentType
     * @param array|object|string|null $data
     * @param array|null $addHeaders
     * @param array|null $addOpts
     * @return Request
     */
    function &request(string $method='GET', string $url_endpoint='', $paramGET=null, ?string $contentType=null, $data=null, ?array $addHeaders=null, ?array $addOpts=null){
        $curl=curl_init();
        $opts=$this->prepare_curl_options($method, $url_endpoint, $paramGET, $contentType, $data, $addHeaders, $addOpts);
        $method=$opts[CURLOPT_CUSTOMREQUEST];
        $url=$opts[CURLOPT_URL];
        curl_setopt_array($curl, $opts);
        $res=new Request($this, $curl, $method, $url, $opts[CURLOPT_FILE] ?? null);
        return $res;
    }

    /**
     * @param float $timeout
     * @param $curl
     * @return bool|null
     */
    public function ready(float $timeout=0.0){
        if(!$this->multi_handle) return null;
        $max=microtime(true)+max(0, $timeout);
        $out=false;
        do{
            $t=max(0, $max-microtime(true));
            $active=$this->exec_select($t);
            while($info=curl_multi_info_read($this->multi_handle, $msg)){
                if($info['msg']===CURLMSG_DONE){
                    $key=array_search($info['handle'], $this->list_curl, true);
                    if(is_int($key)){
                        $out=true;
                        $this->removeCurl($key, $info['handle']);
                    }
                }
            }
            if($out || $t<=0 || microtime(true)>=$max) break;
            usleep(1000);
        } while($active && $this->countCurl());
        return $active;
    }

    private function exec_select(float $timeout=1.0){
        if(!$this->multi_handle) return false;
        $timeout=max($timeout, 0);
        $ok=CURLM_OK===curl_multi_exec($this->multi_handle, $active);
        if($active || $ok){
            $desc=curl_multi_select($this->multi_handle, $timeout);
            if($desc!==0){
                $desc+=0;
            }
        }
        return $active;
    }

    private $list_key=0;
    private $list_curl=[];
    /**
     * @var Request[]
     */
    private $list_request=[];

    /**
     * @return int
     */
    public function countCurl(): int{
        return count($this->list_curl);
    }

    /**
     * @param $curl
     * @param Request $request
     * @return int|null
     */
    public function addCurl(&$curl, Request &$request){
        if(!$this->multi_handle) return null;
        $error_add=curl_multi_add_handle($this->multi_handle, $curl);
        if($error_add!==0) return null;
        $key=++$this->list_key;
        $this->list_curl[$key]=&$curl;
        $this->list_request[$key]=&$request;
        return $key;
    }

    /**
     * @param int $key
     * @param $curl
     * @return bool
     */
    public function existsCurl(int $key, $curl): bool{
        return $curl!==null && $curl===($this->list_curl[$key] ?? null);
    }

    /**
     * @param $curl
     * @return int|null Si se encuentra el curl, devuelve el resultado de {@see curl_multi_remove_handle()}
     */
    public function removeCurl(int $key, $curl): ?int{
        if(!$this->multi_handle) return null;
        if(!$this->existsCurl($key, $curl)) return null;
        $error_code=curl_multi_remove_handle($this->multi_handle, $curl);
        if(!$error_code){
            $async=$this->list_request[$key] ?? null;
            unset($this->list_curl[$key], $this->list_request[$key]);
            if($async){
                $async->stop(false);
            }
        }
        return $error_code;
    }

}

class StringFile extends \CURLFile{
    protected $stream;

    public function __construct(string $data, ?string $postname=null, string $mime = 'application/octet-stream') {
        $res=tmpfile();
        if($res && ($filename=stream_get_meta_data($res)['uri'] ?? null)){
            if(fwrite($res, $data)!==false){
                fflush($res);
                parent::__construct($filename, $mime, $postname);
                $this->stream=$res;
                return;
            }
        }
        parent::__construct('data:'.$mime.','.$data, $mime, $postname);
    }
}
