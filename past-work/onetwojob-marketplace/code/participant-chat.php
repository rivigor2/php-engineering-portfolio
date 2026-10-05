<?php
$user = __DB(['q_my' => "SELECT * FROM `users` WHERE `id` = '".$GLOBAL_VARS['AuthID']."';",
              'return_type' => 'r_fetch_assoc']);

if (empty($user)) {
    die('Critical! (1)');
}

$user            = $user[0];
$request         = $GLOBAL_VARS['request'];
$response        = ['status' => 'error', 'msg' => 'init', 'response' => []];
$jobs_request_id = 0;

if (isset($request['action']))          { $action = $request['action']; }                        else { _ajaxResponse(['status' => 'error', 'msg' => 'Error:action']); }
if (isset($request['jobs_request_id'])) { $jobs_request_id = (int)$request['jobs_request_id']; } else { _ajaxResponse(['status' => 'error', 'msg' => 'Error:jobs_request_id']); }

$jobs_request = __DB(['q_my' => "SELECT * FROM `jobs_request` WHERE `id` = '".$jobs_request_id."';",
                      'return_type' => 'r_fetch_assoc'
]);

if (empty($jobs_request)) {
    _ajaxResponse(['status' => 'error', 'msg' => 'Error:jobs_request']);
}

$jobs_request = $jobs_request[0];

if (!$GLOBAL_VARS['ADMIN']) {
    if (!$GLOBAL_VARS['OPERATOR']) {
        if ($jobs_request['vacancy_user_id'] != $user['id']) {
            if ($jobs_request['resume_user_id'] != $user['id']) {
                _ajaxResponse(['status' => 'error', 'msg' => 'Error:access 2']);
            }
        }
    }
}

if ($action == 'attachment_message') { // Добавление фаила

    if (!in_array($jobs_request['status'], [0, 2, 3, 6])) {
        if (!$GLOBAL_VARS['OPERATOR']) {
            if (!$GLOBAL_VARS['ADMIN']) {
                _ajaxResponse(['status' => 'error', 'msg' => 'Error:access 3']);
            }
        }
    }

    $accept_exts = ['jpg','jpeg','png','docx','doc','txt','xlsx','xls'];
    $docs_exts   = ['docx','doc','txt','xlsx','xls'];
    $pics_exts   = ['jpg','jpeg','png'];

    if (isset($request['to_id']))   { $to_id   = (int)$request['to_id']; } else { _ajaxResponse(['status' => 'error', 'msg' => 'Error:to']); }

    if (isset($_FILES['attachment']['tmp_name'])) {
        if ($_FILES['attachment']['tmp_name'] != '') {
            $ext = mb_strtolower(substr($_FILES['attachment']['name'], strrpos($_FILES['attachment']['name'], '.') + 1));
            if (in_array($ext, $accept_exts)) {
                if ($_FILES['attachment']['size'] < 134217728) {
                    $attachment_name = rand(1, 999) . rand(1, 999) . rand(1, 999) . '_' . str_replace(' ', '_', $_FILES['attachment']['name']);
                    move_uploaded_file($_FILES['attachment']['tmp_name'], $GLOBAL_VARS['attachment_path'] . $attachment_name);
                    if (in_array($ext, $docs_exts)) {
                        $message = '<a class="btn-learn-more report_link" href = "'.$GLOBAL_VARS['site_url'].'report_view/'.$attachment_name.'/attachment/" target="_blank" >'.$attachment_name.'</a>';
                    }
                    elseif (in_array($ext, $pics_exts)) {
                        $message = '<a class="btn-learn-more" href = "' . $GLOBAL_VARS['attachment_link'] . $attachment_name . '" target="_blank" ><img style = "width:200px;height:150px;" src = "' . $GLOBAL_VARS['attachment_link'] . $attachment_name . '" ></a>';
                    }

                    $result  = add_chat_message($jobs_request_id, $user['id'], $to_id, $message);

                    if ($result['status'] == 'error') {
                        _ajaxResponse($result);
                    }

                    $response['response'] = ['messages_id' => $result['messages_id']];
                    $response['msg']      = 'post_message';
                    $response['status']   = 'success';
                }
            }
        }
    }
}

if ($action == 'post_message') { // Добавление сообщения
    if (isset($request['to_id']))   { $to_id           = (int)$request['to_id']; } else { _ajaxResponse(['status' => 'error', 'msg' => 'Error:to']); }
    if (isset($request['message'])) { $message         = $request['message']; }    else { _ajaxResponse(['status' => 'error', 'msg' => 'Error:message']); }

    if (!in_array($jobs_request['status'], [0, 2, 3, 6])) {
        if (!$GLOBAL_VARS['OPERATOR']) {
            if (!$GLOBAL_VARS['ADMIN']) {
                _ajaxResponse(['status' => 'error', 'msg' => 'Error:access']);
            }
        }
    }

    if (!$GLOBAL_VARS['OPERATOR']) {
        if (!$GLOBAL_VARS['ADMIN']) {
            $message = filter_message($message);
        }
    }

    $result = add_chat_message($jobs_request_id, $user['id'], $to_id, $message);

    if ($result['status'] == 'error') {
        _ajaxResponse($result);
    }

    $response['response'] = ['messages_id' => $result['messages_id']];
    $response['msg']      = 'post_message';
    $response['status']   = 'success';
}

if ($action == 'get_messages_html') { // Получить список сообщений
    $html     = '';

    $messages = __DB(['q_my' => "SELECT * FROM `messages` WHERE `jobs_request_id` = '$jobs_request_id' AND `deleted` = '0' ORDER BY `id` ASC;",
                      'return_type' => 'r_fetch_assoc'
    ]);

    if (!empty($messages)) {
        foreach ($messages as $message) {
            $delete_msg = '';
            $details    = unserialize($message['details']);

            if ($GLOBAL_VARS['ADMIN'] or $GLOBAL_VARS['OPERATOR']) {
                $delete_msg = "<i onclick='delete_msg(".$message['id'].");' class='bi bi-trash' style = 'margin:5px;cursor:pointer;'></i>";
            }

            if ($GLOBAL_VARS['AuthID'] == $message['owner_id']) {
                $html .= "<div class='d-flex flex-row justify-content-start mb-4'>
                              ".$delete_msg."
                              <img src='".$GLOBAL_VARS['media_link'].$details['owner_avatar']."' alt='avatar 1' style='width: 45px; height: 100%;'>
                              <div>
                                <p class='small p-2 ms-3 mb-1 rounded-3' style='background-color: #f5f6f7;'>".nl2br($message['message'])."</p>
                                <p class='small ms-3 mb-3 rounded-3 text-muted'>(".$details['owner_name'].") ".$details['time']."</p>
                              </div>
                          </div>";
            } else {
                $html .= "<div class='d-flex flex-row justify-content-end mb-4 pt-1'>
                              <div>
                                <p class='small p-2 me-3 mb-1 text-white rounded-3 bg-primary'>".nl2br($message['message'])."</p>
                                <p class='small me-3 mb-3 rounded-3 text-muted d-flex justify-content-end'>(".$details['from_name'].") ".$details['time']."</p>
                              </div>
                              <img src='".$GLOBAL_VARS['media_link'].$details['owner_avatar']."' alt='avatar 1' style='width: 45px; height: 100%;'>
                              ".$delete_msg."
                          </div>";
            }
        }
    }

    $response['response'] = ['html' => $html];
    $response['msg']      = 'get_messages_html';
    $response['status']   = 'success';
}

if ($action == 'need_admin_in_chat') { // Позвать админа
    $update_jobs_request = __DB(['q_my' => "UPDATE `jobs_request` SET `need_admin_in_chat` = '1' WHERE `id` = '".$jobs_request_id."';",
                                 'return_type' => 'r_result'
    ]);

    if ($update_jobs_request) {
        send_message('OneTwoJob: В чате требуется ваше присутствие.', ['peers' => array_merge($GLOBAL_VARS['telegram_operators'], $GLOBAL_VARS['telegram_admins'])]);
    }

    $response['response'] = [];
    $response['msg']      = 'need_admin_in_chat';
    $response['status']   = 'success';
}

if ($action == 'get_history_html') { // Получить историю вакансии
    // add_jobs_history($history, $jobs_request_id = null, $jobs_id = null)

    $html = '';

    $jobs_history = __DB(['q_my' => "SELECT * FROM `jobs_history` WHERE `jobs_request_id` = '".$jobs_request_id."' OR `jobs_id` = '".$jobs_request['vacancy_id']."' ORDER BY `id` DESC;",
                          'return_type' => 'r_fetch_assoc'
    ]);

    if (!empty($jobs_history)) {
        foreach ($jobs_history as $job_history) {
            $history = unserialize($job_history['history']);
            $history_html = '';
            if (!empty($history)) {
                if (in_array('only_admin', $history)) {
                    if (!$GLOBAL_VARS['ADMIN']) {
                        if (!$GLOBAL_VARS['OPERATOR']) {
                            continue;
                        }
                    }
                }
                foreach ($history as $one) {
                    $history_html .= "$one" . PHP_EOL;
                }
            }
            $html .= "<div class='d-flex flex-row justify-content-start mb-4'>
                          <div>
                            <p class='small p-2 ms-3 mb-1 rounded-3' style='background-color: #f5f6f7;'>" . nl2br($history_html) . "</p>
                            <p class='small ms-3 mb-3 rounded-3 text-muted'>" . date('d-m-Y H:i:s', $job_history['timestamp']) . "</p>
                          </div>
                      </div>";
        }
    }

    $response['response'] = ['html' => $html];
    $response['msg']      = 'get_messages_html';
    $response['status']   = 'success';
}

if ($action == 'delete_msg') { // Удалить сообщение

    if (isset($request['msg_id'])) { $msg_id = (int)$request['msg_id']; } else { _ajaxResponse(['status' => 'error', 'msg' => 'Error:msg_id']); }

    if ($GLOBAL_VARS['ADMIN'] or $GLOBAL_VARS['OPERATOR']) {
        __DB(['q_my' => "UPDATE `messages` SET `deleted` = '1' WHERE `id` = '".$msg_id."';",
              'return_type' => 'r_result'
        ]);
    } else {
        _ajaxResponse(['status' => 'error', 'msg' => 'Error:Permission denied']);
    }

    $response['response'] = [];
    $response['msg']      = 'delete_msg';
    $response['status']   = 'success';
}

_ajaxResponse($response);

function _ajaxResponse($data) {
    dumpLog($data, 'ajax:response:data', 'ajax.log');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    die();
}

//INSERT INTO `messages` (`jobs_request_id`, `from`, `to`, `owner_id`, `message`, `timestamp`, `from_seen`, `to_seen`, `admin_seen`)
//VALUES ('1', '2', '3', '4', '5', '6', '7', '8', '9');
