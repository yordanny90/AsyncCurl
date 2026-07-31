<?php

namespace AsyncCurl;

/**
 * Respuesta de un request
 *
 * - {@see Response::$success} es la forma correcta de comprobar si el resultado es existoso
 * - Aunque el código de {@see Response::http_code()} sea exitoso, pero {@see Response::$aborted} puede ser TRUE
 *
 * Repositorio {@link https://github.com/yordanny90/AsyncCurl}
 */
class Response{
    /**
     * @var string|null
     */
    private $content;
    /**
     * @var resource|null
     */
    private $stream;
    /**
     * Indica que el request fue abortado antes de tiempo.
     *
     * Es posible obtener un 2xx de {@see Response::http_code()}, pero el request se pudo abortar antes de descargar la respuesta completa
     * @var bool
     */
    private $aborted;
    /**
     * @var bool Indica una respuesta recibiba sin errores
     */
    private $success;
    /**
     * @var int
     */
    private $errno;
    /**
     * @var string|null
     */
    private $error;
    /**
     * @var string
     */
    private $headers;
    /**
     * @var array
     */
    private $info;
    /**
     * @var int Tiempo unix del inicio del request
     */
    private $start;
    /**
     * @var string Method original
     */
    private $origin_method;
    /**
     * @var string URL original
     */
    private $origin_url;
    /**
     * @var array Opciones CURL finales que se enviaron en el request (equivalente a last_CURL_OPTIONS de API_helper)
     */
    private $options;

    public function __construct(int $start, string $method, string $url, array $info, $content, string $headers, int $errno, string $error, bool $aborted=false, array $options=[]){
        $this->start=$start;
        $this->origin_method=$method;
        $this->origin_url=$url;
        $this->info=$info;
        $this->options=$options;
        if(is_string($content)) $this->content=$content;
        if(is_resource($content)) $this->stream=$content;
        $this->headers=$headers;
        $this->aborted=$aborted;
        $this->errno=$errno;
        $this->error=$error;
        $this->success=(!$this->aborted && $this->errno===0 && $this->statusGroup()=='Success');
    }

    public function __destruct(){
        $this->close();
    }

    /**
     * Libera el stream temporal (si {@see \AsyncCurl\Agent::saveToStream()} estaba activo).
     * Llamarlo explicitamente al terminar de leer el contenido en corridas con muchas
     * respuestas retenidas en memoria (ej. batch/cron), para no acumular file descriptors
     * abiertos hasta que el garbage collector destruya el objeto.
     * @return void
     */
    public function close(): void{
        if(is_resource($this->stream)){
            fclose($this->stream);
        }
        $this->stream=null;
    }

    /**
     * @return bool
     */
    public function isAborted(): bool{
        return $this->aborted;
    }

    /**
     * @return bool
     */
    public function isSuccess(): bool{
        return $this->success;
    }

    /**
     * @return int
     */
    public function getStart(): int{
        return $this->start;
    }

    /**
     * @return array
     */
    public function getInfo(): array{
        return $this->info;
    }

    /**
     * Opciones CURL finales que se enviaron en este request (debug/logging/auditoria)
     * @return array
     */
    public function getRequestOptions(): array{
        return $this->options;
    }

    /**
     * @return string
     */
    public function getHeaders(): string{
        return $this->headers;
    }

    /**
     * @return int
     */
    public function getErrno(): int{
        return $this->errno;
    }

    /**
     * @return string|null
     */
    public function getError(): ?string{
        return $this->error;
    }

    /**
     * @return string|null
     */
    function url(){
        return $this->info['url'] ?? null;
    }

    /**
     * @return string
     */
    public function getOriginMethod(): string{
        return $this->origin_method;
    }

    /**
     * @return string
     */
    public function getOriginUrl(): string{
        return $this->origin_url;
    }

    /**
     * @return int
     */
    function http_code(){
        if(!is_numeric($this->info['http_code'] ?? null)) return null;
        return intval($this->info['http_code']);
    }

    /**
     * @return int
     */
    function total_time(){
        if(!is_numeric($this->info['total_time'] ?? null)) return null;
        return $this->info['total_time'];
    }

    /**
     * @return string|null
     */
    function content_type(){
        if(!isset($this->info['content_type'])) return null;
        if(preg_match('/^([^;]*)/i', $this->info['content_type'], $m)){
            return trim($m[1]);
        }
        return null;
    }

    /**
     * @return string|null
     */
    function charset(){
        if(!isset($this->info['content_type'])) return null;
        if(preg_match('/;\s*charset=([^;]*)(\b|;)/i', $this->info['content_type'], $m)){
            return trim($m[1]);
        }
        return null;
    }

    function time_list(){
        $res=[];
        foreach($this->info as $name=>$value){
            if(strpos($name, 'time')!==false) $res[$name]=$value;
        }
        return $res;
    }

    function size_list(){
        $res=[];
        foreach($this->info as $name=>$value){
            if(strpos($name, 'size')!==false) $res[$name]=$value;
        }
        return $res;
    }

    function statusText(){
        if(is_null($c=$this->http_code())) return null;
        return (Agent::STATUS_CODE[$c] ?? null);
    }

    /**
     * @return string|null
     */
    function statusGroup(){
        $c=$this->http_code();
        if(is_int($c) && $c>=100 && $c<600){
            $g=floor($c/100);
            $group=Agent::STATUS_GROUP[$g] ?? null;
            return $group;
        }
        return null;
    }

    /**
     * Si no es JSON, devuelve NULL.
     *
     * Ver parametros de {@see json_decode()}
     * @param bool|null $associative
     * @param int $depth
     * @param int $flags
     * @return mixed|null
     */
    function getJSON(?bool $associative=null, int $depth=512, int $flags=0){
        $content=$this->getContent();
        if(!is_string($content)) return null;
        if(function_exists('json_validate') && !json_validate($content)) return null;
        return json_decode($content, $associative, $depth, $flags);
    }

    /**
     * @param string $name
     * @return string|null
     */
    function header(string $name){
        if(!is_string($this->headers)) return null;
        return self::searchHeader($this->headers, $name);
    }

    /**
     * @param string $name
     * @return array|null
     */
    function header_multi(string $name){
        if(!is_string($this->headers)) return null;
        return self::searchHeader_multi($this->headers, $name);
    }

    /**
     * @return string[]|null
     */
    function getHeaderNames(){
        if(!is_string($this->headers)) return null;
        return self::header_names($this->headers);
    }

    /**
     * Obtiene el contenido de la respuesta exitosa.
     *
     * Devuelve NULL si {@see Response::isSuccess()} es FALSE, incluso cuando el servicio
     * envio un body con el detalle del error. Para leer ese body (log/diagnostico de un
     * 4xx/5xx o de un request abortado) usar {@see Response::content_fail()}
     * @return string|null
     */
    function getContent(){
        if(!$this->success) return null;
        return $this->content_fail();
    }

    /**
     * Solo copia el contenido de una respuesta exitosa: devuelve NULL si
     * {@see Response::isSuccess()} es FALSE. Para copiar el contenido de una respuesta
     * fallida usar {@see Response::copyToStream_fail()}
     * @param resource|null $dest Si no es un resource, se creará un stream que apunta a un archivo temporal
     * @return resource|null
     */
    function copyToStream($dest=null){
        if(!$this->success) return null;
        return $this->copyToStream_fail($dest);
    }

    /**
     * Guarda el resultado en un archivo y aplica el tiempo de modificación si se recibió.
     *
     * Solo guarda una respuesta exitosa: devuelve NULL si {@see Response::isSuccess()} es
     * FALSE. Para guardar el contenido de una respuesta fallida usar
     * {@see Response::saveToFile_fail()}
     * @param string $filename
     * @return int|null
     */
    function saveToFile(string $filename){
        if(!$this->success) return null;
        return $this->saveToFile_fail($filename);
    }

    /**
     * Contenido de la respuesta sin filtrar por exito: devuelve el body incluso si el
     * request fallo o fue abortado. Es la via para loggear la respuesta de un 4xx/5xx
     * @return string|null
     */
    function content_fail(){
        if(is_string($this->content)){
            return $this->content;
        }
        if($this->stream){
            fseek($this->stream, 0);
            $content=stream_get_contents($this->stream);
            if(!is_string($content)) return null;
            return $content;
        }
        return null;
    }

    /**
     * Copia el contenido sin filtrar por exito: copia el body incluso si el request fallo
     * o fue abortado
     * @param resource|null $dest
     * @return resource|null
     */
    function copyToStream_fail(&$dest=null){
        if(!is_resource($dest)){
            $dest=tmpfile();
            if(!is_resource($dest)) return null;
        }
        if(is_string($this->content)){
            $copy=fwrite($dest, $this->content);
            fseek($dest, 0);
            if($copy===false) return null;
            return $dest;
        }
        if($this->stream){
            fseek($this->stream, 0);
            $copy=stream_copy_to_stream($this->stream, $dest);
            fseek($dest, 0);
            if($copy===false) return null;
            return $dest;
        }
        return null;
    }

    /**
     * Guarda el contenido en un archivo sin filtrar por exito: guarda el body incluso si
     * el request fallo o fue abortado
     * @param string $filename
     * @return int|null
     */
    function saveToFile_fail(string $filename){
        $mtime=$this->header('last-modified');
        if($mtime) $mtime=strtotime($mtime);
        if(is_string($this->content)){
            $copy=file_put_contents($filename, $this->content);
            if($copy && $mtime) touch($filename, $mtime);
            if(!is_int($copy)) return null;
            return $copy;
        }
        if($this->stream){
            $dest=fopen($filename, 'w+');
            if(!$dest) return null;
            fseek($this->stream, 0);
            $copy=stream_copy_to_stream($this->stream, $dest);
            fclose($dest);
            if($copy && $mtime) touch($filename, $mtime);
            if(!is_int($copy)) return null;
            return $copy;
        }
        return null;
    }

    public function __toString(){
        return $this->getContent()??'';
    }

    /**
     * @param string $headers
     * @return string[]|null
     */
    static function header_names(string $headers){
        if(preg_match_all('/(^|\n|\r)\s*([^\s:]+)[ ]*\:/i', $headers, $m)){
            $m=array_values(array_unique(array_map('trim', $m[2])));
            return $m;
        }
        return null;
    }

    static function searchHeader(string $headers, $name){
        if(preg_match('/(^|\n|\r)\s*'.preg_quote($name, '/').'[ ]*\:([^\n\r]+)(\n|\r|$)/i', $headers, $m)){
            return trim($m[2]);
        }
        return null;
    }

    static function searchHeader_multi(string $headers, $name){
        if(preg_match_all('/(^|\n|\r)\s*'.preg_quote($name, '/').'[ ]*\:([^\n\r]+)(\n|\r|$)/i', $headers, $m)){
            return array_map('trim', $m[2]);
        }
        return null;
    }
}