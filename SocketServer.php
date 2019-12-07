<?php
use Workerman\Worker;
require_once 'Autoloader.php';

// 创建一个Worker监听3000端口，不使用任何应用层协议
$tcp_worker = new Worker("tcp://182.254.159.149:40000");

// 启动4个进程对外提供服务
$tcp_worker->count = 1;

// 当客户端发来数据时
$tcp_worker->onMessage = function($connection, $data)
{
	// $dbname='smart_lock';
	// $host='rds1hq3v6d8f98sm33i0o.mysql.rds.aliyuncs.com';
	// $port=3306;

	// $dsn="mysql:dbname=$dbname;host=$host;port=$port";
	// $user='fyr';
	// $password='aifuwu2014';

	// $pdo=new PDO($dsn,$user,$password); 
	// $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
	// $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	// $pdo->exec("set names utf8"); 

	// $input_information=$pdo->prepare('insert `tcp_data`(`content`,`time`) values(:content,:time);');
	// $input_information->bindValue(':content',$data);
	// $input_information->bindValue(':time',date('Y-m-d H:i:s',time()));
	// $input_information->execute();

    // 向客户端发送hello $data
    $connection->send('hello ' . $data);
};

// 运行worker
Worker::runAll();