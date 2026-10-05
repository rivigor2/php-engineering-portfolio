<?php
$v 			         = [];
$v['status']         = '';
$v['msg']            = [];
$v['msg']['texts'][] = '';
$v['msg']['color']   = '';
$result              = false;
$report_id           = false;
$job_request_status  = false;
$job_status          = false;
$changed             = false;
$job_request_id      = false;
$type                = 'GET';

if (!$GLOBAL_VARS['AuthID']) {
    die('Error:unauth');
}

if (isset($GLOBAL_VARS['request']['job_request_status'])) {
    $job_request_status = (int)$GLOBAL_VARS['request']['job_request_status'];
    $type               = 'POST';
}

if (!$job_request_status) {
    if (isset($GLOBAL_VARS['args'][2])) {
        $job_request_status = (int)$GLOBAL_VARS['args'][2];
    }
}

if (isset($GLOBAL_VARS['request']['job_request_id'])) {
    $job_request_id = (int)$GLOBAL_VARS['request']['job_request_id'];
}

if (!$job_request_id) {
    if (isset($GLOBAL_VARS['args'][1])) {
        $job_request_id = (int)$GLOBAL_VARS['args'][1];
    }
}

if ($job_request_id) {
    if ($job_request_status) {
        if ($type == 'GET') {
            $jobs_request = __DB(['q_my' => "SELECT * FROM `jobs_request` WHERE `id` = '".$job_request_id."' AND `vacancy_user_id` = '".$GLOBAL_VARS['AuthID']."';",
                'return_type' => 'r_fetch_assoc'
            ]);
            if (empty($jobs_request)) {
                die('Error:no_jobs_request');
            }
            $job_id = $jobs_request[0]['vacancy_id'];
            $job = __DB(['q_my' => "SELECT * FROM `jobs` WHERE `id` = '".$job_id."' AND `user_id` = '".$GLOBAL_VARS['AuthID']."';",
                'return_type' => 'r_fetch_assoc'
            ]);
            if (empty($job)) {
                die('Error:no_job');
            }
            $job      = $job[0];
            $job_data = unserialize($job['job_data']);

            if (isset($job_data['vacancy_online_pay_type'])) {
                $vacancy_online_pay_type = $job_data['vacancy_online_pay_type'];
            } else {
                $vacancy_online_pay_type = 1;
            }

            if ($job_request_status == '5') { // Завершено работодателем.
                if ($vacancy_online_pay_type == 0) {
                    $pay_jobs_request = pay_jobs_request_pre($job_request_id);
                }
                if ($vacancy_online_pay_type == 1) {
                    $pay_jobs_request = pay_jobs_request($job_request_id);
                }
                if ($pay_jobs_request['status'] == true) {
                    $result = __DB(['q_my' => "UPDATE `jobs_request` SET `status` = '".$job_request_status."' WHERE `id` = '".$job_request_id."' AND `vacancy_user_id` = '".$GLOBAL_VARS['AuthID']."';",
                                    'return_type' => 'r_query'
                    ]);
                    $job_status = 6; // Завершено.
                    $changed    = true;
                } else {
                    $v['msg']['texts'][] = $pay_jobs_request['status_text'];
                }
            } else {
                if ($job_request_status == 2) { // В работе соискателем.
                    if (in_array($job['status'], [1])) {
                        $billing_request = __DB(['q_my' => "SELECT * FROM `billing_request` WHERE `object_type` = 'pay_reserv_project' AND `object_id` = '".$job['id']."';",
                                                 'return_type' => 'r_fetch_assoc'
                        ]);
                        if (empty($billing_request)) {
                            if ($vacancy_online_pay_type == 1) {
                                $billing_result = reserv_job_balance($job['id']);
                                if ($billing_result['status'] == true) {
                                    $job_status = 5; // В работе.
                                } else {
                                    if ($billing_result['status_num'] == 5) {
                                        redirect('add_balance/?info_add_sum=' . $billing_result['deff_summ']);
                                    }
                                    $v['msg']['texts'][] = $billing_result['status_text'];
                                }
                            }
                            if ($vacancy_online_pay_type == 0) {
                                $billing_result = reserv_job_pre_balance($job['id']);
                                if ($billing_result['status'] == true) {
                                    $job_status = 5; // В работе.
                                } else {
                                    if ($billing_result['status_num'] == 5) {
                                        redirect('add_balance/?info_add_sum=' . $billing_result['deff_summ']);
                                    }
                                    $v['msg']['texts'][] = $billing_result['status_text'];
                                }
                            }
                        } else {
                            $job_status = 5; // В работе.
                        }
                        if ($job_status == 5) {
                            $result = __DB(['q_my' => "UPDATE `jobs_request` SET `status` = '".$job_request_status."' WHERE `id` = '".$job_request_id."' AND `vacancy_user_id` = '".$GLOBAL_VARS['AuthID']."';",
                                            'return_type' => 'r_query'
                            ]);
                            $changed = true;
                        }
                    } else {
                        $v['msg']['texts'][] = 'Исполнитель уже назначен.';
                    }
                } else {
                    if ($job_request_status == 6) { // Отменено работодателем по завершению.
                        $job_status = 7; // Спор.
                        add_chat_message($job_request_id,
                            get_role_id($GLOBAL_VARS['ADMIN_ROLES'][0]),
                            __DB(['q_my' => "SELECT * FROM `jobs_request` WHERE `id` = '".$job_request_id."';", 'return_type' => 'r_fetch_assoc'])[0]['resume_user_id'],
                            "Принятие решения по данной сделке будет осуществлено администрацией проекта через 24 часа, в случае несогласия сообщите в данном чате. (Сообщение для соискателя.)"
                        );
                        add_chat_message($job_request_id,
                            get_role_id($GLOBAL_VARS['ADMIN_ROLES'][0]),
                            $GLOBAL_VARS['AuthID'],
                            "Принятие решения по данной сделке будет осуществлено администрацией проекта через 24 часа, в случае несогласия сообщите в данном чате. (Сообщение для работодателя.)"
                        );
                    }
                    if ($job_request_status == 4) { // Отменено соискателем.
                        $job_status = 1; // Опубликовано.
                    }
                    $result = __DB(['q_my' => "UPDATE `jobs_request` SET `status` = '".$job_request_status."' WHERE `id` = '".$job_request_id."' AND `vacancy_user_id` = '".$GLOBAL_VARS['AuthID']."';",
                                    'return_type' => 'r_query'
                    ]);
                    $changed = true;
                }
            }
            if ($job_status) {
                $job_id = __DB(['q_my' => "SELECT * FROM `jobs_request` WHERE `id` = '".$job_request_id."';", 'return_type' => 'r_fetch_assoc'])[0]['vacancy_id'];
                __DB(['q_my' => "UPDATE `jobs` SET `status` = '".$job_status."' WHERE `id` = '".$job_id."' AND `user_id` = '".$GLOBAL_VARS['AuthID']."';",
                      'return_type' => 'r_query'
                ]);
                add_jobs_history(['Статус вакансии изминен на ' . transStatusJob($job_status)['text'],
                                  'UID Вакансии: ' . $job_id],
                null, $job_id);
            }
            add_jobs_history(['Статус отклика изминен на ' . transStatusJobReuest($job_request_status)['text'],
                              'UID Отклика: '               . $job_request_id],
            $job_request_id);
        } else {
            if ($job_request_status == '3') {
                $report_ok = false;
                if (isset($_FILES['jobs_report']['tmp_name'])) {
                    if ($_FILES['jobs_report']['tmp_name'] != '') {
                        $ext = mb_strtolower(substr($_FILES['jobs_report']['name'], strrpos($_FILES['jobs_report']['name'], '.') + 1));
                        if (in_array($ext, ['docx'])) {
                            if ($_FILES['jobs_report']['size'] < 134217728) {
                                $jobs_report_name = rand(1, 999) . rand(1, 999) . rand(1, 999) . $_FILES['jobs_report']['name'];
                                move_uploaded_file($_FILES['jobs_report']['tmp_name'], $GLOBAL_VARS['report_path'] . $jobs_report_name);
                                $report_id = __DB(['q_my' => "INSERT INTO `jobs_report` (`jobs_request_id`, `name`, `timestamp`)
                                                              VALUES ('".$job_request_id."', '".$jobs_report_name."', '".time()."');",
                                                   'return_type' => 'r_insert_id'
                                ]);
                                if ($report_id) {
                                    add_jobs_history(['Отчет успешно добавлен.',
                                                      'UID Отклика: ' . $job_request_id,
                                                      'UID Записи отчета: ' . $report_id,
                                                      'Ссылка на отчет: ' . get_report_link($job_request_id)],
                                    $job_request_id);
                                    $report_ok = true;
                                }
                            }
                        }
                    }
                }
                if ($report_ok) {
                    $result = __DB(['q_my' => "UPDATE `jobs_request` SET `status` = '".$job_request_status."' WHERE `id` = '".$job_request_id."' AND `resume_user_id` = '".$GLOBAL_VARS['AuthID']."';",
                                    'return_type' => 'r_query'
                    ]);
                    if ($result) {
                        $changed = true;
                        send_message('notification_job', ['user_ids' => [__DB(['q_my' => "SELECT * FROM `jobs_request` WHERE `id` = '".$job_request_id."';", 'return_type' => 'r_fetch_assoc'])[0]['vacancy_user_id']]]);
                        add_jobs_history(['Статус отклика изминен на:' . transStatusJobReuest($job_request_status)['text'],
                                          'UID Отклика: '               . $job_request_id],
                        $job_request_id);
                    }
                } else {
                    $v['msg']['texts'][] = 'Требуется прикрепить отчет(Формат только docx) (Размер не больше 16мб).';
                }
            }
            if ($job_request_status == '4') { // отмена соискателем
                $job_status = 1; // Опубликовано.
                $changed    = true;
                $job_id     = __DB(['q_my' => "SELECT * FROM `jobs_request` WHERE `id` = '".$job_request_id."';", 'return_type' => 'r_fetch_assoc'])[0]['vacancy_id'];
                __DB(['q_my' => "UPDATE `jobs` SET `status` = '".$job_status."' WHERE `id` = '".$job_id."' AND `user_id` = '".__DB(['q_my' => "SELECT * FROM `jobs_request` WHERE `id` = '".$job_request_id."';", 'return_type' => 'r_fetch_assoc'])[0]['vacancy_user_id']."';",
                      'return_type' => 'r_query'
                ]);
                $result = __DB(['q_my' => "UPDATE `jobs_request` SET `status` = '".$job_request_status."' WHERE `id` = '".$job_request_id."' AND `resume_user_id` = '".$GLOBAL_VARS['AuthID']."';",
                                'return_type' => 'r_query'
                ]);
                add_jobs_history(['Статус вакансии изминен на ' . transStatusJob($job_status)['text'],
                                  'UID Вакансии: ' . $job_id],
                null, $job_id);
                add_jobs_history(['Статус отклика изминен на ' . transStatusJobReuest($job_request_status)['text'],
                                  'UID Отклика: '               . $job_request_id],
                $job_request_id);
            }
        }
    }
}

if ($changed) {
    add_request_alarm($GLOBAL_VARS['USER'], $job_request_id);
}

if ($result) {
    $v['msg']['texts'][] = 'Статус успешно изменен.';
}
