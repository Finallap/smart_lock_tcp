<?php
	use Workerman\Worker;
	require_once 'Autoloader.php';

	// 创建一个Worker监听2347端口，不使用任何应用层协议
	$tcp_worker = new Worker("tcp://0.0.0.0:2347");

	// 启动4个进程对外提供服务
	$tcp_worker->count = 4;

	//建立数组处理等待输入
	$input_array = array( 0 => NULL , 1 => NULL , 2  => NULL , 3 => NULL);

	// 当客户端发来数据时
	$tcp_worker->onMessage = function($connection, $data)
	{
		//输入信息进行轮换
		$input_array[3] = $input_array[2];
		$input_array[2] = $input_array[1];
		$input_array[1] = $input_array[0];
		$input_array[0] = $data;

		//连接最近几次输入
		$message_splice = $input_array[3].$input_array[2].$input_array[1].$input_array[0];

		//通过换行符来进行拆分
		$arr = explode("\n",$message_splice);

		//去除拆分完数组元素两侧的空格和换行
		trim_extra_character($arr);

		//找出数组中相同的元素
		$find_array = array_filter(array_repeat($arr));

		//找出第一个相同的元素
		$find_result = current($find_array);

		if($find_result==FALSE)
			$response = "OK";
		else
			$response = $find_result;
		
		database_input($data,$response);

	    // 向客户端发送报文
	    $connection->send($response);
	};

	// 运行worker
	Worker::runAll();
	

	/**
     * 去除数组元素两侧的空格和换行
     * 
     * @param 需要处理的数组
     */
	function trim_extra_character($arr)
	{
		foreach ($arr as $key => $value)
		{
			$arr[$key] = trim($arr[$key]);
			$arr[$key] = str_replace("\n",'',$arr[$key]);
		}
	}


    /**
     * 找出数组中相同的元素，并返回数组
     * 
     * @param 需要查找的数组
     * @return 数组中相同的元素组成的数组
     */
	function array_repeat($arr)
	{
		if(!is_array($arr)) return $arr;

		$arr1 = array_count_values($arr);

		$newArr = array();

		foreach($arr1 as $k=>$v)
		{
			if($v>1) array_push($newArr,$k); 
		}
		return $newArr;
	}

	/**
     * 记录输入、输出到数据库
     * 
     * @param TCP接收报文
     * @param TCP回复报文
     */
	function database_input($receive,$send)
	{
		$dbname='smart_lock';
		$host='rds1hq3v6d8f98sm33i0o.mysql.rds.aliyuncs.com';
		$port=3306;

		$dsn="mysql:dbname=$dbname;host=$host;port=$port";
		$user='fyr';
		$password='aifuwu2014';

		$pdo=new PDO($dsn,$user,$password); 
		$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->exec("set names utf8"); 

		$input_information=$pdo->prepare('insert `tcp_data`(`content`,`time`,`response`) values(:content,:time,:response);');
		$input_information->bindValue(':content',$receive);
		$input_information->bindValue(':response',$send);
		$input_information->bindValue(':time',date('Y-m-d H:i:s',time()));
		$input_information->execute();

		$pdo = NULL;
	}