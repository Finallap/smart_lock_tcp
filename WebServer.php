<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Workerman;

use \Workerman\Worker;
use \Workerman\Protocols\Http;
use \Workerman\Protocols\HttpCache;

/**
 *  WebServer.
 */
class WebServer extends Worker
{
    /**
     * Mime.
     * @var string
     */
    protected static $defaultMimeType = 'text/html; charset=utf-8';
    
    /**
     * Virtual host to path mapping.
     * @var array ['workerman.net'=>'/home', 'www.workerman.net'=>'home/www']
     */
    protected $serverRoot = array();
    
    /**
     * Mime mapping.
     * @var array
     */
    protected static $mimeTypeMap = array();
    
    
    /**
     * Used to save user OnWorkerStart callback settings.
     * @var callback
     */
    protected $_onWorkerStart = null;
    
    /**
     * Add virtual host.
     * @param string $domain
     * @param string $root_path
     * @return void
     */
    public  function addRoot($domain, $root_path)
    {
        $this->serverRoot[$domain] = $root_path;
    }
    
    /**
     * Construct.
     * @param string $socket_name
     * @param array $context_option
     */
    public function __construct($socket_name, $context_option = array())
    {
        list($scheme, $address) = explode(':', $socket_name, 2);
        parent::__construct('http:'.$address, $context_option);
        $this->name = 'WebServer';
    }
    
    /**
     * Run webserver instance.
     * @see Workerman.Worker::run()
     */
    public function run()
    {
        $this->_onWorkerStart = $this->onWorkerStart;
        $this->onWorkerStart = array($this, 'onWorkerStart');
        $this->onMessage = array($this, 'onMessage');
        parent::run();
    }
    
    /**
     * Emit when process start.
     * @throws \Exception
     */
    public function onWorkerStart()
    {
        if(empty($this->serverRoot))
        {
            throw new \Exception('server root not set, please use WebServer::addRoot($domain, $root_path) to set server root path');
        }
        // Init HttpCache.
        HttpCache::init();
        // Init mimeMap.
        $this->initMimeTypeMap();
        
        // Try to emit onWorkerStart callback.
        if($this->_onWorkerStart)
        {
            try
            {
                call_user_func($this->_onWorkerStart, $this);
            }
            catch(\Exception $e)
            {
                echo $e;
                exit(250);
            }
        }
    }
    
    /**
     * Init mime map.
     * @return void
     */
    public function initMimeTypeMap()
    {
        $mime_file = Http::getMimeTypesFile();
        if(!is_file($mime_file))
        {
            $this->notice("$mime_file mime.type file not fond");
            return;
        }
        $items = file($mime_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if(!is_array($items))
        {
            $this->log("get $mime_file mime.type content fail");
            return;
        }
        foreach($items as $content)
        {
            if(preg_match("/\s*(\S+)\s+(\S.+)/", $content, $match))
            {
                $mime_type = $match[1];
                $extension_var = $match[2];
                $extension_array = explode(' ', substr($extension_var, 0, -1));
                foreach($extension_array as $extension)
                {
                    self::$mimeTypeMap[$extension] = $mime_type;
                } 
            }
        }
    }
    
    /**
     * Emit when http message coming.
     * @param TcpConnection $connection
     * @param mixed $data
     * @return void
     */
    public function onMessage($connection, $data)
    {
        // REQUEST_URI.
        $url_info = parse_url($_SERVER['REQUEST_URI']);
        if(!$url_info)
        {
            Http::header('HTTP/1.1 400 Bad Request');
            return $connection->close('<h1>400 Bad Request</h1>');
        }
        
        $path = $url_info['path'];
        
        $path_info = pathinfo($path);
        $extension = isset($path_info['extension']) ? $path_info['extension'] : '' ;
        if($extension === '')
        {
            $path = ($len = strlen($path)) && $path[$len -1] === '/' ? $path.'index.php' : $path . '/index.php';
            $extension = 'php';
        }
        
        $root_dir = isset($this->serverRoot[$_SERVER['HTTP_HOST']]) ? $this->serverRoot[$_SERVER['HTTP_HOST']] : current($this->serverRoot);
        
        $file = "$root_dir/$path";
        
        if($extension === 'php' && !is_file($file))
        {
            $file = "$root_dir/index.php";
            if(!is_file($file))
            {
                $file = "$root_dir/index.html";
                $extension = 'html';
            }
        }
        
        // File exsits.
        if(is_file($file))
        {
            // Security check.
            if((!($request_realpath = realpath($file)) || !($root_dir_realpath = realpath($root_dir))) || 0 !== strpos($request_realpath, $root_dir_realpath))
            {
                Http::header('HTTP/1.1 400 Bad Request');
                return $connection->close('<h1>400 Bad Request</h1>');
            }
            
            $file = realpath($file);
            
            // Request php file.
            if($extension === 'php')
            {
                $cwd = getcwd();
                chdir($root_dir);
                ini_set('display_errors', 'off');
                ob_start();
                // Try to include php file.
                try 
                {
                    // $_SERVER.
                    $_SERVER['REMOTE_ADDR'] = $connection->getRemoteIp();
                    $_SERVER['REMOTE_PORT'] = $connection->getRemotePort();
                    include $file;
                }
                catch(\Exception $e) 
                {
                    // Jump_exit?
                    if($e->getMessage() != 'jump_exit')
                    {
                        echo $e;
                    }
                }
                $content = ob_get_clean();
                ini_set('display_errors', 'on');
                $connection->close($content);
                chdir($cwd);
                return ;
            }
            
            // Static resource file request.
            if(isset(self::$mimeTypeMap[$extension]))
            {
               Http::header('Content-Type: '. self::$mimeTypeMap[$extension]);
            }
            else 
            {
                Http::header('Content-Type: '. self::$defaultMimeType);
            }
            
            // Get file stat.
            $info = stat($file);
            
            $modified_time = $info ? date('D, d M Y H:i:s', $info['mtime']) . ' GMT' : '';
            
            if(!empty($_SERVER['HTTP_IF_MODIFIED_SINCE']) && $info)
            {
                // Http 304.
                if($modified_time === $_SERVER['HTTP_IF_MODIFIED_SINCE'])
                {
                    // 304
                    Http::header('HTTP/1.1 304 Not Modified');
                    // Send nothing but http headers..
                    return $connection->close('');
                }
            }
            
            if($modified_time)
            {
                Http::header("Last-Modified: $modified_time");
            }
            // Send to client.
           return $connection->close(file_get_contents($file));
        }
        else 
        {
            // 404
            Http::header("HTTP/1.1 404 Not Found");
            return $connection->close('<html><head><title>404 File not found</title></head><body><center><h3>404 Not Found</h3></center></body></html>');
        }
    }
}
