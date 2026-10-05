<?php
error_reporting(E_ALL);
$SERVICE                               = [];

$SERVICE['enable']                     = TRUE;
$SERVICE['enable_dump_log']            = TRUE;
$SERVICE['enable_error_log']           = TRUE;
$SERVICE['big_sleep']                  = 3;
$SERVICE['small_sleep']                = 1;
$SERVICE['log_files']                  = [
                                          'dump.log',
                                          'droid.log',
                                          'swarm.log',
                                          'MadelineProto.log'
                                         ];
$SERVICE['update_files']               = [
                                          'index.php',
                                          'swarm.php',
                                          'dump.php',
                                          'restart.swarm'
                                         ];
$SERVICE['inc_files']                  = [
                                          'protected/CONF__.php',
                                          'protected/DB__.php',
                                          'functions/functions.php'
                                         ];

$SERVICE['path']                       = [];
$SERVICE['path']['local']              = [];
$SERVICE['path']['remote']             = [];
$SERVICE['log']                        = [];
$SERVICE['log']['dump']                = [];
$SERVICE['log']['error']               = [];
$SERVICE['droid']                      = [];
$SERVICE['droid']['identification']    = [];
$SERVICE['job_queues_auth']            = [];

$SERVICE['path']['local']['root']      = '/srv/icdm/';
$SERVICE['path']['remote']['root']     = '/srv/icdm/worker/';
$SERVICE['path']['local']['log']       = $SERVICE['path']['local']['root'] . 'var/';
$SERVICE['path']['local']['droid_src'] = $SERVICE['path']['local']['root'] . 'droid/src/icdm/';
$SERVICE['path']['local']['droid_log'] = $SERVICE['path']['local']['root'] . 'var/droid/';
$SERVICE['path']['local']['core']      = $SERVICE['path']['local']['root'] . 'core/';
$SERVICE['path']['remote']['log']      = $SERVICE['path']['remote']['root'] . 'var/';
$SERVICE['dump_log_file']              = 'SERVICE_DUMP.log';
$SERVICE['error_log_file']             = 'SERVICE_ERROR.log';
$SERVICE['droid_update_file']          = $SERVICE['path']['local']['log'] . 'droid_update.start';
$SERVICE['droid_get_logs_file']        = $SERVICE['path']['local']['log'] . 'droid_get_logs.start';
$SERVICE['job_queues_auth_file']       = $SERVICE['path']['local']['log'] . 'job_queues_auth.start';
$SERVICE['restart_service_file']       = $SERVICE['path']['local']['log'] . 'restart_service_file.start';

$SERVICE['service_loop']               = 0;
$SERVICE['DB']                         = null;
$SERVICE['queues']                     = [];
$SERVICE['sync_droids']                = [];

$SERVICE['can_work']  = true;
$SERVICE['must_work'] = $SERVICE['droid_all'] = $SERVICE['droid_update'] = $SERVICE['droid_get_logs'] = $SERVICE['job_queues_auth'] = false;

foreach ($SERVICE['inc_files'] as $inc_file) {
    $inc_file = $SERVICE['path']['local']['core'] . $inc_file;
    if (file_exists($inc_file)) {
        require_once($inc_file);
    } else {
        $SERVICE['log']['dump'][] = $SERVICE['log']['error'][] = "Error|inc_files|!file_exists(".$inc_file.")";
        $SERVICE['enable'] = false;
    }
}

sleep(5);
$SERVICE['DB'] = __DB(['return_type' => 'DB']);

if ($SERVICE['enable'] === TRUE) {
    while($SERVICE['enable'] === TRUE) {
        if (file_exists($SERVICE['restart_service_file'])) {
            unlink($SERVICE['restart_service_file']);
            die();
        }
        if (file_exists($SERVICE['job_queues_auth_file'])) {
            $SERVICE['must_work'] = $SERVICE['job_queues_auth'] = true;
        }
        if (file_exists($SERVICE['droid_get_logs_file'])) {
            $SERVICE['must_work'] = $SERVICE['droid_get_logs'] = $SERVICE['droid_all'] = true;
        }
        if (file_exists($SERVICE['droid_update_file'])) {
            $SERVICE['must_work'] = $SERVICE['droid_update'] = $SERVICE['droid_all'] = true;
        }
        if ($SERVICE['must_work'] === true) {
            if ($SERVICE['DB'] === false) {
                $SERVICE['log']['dump'][] = $SERVICE['log']['error'][] = "Error|SERVICE|DB=false!Try_restart;";
                _transit_SERVICE_log($SERVICE);
                $SERVICE['DB'] = __DB(['return_type' => 'DB']);
                continue;
            }
            $SERVICE['can_work'] = false;
            $job = _job_queues_auth($SERVICE);
            sleep($SERVICE['big_sleep']);
            $job = _sync_droids($SERVICE);
            _transit_SERVICE_log($SERVICE);
            $job = _clear_SERVICE($SERVICE);
        } else {
            sleep($SERVICE['small_sleep']);
        }
        $SERVICE['service_loop']++;
    }
}

_transit_SERVICE_log($SERVICE);
die();

/////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

function _sync_droids(&$SERVICE) {
    if ($SERVICE['droid_all'] === false) {
        return false;
    }
    $droid_identifications = [];
    $SERVICE['sync_droids']['count_results'] = 0;
    $SERVICE['sync_droids']['count_execs']   = 0;
    $SERVICE['sync_droids']['execs']         = [];
    $droids = mysqli_query($SERVICE['DB'], "SELECT * FROM `module_telegram` where `type` = 'droid' and `deleted` = 'N';");
    if ($droids) {
        if ($droids->num_rows) {
            while ($droid = mysqli_fetch_assoc($droids)) {
                $droid_identifications[] = $droid['identification'];
            }
        }
    }
    if (!empty($droid_identifications)) {
        $SERVICE['sync_droids']['execs']        = [];
        $SERVICE['sync_droids']['exec_results'] = [];
        foreach ($droid_identifications as $droid_identification) {
            if ($SERVICE['droid_update'] === true) {
                foreach($SERVICE['update_files'] as $update_file) {
                    $SERVICE['sync_droids']['execs'][] = "docker cp " . $SERVICE['path']['local']['droid_src'] . $update_file . " parsing-" . $droid_identification . ":" . $SERVICE['path']['remote']['root'] . $update_file;
                }
            }
            if ($SERVICE['droid_get_logs'] === true) {
                foreach($SERVICE['log_files'] as $log_file) {
                    if ($log_file == 'MadelineProto.log') {
                        $SERVICE['sync_droids']['execs'][] = "docker cp parsing-" . $droid_identification . ":" . $SERVICE['path']['remote']['root'] . $log_file . " " . $SERVICE['path']['local']['droid_log'] . $droid_identification . "_" . $log_file;
                    } else {
                        $SERVICE['sync_droids']['execs'][] = "docker cp parsing-" . $droid_identification . ":" . $SERVICE['path']['remote']['log'] . $log_file . " " . $SERVICE['path']['local']['droid_log'] . $droid_identification . "_" . $log_file;
                    }
                 }
            }
        }
        if(!empty($SERVICE['sync_droids']['execs'])) {
            $i = 0;
            foreach($SERVICE['sync_droids']['execs'] as $exec) {
                $SERVICE['sync_droids']['exec_results'][$i]['exec_result_0'] = exec($exec, $SERVICE['sync_droids']['exec_results'][$i]['exec_result_1'], $SERVICE['sync_droids']['exec_results']['exec_result_2']);
                sleep($SERVICE['small_sleep']);
                $i++;
            }
            $SERVICE['sync_droids']['count_execs']   = count($SERVICE['sync_droids']['execs']);
            $SERVICE['sync_droids']['count_results'] = count($SERVICE['sync_droids']['exec_results']);
        }
    }

    if ($SERVICE['sync_droids']['count_execs'] != $SERVICE['sync_droids']['count_results']) {
        $SERVICE['log']['dump'][] = $SERVICE['log']['error'][] = "Error|_sync_droids|count_execs!=count_results";
    }
    return true;
}

function _job_queues_auth(&$SERVICE) {
    if ($SERVICE['job_queues_auth'] === false) {
        return false;
    }
    $SERVICE['queues'] = get_queue('job_queues_auth', $SERVICE['DB'], false, false, " `queue_status` IN ('N', 'S') ");
    if (!empty($SERVICE['queues'])) {
        foreach ($SERVICE['queues'] as $queue) {
            set_status_queue('job_queues_auth', $SERVICE['DB'], 'S', false, $queue['id']);
            $exec            = str_replace("|", "'", $queue['queue_content']);
            $exec_res        = exec($exec, $exec_res_1, $exec_res_2);
            $identification  = '';
            if (strpos($queue['queue_from'], '|')) {
                $getIdentificationBuff = explode('|', $queue['queue_from']);
                if (isset($getIdentificationBuff[2])) {
                    $identification = $getIdentificationBuff[2];
                }
            }
            if (strpos($queue['queue_from'], '|auth|')) {
                if (isset($exec_res_1[0])) {
                    if (strpos($exec_res_1[0], 'Enter the code')) {
                        set_status_queue('job_queues_auth', $SERVICE['DB'], 'D', false, $queue['id']);
                    } elseif (strpos($exec_res_1[0], 'ERROR')) {
                        set_status_queue('job_queues_auth', $SERVICE['DB'], 'E', false, $queue['id']);
                        _Module_telegram_set_next_status($identification, 'E_RESULT');
                    } else {
                        set_status_queue('job_queues_auth', $SERVICE['DB'], 'E', false, $queue['id']);
                        _Module_telegram_set_next_status($identification, 'E_DROID');
                    }
                } else {
                    set_status_queue('job_queues_auth', $SERVICE['DB'], 'E', false, $queue['id']);
                    _Module_telegram_set_next_status($identification, 'E_SYSTEM');
                }
            }
            if (strpos($queue['queue_from'], '|auth_code|')) {
                $queue_status    = 'D';
                if (isset($exec_res_1[0])) {
                    if (strpos($exec_res_1[0], 'Enter your password')) {
                        $queue_status    = 'E';
                        _Module_telegram_set_next_status($identification, 'E_RESULT');
                    } elseif (strpos($exec_res_1[0], 'PHONE_CODE_INVALID')) {
                        $queue_status    = 'E';
                        _Module_telegram_set_next_status($identification, 'E_RESULT');
                    } elseif (strpos($exec_res_1[0], 'ERROR')) {
                        $queue_status    = 'E';
                        _Module_telegram_set_next_status($identification, 'E_RESULT');
                    } else {
                        _Module_telegram_set_next_status($identification, 'Q_DONE');
                    }
                } else {
                    _Module_telegram_set_next_status($identification, 'E_DROID');
                }
                set_status_queue('job_queues_auth', $SERVICE['DB'], $queue_status, false, $queue['id']);
            }
            if (strpos($queue['queue_from'], '|start_swarm_php|')) {
                set_status_queue('job_queues_auth', $SERVICE['DB'], 'D', false, $queue['id']);
                _Module_telegram_set_next_status($identification, 'C_START');
            }
            sleep($SERVICE['small_sleep']);
            $check = get_queue('job_queues_auth', $SERVICE['DB'], false, $queue['id']);
            if (isset($check[0]['queue_status'])) {
                if ($check[0]['queue_status'] == 'S') {
                    set_status_queue('job_queues_auth', $SERVICE['DB'], 'N', false, $queue['id']);
                }
            }
        }
    }
    return true;
}

function _transit_SERVICE_log(&$SERVICE) {
    if ($SERVICE['enable_dump_log'] === true) {
        dumpLog($SERVICE, '___Service_loop_=_'.$SERVICE['service_loop'].'___', $SERVICE['dump_log_file']);
    }
    if ($SERVICE['error_log_file'] === true) {
        if (!empty($SERVICE['log']['error'])) {
            dumpLog($SERVICE, '___Service_loop_=_'.$SERVICE['service_loop'].'___', $SERVICE['error_log_file']);
        }
    }
}

function _clear_SERVICE(&$SERVICE) {
    $SERVICE['log']['dump']  = [];
    $SERVICE['log']['error'] = [];
    $SERVICE['log']['error'] = [];
    $SERVICE['queues']       = [];
    $SERVICE['sync_droids']['count_results'] = 0;
    $SERVICE['sync_droids']['count_execs']   = 0;
    $SERVICE['sync_droids']['execs']         = [];
    $SERVICE['sync_droids']['exec_results']  = [];

    if ($SERVICE['DB']) {
  //      mysqli_close($SERVICE['DB']);
    }
 //   $SERVICE['DB']        = null;
    $SERVICE['must_work'] = $SERVICE['droid_all'] = $SERVICE['droid_update'] = $SERVICE['droid_get_logs'] = $SERVICE['job_queues_auth'] = false;
    if (file_exists($SERVICE['job_queues_auth_file'])) {
        unlink($SERVICE['job_queues_auth_file']);
    }
    if (file_exists($SERVICE['droid_get_logs_file'])) {
        unlink($SERVICE['droid_get_logs_file']);
    }
    if (file_exists($SERVICE['droid_update_file'])) {
        unlink($SERVICE['droid_update_file']);
    }
    $SERVICE['can_work']  = true;
    return true;
}
