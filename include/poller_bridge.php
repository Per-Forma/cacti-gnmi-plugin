<?php
require_once __DIR__ . '/database_config.php';

function gnmi_bridge_now() { return hrtime(true) / 1000000000; }
function gnmi_bridge_target($inherited, $interval) {
    $target = gnmi_bridge_now() + min(.8 * $interval, 30);
    return $inherited === null ? $target : min($target, $inherited);
}

function gnmi_bridge_diagnostic($category) {
    $messages = [
        'configuration'=>'Database configuration is invalid or incomplete',
        'php_unavailable'=>'CLI PHP is unavailable to the poller account',
        'permissions'=>'Database configuration or TLS material is not readable by the poller account',
        'socket_resolution'=>'Set an absolute pdo_mysql.default_socket in the poller PHP configuration',
        'dns'=>'Database name resolution failed',
        'refused'=>'Database connection refused; check availability and transport settings',
        'timeout'=>'Database bridge deadline expired; work will resume next cycle',
        'authentication'=>'Database authentication failed; check the effective Cacti configuration',
        'tls_identity'=>'Database TLS trust or hostname validation failed; correct the CA or certificate identity',
        'tls_client'=>'Database client certificate or key is unreadable or invalid',
        'tls_required'=>'Database TLS is required; the server must support encrypted connections',
        'tls'=>'Database TLS negotiation failed',
        'routing'=>'Unsupported database routing; test through the actual PHP poller',
        'metadata'=>'Database metric metadata query failed',
        'execution'=>'Database bridge execution failed; verify proc_open and the poller Python environment',
        'deferred'=>'Collection deferred by its shared deadline; work will resume next cycle',
        'maintenance'=>'Cannot persist bridge progress safely; check the protected runtime storage directory',
    ];
    return $messages[$category] ?? $messages['execution'];
}

/** Argument arrays and separate private pipes: never put credentials in argv or logs. */
function gnmi_run_bridge(array $command, $payload, $target) {
    $end = min($target, gnmi_bridge_now() + 5);
    $result = ['exit_code'=>-1,'stdout'=>'','category'=>'execution','phase'=>'execution'];
    if ($end - gnmi_bridge_now() < .2) { $result['category']='deferred'; return $result; }
    if (!function_exists('proc_open') || !is_string($payload) || strlen($payload)>65536) {
        $result['phase']='configuration';
        return $result;
    }
    $process = @proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) { $result['phase']='configuration'; return $result; }
    foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
    $offset=0; $stdout=''; $stderr=''; $failure=null; $exit=-1; $running=true;
    try {
        while (true) {
            $now=gnmi_bridge_now();
            if ($now >= $end-.15) { $failure='timeout'; break; }
            $status=proc_get_status($process);
            $running=$status['running'];
            if (!$running) { if ($status['exitcode']>=0) $exit=$status['exitcode']; if($status['signaled']) $exit=-1; }
            $read=[];foreach ([1,2] as $i) if (isset($pipes[$i]) && !feof($pipes[$i])) $read[]=$pipes[$i];
            $write=isset($pipes[0]) && $running ? [$pipes[0]] : [];
            if (isset($pipes[0]) && (!$running || $offset >= strlen($payload))) {fclose($pipes[0]);unset($pipes[0]);$write=[];}
            if (!$read && !$write) {
                if (!$running) break;
                usleep(1000);continue;
            }
            $except=null;
            if (@stream_select($read,$write,$except,0,10000) === false) { $failure='execution'; break; }
            foreach ($write as $pipe) {
                $n=@fwrite($pipe,substr($payload,$offset,8192));
                if($n===false){$failure='execution';break 2;}$offset+=$n;
            }
            foreach ($read as $pipe) {
                $chunk=@fread($pipe,8192);
                if($chunk===false){$failure='execution';break 2;}
                if(isset($pipes[1]) && $pipe===$pipes[1]) {
                    $stdout.=$chunk;
                    if(strlen($stdout)>1048576){$failure='execution';break 2;}
                } else {
                    $stderr.=substr($chunk,0,max(0,8192-strlen($stderr)));
                }
            }
            // A child may leave inherited pipes open; do not wait for another process's EOF.
            if (!$running && !$read) break;
        }
    } finally {
        if ($running) {
            @proc_terminate($process,15);
            $grace=min($end-.05,gnmi_bridge_now()+.05);
            do { $status=proc_get_status($process); if(!$status['running']) break; usleep(1000); } while(gnmi_bridge_now()<$grace);
            if($status['running']) @proc_terminate($process,9);
            do { $status=proc_get_status($process); if(!$status['running']) break; usleep(1000); } while(gnmi_bridge_now()<$end);
            $running=$status['running'];
        }
        foreach ($pipes as $pipe) fclose($pipe);
        if (!$running) {
            $closed=proc_close($process);
            if($exit<0 && $closed>=0) $exit=$closed;
        } else {
            // Never block proc_close on a process the OS has not yet reaped.
            // Retain the handle until PHP shutdown; report execution maintenance failure.
            $GLOBALS['gnmi_unreaped_bridge_handles'][]=$process;
            $failure='execution';
        }
    }
    $result['exit_code']=$exit;
    if ($failure !== null) { $result['category']=$failure; return $result; }
    if ($exit===0 && $offset===strlen($payload)) {
        $result['stdout']=$stdout; $result['category']=null; return $result;
    }
    if (preg_match('/(?:^|\n)GNMI_BRIDGE_ERROR ([a-z_]+) (configuration|resolver|connect|metadata|execution)\n/', $stderr, $matches)) {
        $category=$matches[1];
        if ($category!=='execution' && gnmi_bridge_diagnostic($category)===gnmi_bridge_diagnostic('execution')) $category='execution';
        $result['category']=$category;$result['phase']=$matches[2];
    }
    return $result;
}

class GnmiBridgeMaintenanceException extends RuntimeException {}
function gnmi_bridge_cursor_stat($path) {
    clearstatcache(true,$path);
    $stat=@lstat($path);
    if($stat!==false && (($stat['mode']&0170000)!==0100000 || $stat['nlink']!==1)) throw new GnmiBridgeMaintenanceException();
    return $stat;
}
function gnmi_bridge_cursor_read($dir) {
    $path=$dir.'/.gnmi_bridge_cursor.json';
    $stat=gnmi_bridge_cursor_stat($path);
    if($stat===false) return [0,0];
    $file=@fopen($path,'rb');if($file===false) throw new GnmiBridgeMaintenanceException();
    try {
        $opened=fstat($file);
        if($opened['ino']!==$stat['ino'] || $opened['dev']!==$stat['dev']) throw new GnmiBridgeMaintenanceException();
        $raw=fread($file,257);
    } finally {fclose($file);}
    $cursor=json_decode($raw,true);
    if(strlen($raw)>256 || !is_array($cursor) || count($cursor)!==3 || ($cursor['version']??null)!==1 ||
        !isset($cursor['device_id'],$cursor['local_data_id']) || !is_int($cursor['device_id']) || !is_int($cursor['local_data_id']) ||
        $cursor['device_id']<0 || $cursor['local_data_id']<0) return [0,0];
    return [$cursor['device_id'],$cursor['local_data_id']];
}
function gnmi_bridge_cursor_write($dir,array $pair) {
    if(empty($GLOBALS['gnmi_poller_lock_held'])) throw new GnmiBridgeMaintenanceException();
    $path=$dir.'/.gnmi_bridge_cursor.json';gnmi_bridge_cursor_stat($path);
    $temp=$dir.'/.gnmi_bridge_cursor.'.bin2hex(random_bytes(12)).'.tmp';
    $mask=umask(0077);try{$file=@fopen($temp,'x+b');}finally{umask($mask);}
    if($file===false) throw new GnmiBridgeMaintenanceException();
    try {
        $raw=json_encode(['version'=>1,'device_id'=>(int)$pair[0],'local_data_id'=>(int)$pair[1]],JSON_THROW_ON_ERROR);
        if(strlen($raw)>256 || !chmod($temp,0640) || fwrite($file,$raw)!==strlen($raw) || !fflush($file)) throw new GnmiBridgeMaintenanceException();
        gnmi_bridge_cursor_stat($path);
        if(!@rename($temp,$path)) throw new GnmiBridgeMaintenanceException();
    } finally {fclose($file);if(file_exists($temp))@unlink($temp);}
}
function gnmi_bridge_order_pairs(array $pairs,array $cursor) {
    usort($pairs,fn($a,$b)=>[(int)$a['device_id'],(int)$a['local_data_id']]<=>[(int)$b['device_id'],(int)$b['local_data_id']]);
    $after=[];$before=[];
    foreach($pairs as $pair) {
        if([(int)$pair['device_id'],(int)$pair['local_data_id']]>$cursor)$after[]=$pair;else $before[]=$pair;
    }
    return array_merge($after,$before);
}
