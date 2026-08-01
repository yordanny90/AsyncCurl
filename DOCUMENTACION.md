[repo]:  https://github.com/yordanny90/AsyncCurl
[iconGit]: http://www.google.com/s2/favicons?domain=www.github.com

# Documentación AsyncCurl

Libreria de consumo HTTP sobre `curl_multi`. Permite tanto un request bloqueante simple como
varias peticiones en paralelo con el mismo `Agent`.

Requiere PHP 7.4+ / 8.0+ y la extension `curl`.

[Ir a ![GitHub CI][iconGit]][repo]

## Clases

| Clase | Rol |
|---|---|
| `AsyncCurl\Agent` | Configuracion comun (URL base, headers, opciones, credenciales) y fabrica de peticiones. Administra el pool `curl_multi`. |
| `AsyncCurl\Request` | Una peticion en vuelo. Se resuelve con `resolve()` o con `wait()`+`stop()`. |
| `AsyncCurl\Response` | Resultado final, exitoso o fallido/abortado. |
| `AsyncCurl\StringFile` | `CURLFile` construido desde un string en memoria (`Agent::curl_string_file()`). |

Un `Agent` representa una **sesion** de consumo, no una peticion: crear uno por integracion o
por corrida de proceso y reutilizarlo. Cada `Agent` inicializa su propio handle `curl_multi`.

## Uso sincrono (un request bloqueante)

```php
use AsyncCurl\Agent;

$agent=new Agent('https://api.ejemplo.com');
$agent->addHeader('Authorization', 'Bearer '.$token);
$agent->set_content_type(Agent::CT_JSON);

$response=$agent->requestSync('POST', '/recurso', null, null, ['campo'=>'valor'], null, null, 30);

if(!$response || !$response->isSuccess()){
    $detalle=$response ? $response->content_fail() : null;
    // manejar error: $response->http_code(), getErrno(), getError()
}
else{
    $data=$response->getJSON(true);
}
```

`requestSync()` dispara la peticion y espera el resultado — equivalente a un `curl_exec()`
bloqueante. Es la forma recomendada cuando no hay paralelismo.

## Uso en paralelo

```php
use AsyncCurl\Agent;

$agent=new Agent('https://api.ejemplo.com');
$agent->multi_setopt(CURLMOPT_MAX_HOST_CONNECTIONS, 10);

// 1. Disparar: cada request() sale de inmediato
$pendientes=[];
foreach($items as $id){
    $pendientes[$id]=$agent->request('GET', '/consulta', ['id'=>$id]);
}

// 2. Recoger a medida que terminan
while($pendientes){
    foreach($pendientes as $id=>$req){
        if($req->is_running()) continue;
        $response=$req->stop()->response();
        unset($pendientes[$id]);
        // procesar $response
    }
}
```

`$agent->ready($timeout)` avanza el pool y `$agent->countCurl()` indica cuantas peticiones
siguen en vuelo, para controlar el ritmo de las rafagas.

## Agent

### Construccion y credenciales

```php
new Agent(string $uri='', $user=null, $pass=null)
```

`setUri()`, `setUser()`, `setPassword()` ignoran valores vacios/`null`: no borran lo ya
configurado. `getUri()` devuelve la URL base.

### Cierre

```php
$agent->close();
```

Aborta las peticiones que sigan en vuelo — cada una queda con una `Response` marcada como
abortada — y libera el pool de conexiones. Util al terminar una corrida larga (un cron) para
soltar los recursos de inmediato en vez de esperar al recolector de basura. Es idempotente y
el destructor tambien lo llama.

Un `Agent` cerrado ya no atiende peticiones nuevas: `request()` devuelve un `Request` cuya
`response()` es `null`.

### URL por peticion

El parametro `$url_endpoint` de `request()` se **concatena** a la URL base, salvo que sea una
URL absoluta (`^\w+://`), en cuyo caso la reemplaza. Los parametros GET (`$paramGET`) se
serializan con `http_build_query()` si son array/object y se anexan respetando el `?`/`&` que
ya traiga la URL.

### Headers y opciones

```php
$agent->addHeader('Accept', Agent::CT_JSON);   // reemplaza si ya existe
$agent->addHeaders(['X-A'=>'1', 'X-B'=>'2']);
$agent->delHeader('Accept');

$agent->addOption(CURLOPT_SSL_VERIFYPEER, false);
$agent->addOptions([CURLOPT_TIMEOUT=>30]);
$agent->multi_setopt(CURLMOPT_PIPELINING, 3);  // opciones del pool curl_multi
```

`request()` acepta `$addHeaders` y `$addOpts` que aplican **solo a esa peticion** y no quedan
guardados en el `Agent`.

### Defaults globales (estaticos, afectan a todos los Agent)

```php
Agent::setDefaultTimeout(60);
Agent::setDefaultConnectTimeout(10);
Agent::setDefaultMaxRedirs(10);
Agent::setDefaultVerifyPeer(false);
Agent::setDefaultAppUserAgent('https://mi-sistema.com', 'extras');
Agent::setDefaultOption(CURLOPT_ENCODING, '');
Agent::getDefaultOption(CURLOPT_TIMEOUT);
Agent::delDefaultOption(CURLOPT_TIMEOUT);
```

Valores iniciales: `CONNECTTIMEOUT=10`, `TIMEOUT=60`, `ENCODING=''` (todas las compresiones
soportadas), `FOLLOWLOCATION=true`, `MAXREDIRS=10`, `FAILONERROR=false`.

### Tipo de contenido

Constantes: `CT_JSON`, `CT_FORM_URLENCODED`, `CT_FORM_DATA`, `CT_PLAIN`, `CT_XML`,
`CT_OCTET_STREAM`.

`set_content_type()` fija el tipo por defecto de la instancia, usado cuando `request()` recibe
`$contentType` en `null`. La serializacion del body depende del tipo resuelto:

| `$data` | `$contentType` resuelto | Se envia |
|---|---|---|
| array / object | `CT_FORM_URLENCODED` (default si no hay otro) | `http_build_query()` |
| array / object | `CT_JSON` | `json_encode()` |
| array / object | `CT_FORM_DATA` u otro | campos individuales via `toFields()` (soporta `CURLFile`/`CURLStringFile`) |
| string | el que se indique; si no hay, `CT_OCTET_STREAM` | tal cual, con `Content-Length` |
| `null` | — | sin body y **sin header `Content-Type`** |

Al no haber body, un `Content-Type` puesto a mano en `$addHeaders` se respeta.

`set_charset()` se anexa al header (`; charset=utf-8`); por defecto toma
`ini_get('default_charset')`.

### Metodo HTTP

`request()` recibe el metodo como primer parametro. Con `HEAD` activa `CURLOPT_NOBODY`.

### Version HTTP

```php
$agent->setHttpVersion(2);   // null, 0, 1, 1.1, 2, 3 — devuelve false si el entorno no la soporta
```

### Archivos adjuntos

Con `CT_FORM_DATA`, pasar `CURLFile`/`CURLStringFile` como valores del array, o construir uno
desde memoria:

```php
$file=Agent::curl_string_file($contenido, 'reporte.pdf', 'application/pdf');
$agent->requestSync('POST', '/subir', null, Agent::CT_FORM_DATA, ['archivo'=>$file]);
```

## Request

| Metodo | Que hace |
|---|---|
| `resolve(float $timeout=10.0): ?Response` | Espera, detiene y devuelve la `Response`. Atajo de `wait()`+`stop()`+`response()`. |
| `is_running(float $timeout=0.0): bool` | Avanza el pool y dice si sigue en vuelo. Si ya termino, resuelve internamente la `Response`. |
| `wait(float $timeout=10.0): bool` | Bloquea hasta que termine o se agote el tiempo. |
| `stop(bool $ready=true): $this` | Cierra la peticion y construye la `Response`. Si aun estaba en vuelo, queda marcada como abortada. |
| `response(): ?Response` | Devuelve la `Response` ya construida. |
| `getOptions(): array` | Opciones curl realmente enviadas (debug/auditoria). |
| `execution_time()` | Tiempo acumulado mientras sigue en vuelo. |

**Todo `Request` se debe resolver.** No hay fire-and-forget: la peticion solo avanza mientras
algo bombee el pool (`resolve()`, `wait()`, `is_running()`, `stop()`, `Agent::ready()`, o la
creacion de otra peticion del mismo `Agent`). Un `Request` que se descarte sin resolver queda a
medias de forma no determinista y puede no llegar a enviarse completo.

`Request::__destruct()` llama a `stop()`, pero no sirve como red de seguridad: el `Agent`
mantiene una referencia a cada peticion en vuelo, asi que el destructor no se ejecuta mientras
la peticion siga en el pool, y cuando se libera ya fue detenida. Para un consumo simple usar
`Agent::requestSync()`, que resuelve siempre.

## Response

### Exito

```php
$response->isSuccess()   // sin errores, no abortado y codigo 2xx
$response->isAborted()   // se corto antes de completar la descarga
$response->getErrno()    // codigo de error de curl (0 = sin error)
$response->getError()
```

`isSuccess()` es la comprobacion correcta: una respuesta puede traer `http_code()` 2xx y estar
abortada a la vez (se corto durante la descarga del body). Ningun fallo lanza excepcion — el
llamador decide que hacer.

### Estado HTTP

```php
$response->http_code();     // 200
$response->statusText();    // 'OK'
$response->statusGroup();   // 'Success' | 'Client Error' | ...
```

### Contenido

```php
$response->getContent();          // string|null — NULL si !isSuccess()
$response->getContent(100);       // primeros 100 bytes
$response->getContent(100, 50);   // 100 bytes a partir del offset 50
$response->getJSON(true);         // NULL si el contenido no es JSON valido
$response->copyToStream($dest);
$response->saveToFile($ruta);
```

Las tres primeras devuelven `null` cuando la respuesta **no** fue exitosa, aunque el servicio
haya enviado un body con el detalle del error. Para leer ese caso existen las variantes sin
filtro:

```php
$response->content_fail();          // body aunque haya fallado o se haya abortado
$response->content_fail(100, 50);   // idem, con rango de bytes ($length, $offset)
$response->copyToStream_fail($dest);
$response->saveToFile_fail($ruta);
```

`getContent()`/`content_fail()` admiten `$length` y `$offset` para leer solo un rango de
bytes. Si el contenido esta respaldado por un stream temporal
({@see `Agent::saveToStream()`}), el rango se lee directo del archivo sin cargar el contenido
completo en memoria.

`saveToFile()` aplica al archivo la fecha del header `Last-Modified` si viene.

### Headers

```php
$response->getHeaders();          // string crudo
$response->getHeaderNames();      // string[]
$response->header('location');    // una ocurrencia
$response->header_multi('set-cookie');
```

Si hubo redirecciones, solo se conservan los headers de la respuesta final.

### Metadatos

```php
$response->getInfo();             // curl_getinfo() completo
$response->getRequestOptions();   // opciones curl enviadas
$response->content_type();        // sin el charset
$response->charset();
$response->total_time();
$response->time_list();           // todas las metricas de tiempo
$response->size_list();
$response->getStart();            // timestamp unix del inicio
$response->getOriginMethod();
$response->getOriginUrl();        // URL solicitada (antes de redirecciones)
$response->url();                 // URL efectiva
```

## Respuestas grandes: stream

```php
$agent->saveToStream(true);
$response=$agent->requestSync('GET', '/archivo-grande');
$response->saveToFile($ruta);
$response->close();               // libera el archivo temporal
```

`saveToStream()` es configuracion **del `Agent`**, no de la peticion: aplica a todos los
request que se creen mientras este activo. Cada `Response` retiene entonces un `tmpfile()`
abierto; `close()` lo libera de inmediato (el destructor tambien lo hace, pero en procesos que
acumulan muchas respuestas conviene no esperar al recolector).

Para procesar la respuesta mientras se descarga, pasar un `CURLOPT_WRITEFUNCTION` en
`$addOpts`: la libreria respeta ese callback y no fuerza el volcado.

```php
$agent->requestSync('GET', '/stream', null, null, null, null, [
    CURLOPT_WRITEFUNCTION=>function($ch, $chunk){
        // procesar $chunk
        return strlen($chunk);   // obligatorio: devolver los bytes consumidos
    },
]);
```

## Notas

- `$timeout` de `requestSync()` va **al final** de la firma, despues de `$addOpts`.
- `request()` devuelve por referencia (`function &request`); asignarlo normalmente
  (`$req=$agent->request(...)`) funciona sin cuidados especiales.
- Un `Content-Type` calculado por la libreria sobrescribe el que venga en `$addHeaders`, salvo
  cuando la peticion no lleva body.
