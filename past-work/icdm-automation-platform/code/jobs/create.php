<?php
$identification = 'Индификатор новой задачи(ОБЯЗАТЕЛЬНО нужно поменять, иначе будет дубликат!)';
$content = [];
$content['droid']     = ['0'];
$content['type_code'] = '0';
$content['message']   = ['0'];
$content['user']      = ['0'];
$content['group']     = ['0'];

$content['interval']    = '0';
$content['count_users'] = '0';
$content['limit_users'] = '0';


$module['response']['inserted_id'] = __DB(['q_name' => 'q_951',
    'q_args' => [$module['module'],
                 $identification,
                 $module['type'],
                 serialize($content),
                 serialize(['Q_END']),
                 serialize([time()])],
    'return_type' => 'r_insert_id'
]);

if ($module['response']['inserted_id'] === 'dublicate') {
    $module['response']['warning'] = 'dublicate';
    $getModuleItem = __DB(['q_name' => 'q_966',
        'q_args' => [$module['module'], $identification],
        'return_type' => 'r_fetch_assoc'
    ]);
} else {
    $getModuleItem = __DB(['q_name' => 'q_954',
        'q_args' => [$module['module'], $module['response']['inserted_id']],
        'return_type' => 'r_fetch_assoc'
    ]);
}

if ($getModuleItem === false) {
    $module['response']['status_msgs'][] = 'ajax:_create:go:row_not_found:id=' . $module['id'];
    _ajaxResponse($module);
} else {
    $module['response']['data'] = $getModuleItem;
}

if ($module['response']['data'] !== false) {
    $module['id']                 = $module['response']['data'][0]['id'];
    $module['identification']     = $module['response']['data'][0]['identification'];
    $module['content']            = unserialize($module['response']['data'][0]['content']);
    $module['type']               = $module['response']['data'][0]['type'];
    $module['reload']             = 'edit';
    $module['response']['status'] = 'success';
}
