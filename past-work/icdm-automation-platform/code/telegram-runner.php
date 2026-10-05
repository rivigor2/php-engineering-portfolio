<?php
set_time_limit(60);
//error_reporting(E_ALL);
error_reporting(0);
require_once ('dump.php');

require '../vendor/autoload.php';

$api_id            = getenv('TELEGRAM_API_ID') ?: '000000';
$api_hash          = getenv('TELEGRAM_API_HASH') ?: 'replace-in-local-env';
$MadelineProto     = false;
$log = [];
$log['arguments']['argv'] = [];
$log['arguments']['get']  = [];
$log['arguments']['post'] = [];
$log['workflow']          = [];
$log['error']             = false;
$log['response']          = '';
$log['work_type']         = [];
$log['work_type'][]       = 'none';

if (isset($argv) && !empty($argv)) {
    $log['arguments']['argv'] = $argv;
}
if (isset($_GET) && !empty($_GET)) {
    $log['arguments']['get'] = $_GET;
}
if (isset($_POST) && !empty($_POST)) {
    $log['arguments']['post'] = $_POST;
}

$log['inputArguments'] = getInput($log['arguments']);

if (!empty($log['inputArguments']['IN_start'])) {
    $log['work_type'][] = 'start_swarm';
    $log['error']      = true;
    $log['response']   .= '|signal_allready_created|';
    if (!file_exists('/srv/icdm/start.swarm')) {
        $fp = fopen('/srv/icdm/start.swarm', "w");
        fwrite($fp, 'go go go');
        fclose($fp);
        $log['error']      = false;
        $log['response']   .= '|signal_created|';
    }
    do_response($log);
}

if (!empty($log['inputArguments']['IN_checkStatus'])) {
    set_time_limit(10);
}

if (!empty($log['inputArguments']['IN_phone'])) {
    $phone = $log['inputArguments']['IN_phone'];
    $session = $phone.'.session';
    if(file_exists( $session ) ) {
        $MadelineProto = new \danog\MadelineProto\API($session);
        $MadelineProto->start();
        $log['workflow'][] = 'start->get->session:'.$session;
        $log['response']   .= '|success_p_get_session|';
        $log['work_type'][]  = 'get_session';
    } else {
        $settings=[];
        $settings['app_info']['api_id']   = $api_id;
        $settings['app_info']['api_hash'] = $api_hash;
        // Иначе создать новую сессию
        $MadelineProto = new \danog\MadelineProto\API($session, $settings);
        $MadelineProto->start();
        $MadelineProto->session = $session;
        $MadelineProto->serialize();
        $log['workflow'][] = 'start->new->session:'.$session;
        $log['response']   .= '|success_p_new_session|';
        $log['work_type'][]  = 'start_session';
    }
    $log['response']   .= '|success_p_start|';
} else {
    $log['workflow'][] = 'start->error->session:no_phone';
    $log['error']      = true;
    $log['response']   .= '|error_no_p|';
}

if (!empty($log['inputArguments']['IN_checkStatus'])) {
    $isError = true;
    if ($MadelineProto) {
        try {
            $me = $MadelineProto->getSelf();
            if (!$me['bot']) {
                $isError = false;
            }
        } finally {
            if ($isError) {
                $log['response'] = 'error';
                do_response($log);
            } else {
                $log['response'] = 'success';
                do_response($log);
            }
        }
    } else {
        $log['response'] = 'error';
        do_response($log);
    }
}

if (!empty($log['inputArguments']['IN_joinChannel'])) {
    $log['work_type'][] = 'joinChannel';
    if ($MadelineProto) {
        if (isset($log['arguments']['post']['channel'])) {
            try {
                $log['response'] = $MadelineProto->channels->joinChannel(['channel' => $log['arguments']['post']['channel']]);
            } catch (\danog\MadelineProto\RPCErrorException $e) {
                $log['response'] = 'Error|RPCErrorException|joinChannel';
                if (isset($e->rpc)) {
                    if (!empty($e->rpc)) {
                        $log['response'] = 'Error|joinChannel|' . $e->rpc;
                    }
                }
                $log['RPCErrorException:joinChannel'] = $e;
                do_response($log);
            }
        } else {
            $log['response'] = 'Error|channel_post_not_isset';
        }
    } else {
        $log['response'] = 'Error|MadelineProto_not_init';
    }
    do_response($log);
}

if (!empty($log['inputArguments']['IN_inviteToChannel'])) {
    $log['work_type'][] = 'inviteToChannel';
    if ($MadelineProto) {
        if (isset($log['arguments']['post']['channel'])) {
            if (isset($log['arguments']['post']['users'])) {
                try {
                    $log['response'] = $MadelineProto->channels->inviteToChannel(['channel' => $log['arguments']['post']['channel'], 'users' => [$log['arguments']['post']['users']]]);
                } catch (\danog\MadelineProto\RPCErrorException $e) {
                    $log['response'] = 'Error|RPCErrorException|inviteToChannel';
                    if (isset($e->rpc)) {
                        if (!empty($e->rpc)) {
                            $log['response'] = 'Error|inviteToChannel|' . $e->rpc;
                        }
                    }
                    $log['RPCErrorException:inviteToChannel'] = $e;
                    do_response($log);
                }
            } else {
                $log['response'] = 'Error|users_post_not_isset';
            }
        } else {
            $log['response'] = 'Error|channel_post_not_isset';
        }
    } else {
        $log['response'] = 'Error|MadelineProto_not_init';
    }
    do_response($log);
}

if (!empty($log['inputArguments']['IN_sendMessage'])) {
    $log['work_type'][] = 'sendMessage';
    if ($MadelineProto) {
        if (isset($log['arguments']['post']['peer'])) {
            if (isset($log['arguments']['post']['message'])) {
                try {
                    $log['response'] = $MadelineProto->messages->sendMessage(['peer' => $log['arguments']['post']['peer'], 'message' => $log['arguments']['post']['message']]);
                } catch (\danog\MadelineProto\RPCErrorException $e) {
                    $log['response'] = 'Error|RPCErrorException|sendMessage';
                    if (isset($e->rpc)) {
                        if (!empty($e->rpc)) {
                            $log['response'] = 'Error|sendMessage|' . $e->rpc;
                        }
                    }
                    $log['RPCErrorException:sendMessage'] = $e;
                    do_response($log);
                }
            } else {
                $log['response'] = 'Error|message_post_not_isset';
            }
        } else {
            $log['response'] = 'Error|peer_post_not_isset';
        }
    } else {
        $log['response'] = 'Error|MadelineProto_not_init';
    }
    do_response($log);
}

if (!empty($log['inputArguments']['IN_getPwrChat'])) {
    $log['work_type'][] = 'getPwrChat';
    if ($MadelineProto) {
        if (isset($log['arguments']['post']['peer'])) {
            try {
                $log['response'] = $MadelineProto->getPwrChat($log['arguments']['post']['peer']);
            } catch (\danog\MadelineProto\RPCErrorException $e) {
                $log['response'] = 'Error|RPCErrorException|getPwrChat';
                if (isset($e->rpc)) {
                    if (!empty($e->rpc)) {
                        $log['response'] = 'Error|getPwrChat|' . $e->rpc;
                    }
                }
                $log['RPCErrorException:getPwrChat'] = $e;
                do_response($log);
            }
        } else {
            $log['response'] = 'Error|peer_post_not_isset';
        }
    } else {
        $log['response'] = 'Error|MadelineProto_not_init';
    }
    do_response($log);
}

do_response($log);

function do_response($log) {
    dumpLog($log, '$log', 'droid.log');
    if (!in_array('start_session', $log['work_type'])) {
        echo serialize($log['response']);
        die();
    }
}

function getInput($arguments = []) {
    $result                       = [];
    $result['IN_phone']           = '';
    $result['IN_start']           = '';
    $result['IN_joinChannel']     = '';
    $result['IN_inviteToChannel'] = '';
    $result['IN_sendMessage']     = '';
    $result['IN_getPwrChat']      = '';
    $result['IN_checkStatus']     = '';
    $result['IN_use']      = [];
    $result['IN_use']['p'] = '';
    $result['IN_use']['s'] = '';
    $result['IN_use']['j'] = '';
    $result['IN_use']['i'] = '';
    $result['IN_use']['m'] = '';
    $result['IN_use']['g'] = '';
    $result['IN_use']['w'] = '';

    if (!empty($arguments['argv'])) {
        foreach ($arguments['argv'] as $keyArg => $arg) {
            $nextArg = $keyArg + 1;
            if ($arg == '-p' && isset($arguments['argv'][$nextArg])) {
                $result['IN_phone']    = $arguments['argv'][$nextArg];
                $result['IN_use']['p'] = 'argv';
            }
            if ($arg == '-s' && isset($arguments['argv'][$nextArg])) {
                $result['IN_start']    = $arguments['argv'][$nextArg];
                $result['IN_use']['s'] = 'argv';
            }
            if ($arg == '-j' && isset($arguments['argv'][$nextArg])) {
                $result['IN_joinChannel']  = $arguments['argv'][$nextArg];
                $result['IN_use']['j'] = 'argv';
            }
            if ($arg == '-i' && isset($arguments['argv'][$nextArg])) {
                $result['IN_inviteToChannel'] = $arguments['argv'][$nextArg];
                $result['IN_use']['i'] = 'argv';
            }
            if ($arg == '-m' && isset($arguments['argv'][$nextArg])) {
                $result['IN_sendMessage'] = $arguments['argv'][$nextArg];
                $result['IN_use']['m'] = 'argv';
            }
            if ($arg == '-g' && isset($arguments['argv'][$nextArg])) {
                $result['IN_getPwrChat'] = $arguments['argv'][$nextArg];
                $result['IN_use']['g'] = 'argv';
            }
            if ($arg == '-w' && isset($arguments['argv'][$nextArg])) {
                $result['IN_checkStatus'] = $arguments['argv'][$nextArg];
                $result['IN_use']['w'] = 'argv';
            }
        }
    }

    if (!empty($arguments['get'])) {
        if (isset($arguments['get']['p'])) {
            $result['IN_phone']    = $arguments['get']['p'];
            $result['IN_use']['p'] = 'get';
        }
        if (isset($arguments['get']['s'])) {
            $result['IN_start']    = $arguments['get']['s'];
            $result['IN_use']['s'] = 'get';
        }
        if (isset($arguments['get']['j'])) {
            $result['IN_joinChannel']       = $arguments['get']['j'];
            $result['IN_use']['j'] = 'get';
        }
        if (isset($arguments['get']['i'])) {
            $result['IN_inviteToChannel']     = $arguments['get']['i'];
            $result['IN_use']['i'] = 'get';
        }
        if (isset($arguments['get']['m'])) {
            $result['IN_sendMessage']       = $arguments['get']['m'];
            $result['IN_use']['m'] = 'get';
        }
        if (isset($arguments['get']['g'])) {
            $result['IN_getPwrChat']     = $arguments['get']['g'];
            $result['IN_use']['g'] = 'get';
        }
        if (isset($arguments['get']['w'])) {
            $result['IN_checkStatus']     = $arguments['get']['w'];
            $result['IN_use']['w'] = 'get';
        }
    }

    if (!empty($arguments['post'])) {
        if (isset($arguments['post']['p'])) {
            $result['IN_phone']    = $arguments['post']['p'];
            $result['IN_use']['p'] = 'post';
        }
        if (isset($arguments['post']['s'])) {
            $result['IN_start']    = $arguments['post']['s'];
            $result['IN_use']['s'] = 'post';
        }
        if (isset($arguments['post']['j'])) {
            $result['IN_joinChannel']       = $arguments['post']['j'];
            $result['IN_use']['j'] = 'post';
        }
        if (isset($arguments['post']['i'])) {
            $result['IN_inviteToChannel']     = $arguments['post']['i'];
            $result['IN_use']['i'] = 'post';
        }
        if (isset($arguments['post']['m'])) {
            $result['IN_sendMessage']    = $arguments['post']['m'];
            $result['IN_use']['m'] = 'post';
        }
        if (isset($arguments['post']['g'])) {
            $result['IN_getPwrChat']    = $arguments['post']['g'];
            $result['IN_use']['g'] = 'post';
        }
        if (isset($arguments['post']['w'])) {
            $result['IN_checkStatus']    = $arguments['post']['w'];
            $result['IN_use']['w'] = 'post';
        }
    }

    return $result;
}
