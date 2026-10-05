<?php
$getModuleItem = __DB(['q_name' => 'q_954',
    'q_args' => [$module['module'], $module['id']],
    'return_type' => 'r_fetch_assoc'
]);

if ($getModuleItem === false) {
    $module['response']['status_msgs'][] = 'ajax:_start:row_not_found:id=' . $module['id'];
    _ajaxResponse($module);
}

$module['response']['dbs_contents'] = __DB(['q_name' => 'special|dbs_contents_by_job_content',
                                            'q_args' => [$getModuleItem[0]['content']]]);

if (empty($module['response']['dbs_contents'])) {
    $module['response']['status_msgs'][] = 'ajax:_start:empty_dbs_contents:id=' . $module['id'];
    _ajaxResponse($module);
}

$module['response']['data'] = __DB(['q_name' => 'q_950',
                                    'q_args' => [$module['module'], $module['type']],
                                    'return_type' => 'r_fetch_assoc'
]);

if ($module['response']['data'] !== false) {
    $module['response']['status'] = 'success';
    $module['reload']             = 'list';
    _Module_telegram_set_next_status($module['id'], 'Q_START');
    _job_queues_droids_set_next_status($module, 'Q_START');
}
