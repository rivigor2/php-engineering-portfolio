<?php
$v 			         = [];
$v['status']         = '';
$v['msg']            = [];
$v['msg']['texts'][] = '';
$v['msg']['color']   = '';

$default                    = '';
$q			                = [];
$q['vacancy_name']		    = $default;
$q['vacancy_spec']          = $default;
$q['vacancy_condidate_pay'] = $default;
$q['vacancy_text'] 	        = $default;
$q['vacancy_pay']           = $default;
$q['vacancy_currency']      = $default;
$q['vacancy_pay_type']      = $default;
$q['vacancy_country']       = $default;
$q['vacancy_city']          = $default;
$q['vacancy_conditions']    = $default;
$q['vacancy_reports'] 	    = $default;
$q['vacancy_estimate']      = $default;

if (isset($GLOBAL_VARS['request']['vacancy_name']))          { $q['vacancy_name']          = protectionArgs($GLOBAL_VARS['request']['vacancy_name'],          $DB); }
if (isset($GLOBAL_VARS['request']['vacancy_spec']))          { $q['vacancy_spec']          = protectionArgs($GLOBAL_VARS['request']['vacancy_spec'],          $DB); }
if (isset($GLOBAL_VARS['request']['vacancy_condidate_pay'])) { $q['vacancy_condidate_pay'] = protectionArgs($GLOBAL_VARS['request']['vacancy_condidate_pay'], $DB); }
if (isset($GLOBAL_VARS['request']['vacancy_text']))          { $q['vacancy_text']          = protectionArgs($GLOBAL_VARS['request']['vacancy_text'],          $DB); }
if (isset($GLOBAL_VARS['request']['vacancy_pay']))           { $q['vacancy_pay']           = protectionArgs($GLOBAL_VARS['request']['vacancy_pay'],           $DB); }
if (isset($GLOBAL_VARS['request']['vacancy_currency']))      { $q['vacancy_currency']      = protectionArgs($GLOBAL_VARS['request']['vacancy_currency'],      $DB); }
if (isset($GLOBAL_VARS['request']['vacancy_pay_type']))      { $q['vacancy_pay_type']      = protectionArgs($GLOBAL_VARS['request']['vacancy_pay_type'],      $DB); }
if (isset($GLOBAL_VARS['request']['vacancy_country']))       { $q['vacancy_country']       = protectionArgs($GLOBAL_VARS['request']['vacancy_country'],       $DB); }
if (isset($GLOBAL_VARS['request']['vacancy_city']))          { $q['vacancy_city']          = protectionArgs($GLOBAL_VARS['request']['vacancy_city'],          $DB); }
if (isset($GLOBAL_VARS['request']['vacancy_conditions']))    { $q['vacancy_conditions']    = protectionArgs($GLOBAL_VARS['request']['vacancy_conditions'],    $DB); }
if (isset($GLOBAL_VARS['request']['vacancy_reports']))       { $q['vacancy_reports']       = protectionArgs($GLOBAL_VARS['request']['vacancy_reports'],       $DB); }
if (isset($GLOBAL_VARS['request']['vacancy_estimate']))      { $q['vacancy_estimate']      = protectionArgs($GLOBAL_VARS['request']['vacancy_estimate'],      $DB); }
if (isset($GLOBAL_VARS['request']['vacancy_company']))       { $q['vacancy_company']       = protectionArgs($GLOBAL_VARS['request']['vacancy_company'],       $DB); }

if (!is_numeric($q['vacancy_pay']) || $q['vacancy_pay'] < 0) {
    $v['msg']['texts'][]  = 'Не указана стоимость онлайн-вакансии'; $v['status'] = 'error';
}

$q['vacancy_online_pay_type'] = __DB(['q_my' => "SELECT * FROM `users` WHERE `id` = '".$GLOBAL_VARS['AuthID']."';", 'return_type' => 'r_fetch_assoc'])[0]['vacancy_online_pay_type'];
$settings                     = get_settings();

if ($q['vacancy_online_pay_type'] == 0) {
    if (($settings['cost_online_vacancy']) > $GLOBAL_VARS['balance']) {
        $v['msg']['texts'][]  = 'Стоимость коммисии вакансии больше чем ваш баланс, пополните баланс.'; $v['status'] = 'error';
    }
}

if ($v['status'] != 'error') {
    $inserted_id = __DB(['q_my' => "INSERT INTO `jobs` (`user_id`, `job_data`, `job`, `name`, `status`, `timestamp`)
                                    VALUES ('".$GLOBAL_VARS['AuthID']."',
                                            '".serialize($q)."',
                                            'vacancy',
                                            '".$q['vacancy_name']."',
                                            '0',
                                            '".time()."');",
                         'return_type' => 'r_insert_id'
    ]);
    if ($inserted_id === 'dublicate') {
        $v['msg']['texts'][]  = 'Такая запись уже есть.'; $v['status'] = 'error';
    } else {
        if (check_job_data_serialize($inserted_id, true)) {
            if ($q['vacancy_online_pay_type'] == 0) {
                $billing_result = pay_cost_pre_vacancy($inserted_id);
                if ($billing_result['status'] == true) {
                    add_jobs_history(['Онлайн-вакансия успешно создана.',
                        'UID Вакансии: ' . $inserted_id],
                        null, $inserted_id);
                    $v['msg']['texts'][]  = 'Вакансия успешно создана и отправлена на модерацию..'; $v['status'] = 'success';
                } else {
                    if ($billing_result['status_num'] == 5) {
                        redirect('add_balance/?info_add_sum=' . $billing_result['deff_summ']);
                    }
                    $v['msg']['texts'][] = $billing_result['status_text']; $v['status'] = 'error';
                }
            } else {
                add_jobs_history(['Онлайн-вакансия успешно создана.',
                    'UID Вакансии: ' . $inserted_id],
                    null, $inserted_id);
                $v['msg']['texts'][]  = 'Вакансия успешно создана и отправлена на модерацию..'; $v['status'] = 'success';
            }
        } else {
            $v['msg']['texts'][]  = 'Ошибка записи в БД, проверьте входные данные.'; $v['status'] = 'error';
        }
    }
}
