<?php

class MyHTTP
{

    protected $bind_ip;
    protected $bind_port;
    protected $fd_data = [];

    protected $table;

    public function __construct($ip, $port)
    {
        $this->bind_ip = $ip;
        $this->bind_port = $port;

        $this->table = new Swoole\Table(1024);
        $this->table->column('is_parse_head', Swoole\Table::TYPE_INT);
        $this->table->column('is_completed', Swoole\Table::TYPE_INT);
        $this->table->column('require_length', Swoole\Table::TYPE_INT);
        $this->table->column('recv_length', Swoole\Table::TYPE_INT);
        $this->table->column('cid', Swoole\Table::TYPE_INT);
        $this->table->create();

        $server = new Swoole\Server($ip, $port);

        $server->on('start', [$this, 'onStart']);
        $server->on('connect', [$this, 'onConnect']);
        $server->on('receive', [$this, 'onReceive']);
        $server->on('close', [$this, 'onClose']);

        $server->start();
    }

    public function onStart($server)
    {
        Swoole\Runtime::enableCoroutine();
        echo "TCP Server is started at tcp://{$this->bind_ip}\n";
    }

    public function onConnect($server, $fd)
    {
        echo "connection open: {$fd}\n";
        @unlink('aa.txt');
    }

    public function onReceive($server, $fd, $reactor_id, $data)
    {

//        file_put_contents('aa.txt',$data,FILE_APPEND);
//        echo sprintf("<< [收到数据请求:]\n%s\n", strlen($data)>200?substr($data,0,200).'...[数据过大]':$data);

        if (!$this->table->exist($fd)) {
            $this->table->set($fd, [
                'is_completed'   => 0, // 是否完成
                'is_parse_head'  => 0, // 是否解析head
                'require_length' => 0, // 请求需要长度
                'recv_length'    => 0, // 已经接收长度
            ]);
        }

        if (!$this->table->get($fd, 'is_parse_head')) {

            $this->debugText("<<<<<<<<<<尝试解析header:$fd\n");
            $request = $this->preread_http_request($data);

            $body_pos = strpos($data, hex2bin("0d0a0d0a"));
            $content_length = intval($request['headers']['Content-Length']);
            $recv_length = strlen($data) - $body_pos + strlen(hex2bin("0d0a0d0a"));
            $this->table->set($fd, [
                'is_parse_head'  => 1,
                'require_length' => $request['headers']['Content-Length'] ?? 0,
                'recv_length'    => $recv_length
            ]);
            file_put_contents('./tmp/'.$fd, substr($data, $body_pos + strlen(hex2bin("0d0a0d0a"))));

            if ($recv_length >= $content_length) {
                // 接收完毕
                $this->debugText(sprintf("<< [一次接收完毕,触发请求,$recv_length, $content_length]\n"));
                $this->onRequest($server, $fd, $request, $data);
            } else {
                $this->debugText(sprintf("<< [未接收完毕]\n"));
                // 未接收完毕，需要多次接收
                $cid = Swoole\Coroutine::getuid();
                $this->debugText(sprintf("<< [让出协程] code => %s\n",$cid));
                $this->table->set($fd, [
                    'cid'  => $cid,
                ]);
                Swoole\Coroutine::yield();
                $this->debugText(sprintf("<< [恢复协程，准备触发 Request]\n"));
                $this->onRequest($server, $fd, $request, $data);
                return false;
            }
        } else {
            $this->debugText(sprintf("<< [分段接收]\n"));
            // 这里接收数据
            file_put_contents('./tmp/'.$fd, $data,FILE_APPEND);
            $this->table->incr($fd, 'recv_length', strlen($data));
            if ($this->table->get($fd,'recv_length') >= $this->table->get($fd,'require_length')) {
                $this->debugText(sprintf("<< [接收完毕,触发请求] %s == %s\n", $this->table->get($fd,'recv_length'), $this->table->get($fd,'require_length')));
                $cid = $this->table->get($fd,'cid');
                Swoole\Coroutine::resume($cid);
                $this->debugText(sprintf("<< [准备恢复协程，%s]\n",$cid));
            }else{
                return false;
            }

        }

        // 请求完释放内存数据
        $this->table->del($fd);
    }

    public function onRequest($server, $fd, $request, $data)
    {

//        if ($request['method'] == 'POST') {
//            $payload = $this->parse_http_payload($request, $data);
//            var_dump($payload);
//        }
//        $text = "你请求的地址:" . $request['request_uri'] . '  方法是:' . $request['method'];
        $text = "ddd";
        $raw = $this->build_http_response($this->http_code(200), 'text/html; charset=utf-8', $text);
        $this->debugText(sprintf(">> [回应数请求:]\n%s\n", $raw));
        $server->send($fd, $raw);
    }

    public function onClose($server, $fd)
    {
        echo "connection close: {$fd}\n";
    }

    protected function preread_http_header(&$data)
    {//ff d8 ff e0
//    $pos = strpos($data,"\r\n");
//    var_dump(substr($data,0, $pos));

        $pos = strpos($data, hex2bin("0d0a0d0a"));
//        var_dump(substr($data,0,400));

        $header = substr($data, 0, $pos);
//        var_dump('==============================000');
//        var_dump($header);
//        var_dump('==============================111');
//    file_put_contents('./test.txt', $header.PHP_EOL, FILE_APPEND);
        if ($headers = explode("\r\n", $header)) {
            $key_map = [];
            foreach ($headers as $item) {
                $buffer = explode(":", $item);
                if (count($buffer) >= 2) {
                    $key_map[trim($buffer[0])] = trim($buffer[1]);
                }
                unset($buffer);
            }
            unset($headers);
            unset($header);
            unset($pos);
            return $key_map;
        } else {
            return false;
        }
    }

    protected function preread_http_request(&$data)
    {
//        var_dump(substr($data,0,200));
        $pos = strpos($data, hex2bin("0d0a"));
        $first_line = substr($data, 0, $pos);
        list($method, $path, $protocol_version) = explode(' ', $first_line);
        $request = [
            'method'           => trim(strtoupper($method)),
            'request_uri'      => trim($path),
            'protocol_version' => trim($protocol_version),
        ];
        $request['headers'] = $this->preread_http_header($data);
        return $request;
    }

    protected function parse_http_payload(array $request, string &$data)
    {
        $payload = [];
        $form = [];
        $raw = '';
        $pos = strpos($data, hex2bin("0d0a0d0a"));
        if ($pos != -1) {
            $body = substr($data, $pos + strlen(hex2bin("0d0a0d0a")));
        } else {
            $body = $data;
        }

        if (isset($request['headers']['Content-Type'])) {
            if ($request['headers']['Content-Type'] == 'application/x-www-form-urlencoded') {
                $arr_data = explode('&', $body);
                foreach ($arr_data as $item_data) {
                    $value_ret = explode('=', $item_data);
                    $form[$value_ret[0]] = $value_ret[1] ?? '';
                }

            } else if (preg_match('#multipart/form-data; boundary=([^\b]+)\b#is', $request['headers']['Content-Type'], $matches)) {
                $boundary_str = $matches[1];
                $arr_data = explode($boundary_str, $body);
                foreach ($arr_data as $item_data) {
                    if (preg_match('#Content-Disposition: form-data; name="([^"]+)"\r\n\r\n(.*)\r\n#is', $item_data, $value_ret)) {
                        $form[$value_ret[1]] = $value_ret[2];
                    }
                    //Content-Disposition: form-data; name="file"; filename="222.png"
                    //Content-Type: image/png
                    elseif (preg_match('#Content-Disposition: form-data; name="file"; filename="([^"]+)"\r\nContent-Type: ([A-Za-z0-9/]+)\r\n\r\n(.*)\r\n#is', $item_data, $value_ret)) {
                        $form[$value_ret[1]] = ['type' => $value_ret[2], 'stream' => $value_ret[3]];
                        file_put_contents('aa3.png', $value_ret[3]);
                    }
                }
            } else if (in_array($request['headers']['Content-Type'], [
                'text/plain',
                'application/json',
                'application/javascript',
                'text/html',
                'application/xml',
            ])) {
                $raw = $body;
            } else {
                // 其他都认为是资源文件
                $raw = $body;
            }
        }

        $payload = [
            'from' => $form,
            'raw'  => $raw
        ];

        unset($body, $arr_data, $item_data, $boundary_str, $value_ret, $form, $raw);
        return $payload;
    }


    protected function http_code($num)
    {
        $http = array(
            100 => "HTTP/1.1 100 Continue",
            101 => "HTTP/1.1 101 Switching Protocols",
            200 => "HTTP/1.1 200 OK",
            201 => "HTTP/1.1 201 Created",
            202 => "HTTP/1.1 202 Accepted",
            203 => "HTTP/1.1 203 Non-Authoritative Information",
            204 => "HTTP/1.1 204 No Content",
            205 => "HTTP/1.1 205 Reset Content",
            206 => "HTTP/1.1 206 Partial Content",
            207 => "HTTP/1.1 207 Multi-Status",
            300 => "HTTP/1.1 300 Multiple Choices",
            301 => "HTTP/1.1 301 Moved Permanently",
            302 => "HTTP/1.1 302 Found",
            303 => "HTTP/1.1 303 See Other",
            304 => "HTTP/1.1 304 Not Modified",
            305 => "HTTP/1.1 305 Use Proxy",
            307 => "HTTP/1.1 307 Temporary Redirect",
            400 => "HTTP/1.1 400 Bad Request",
            401 => "HTTP/1.1 401 Unauthorized",
            402 => "HTTP/1.1 402 Payment Required",
            403 => "HTTP/1.1 403 Forbidden",
            404 => "HTTP/1.1 404 Not Found",
            405 => "HTTP/1.1 405 Method Not Allowed",
            406 => "HTTP/1.1 406 Not Acceptable",
            407 => "HTTP/1.1 407 Proxy Authentication Required",
            408 => "HTTP/1.1 408 Request Time-out",
            409 => "HTTP/1.1 409 Conflict",
            410 => "HTTP/1.1 410 Gone",
            411 => "HTTP/1.1 411 Length Required",
            412 => "HTTP/1.1 412 Precondition Failed",
            413 => "HTTP/1.1 413 Request Entity Too Large",
            414 => "HTTP/1.1 414 Request-URI Too Large",
            415 => "HTTP/1.1 415 Unsupported Media Type",
            416 => "HTTP/1.1 416 Requested range not satisfiable",
            417 => "HTTP/1.1 417 Expectation Failed",
            500 => "HTTP/1.1 500 Internal Server Error",
            501 => "HTTP/1.1 501 Not Implemented",
            502 => "HTTP/1.1 502 Bad Gateway",
            503 => "HTTP/1.1 503 Service Unavailable",
            504 => "HTTP/1.1 504 Gateway Time-out"
        );
        return $http[$num];
    }

    protected function build_http_response($code = "HTTP/1.1 200 OK", $content_type = "text/plain; charset=UTF-8", $content = "", $is_keep_alive = "close", $length = 0, $headers = [])
    {
        $lastmod = gmdate("D, d M Y H:i:s") . " GMT";
        $length = $length > 0 ? $length : strlen($content);
        // header
        $header_str = '';
        foreach ($headers as $header) {
            $header_str .= $header . PHP_EOL;
        }
        $header_str = rtrim($header_str, PHP_EOL);
        if (!empty($header_str)) {
            $header_str = "\r\n" . $header_str;
        }
        $raw = <<<EOF
$code
Date: $lastmod
Content-Type: $content_type
Connection: $is_keep_alive
Content-Length: $length
Server: eller-server$header_str

$content
EOF;
//    var_dump($raw);
        return $raw;
    }

    protected function debugText($text)
    {
//        echo $text;
    }
}

$http = new MyHTTP('0.0.0.0', 5555);

