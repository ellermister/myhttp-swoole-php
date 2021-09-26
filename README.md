# myhttp-swoole-php

传统 PHP WEB 运行模式：Nginx + php-fpm、Apache + FCGI 或者 Cli 终端起的服务，PHP 默认都会在底层将请求数据完整吞到内存里，才会进行解析执行脚本。

无法实现大文件上传（大于运行机器内存的文件）。

该例子通过 SWOOLE TCP 服务器实现简单 HTTP 协议服务器，改变往常将 TCP buffer 数据暂存到内存中，直接写入文件。

轻微内存占用，可实现上传超大文件。

> 该 Demo 仅作为参考，并未完整实现 HTTP 细节。

运行命令：

```bash
php -d swoole.use_shortname=On myhttp.php
```

