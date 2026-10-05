<?php

function billing_get_balance(int $user_id) {
    $balance = 0;
    $billing = __DB(['q_my' => "SELECT * FROM `billing` WHERE `user_id` = '".$user_id."';",
                     'return_type' => 'r_fetch_assoc'
    ]);
    if (!empty($billing)) {
        $balance = $billing[0]['balance'];
    }
    return $balance;
}

function billing_add_balance(int $user_id, $add_summ, int $request_id = 0) {
    $result           = false;
    $balance_summ     = billing_get_balance($user_id);
    $balance_summ_add = $balance_summ + $add_summ;
    $result_add = __DB(['q_my' => "UPDATE `billing` SET `balance` = '".$balance_summ_add."' WHERE `user_id` = '".$user_id."';",
                        'return_type' => 'r_query'
    ]);
    if ($result_add) {
        $result = __DB(['q_my' => "INSERT INTO `billing_history` (`user_id`, `balance_before`, `balance_add`, `balance`, `timestamp`, `billing_request_id`)
                                   VALUES ('".$user_id."', '".$balance_summ."', '".$add_summ."', '".$balance_summ_add."', '".time()."', '".$request_id."');",
                        'return_type' => 'r_query'
        ]);
    }
    return $result;
}

function billing_request_add(int $user_id, $add_summ, $details = [], $status = 0, $admin_id = 0, $request_hash = 0, $object_id = 0, $object_type = '', $object_name = '') {
    if ($request_hash === 0) {
        $request_hash = md5(time().rand(1,999));
    }
    return __DB(['q_my' => "INSERT INTO `billing_request` (`user_id`, `balance_add`, `timestamp`, `status`, `admin_id`, `request_details`, `request_hash`, `object_id`, `object_type`, `object_name`)
                            VALUES ('".$user_id."', '".$add_summ."', '".time()."', '".$status."', '".$admin_id."', '".serialize($details)."', '".$request_hash."', '".$object_id."', '".$object_type."', '".$object_name."');",
                 'return_type' => 'r_insert_id'
    ]);
}

function billing_pay_jobs_request(int $user_id, $summ, $details = [], $request_hash = 0, $object_id = 0, $object_type = '', $object_name = '') {
    $request_id = billing_request_add($user_id, $summ, $details, 1, get_role_id(get_GLOBAL_VARS()['BILLING_BALANCE_ROLE']), $request_hash, $object_id, $object_type, $object_name);

    if (!$request_id) {
        return false;
    }
    if ($request_id == 'dublicate') {
        return false;
    }
    $billing_add_balance = billing_add_balance($user_id, $summ, $request_id);
    if (!$billing_add_balance) {
        return false;
    }
    return $request_id;
}

function buy_standart_resume(int $job_id, int $buy_user_id) {
    $result                = [];
    $result['status']      = false;
    $result['status_num']  = 1;
    $result['status_text'] = 'Биллинг резерв: Ошибка при инициализации.';

    $job = __DB(['q_my' => "SELECT * FROM `jobs` WHERE `id` = '".$job_id."';",
                 'return_type' => 'r_fetch_assoc'
    ]);

    if (empty($job)) {
        $result['status_text'] = 'Биллинг: Нет резюме.';
        return $result;
    }

    $job = $job[0];

    if ($job['status'] != 1) {
        $result['status_text'] = 'Биллинг: не верный статсус резюме.';
        return $result;
    }

    $cost_standard_resume = get_settings()['cost_standard_resume'];
    $buy_user_balance     = billing_get_balance($buy_user_id);

    if ($cost_standard_resume <= 0) {
        $result['status_text'] = 'Биллинг: сумма меньше или ровна 0.';
        return $result;
    }

    if ($cost_standard_resume > $buy_user_balance) {
        $result['status_text'] = 'Биллинг: Недостаточно средств на балансе пользователя.';
        $result['status_num']  = 5;
        $result['deff_summ']   = ($buy_user_balance - $cost_standard_resume) * -1;
        return $result;
    }

    $request_hash_blank = $job['id'].$job['user_id'].$job['job'].$buy_user_id;
    $details            = ['type' => 'standart_resume_buy', 'step' => 'init', 'cost_standard_resume' => $cost_standard_resume, 'job' => $job, 'buy_user_id' => $buy_user_id, 'buy_user_balance' => $buy_user_balance];
    $details['step']    = 'debit_from_user_for_resume_s';

    // списание с пользователя вакансии
    $summ_resume_minus            = $cost_standard_resume * -1;
    $debit_from_user_for_resume_s = billing_pay_jobs_request($buy_user_id, $summ_resume_minus, $details, md5($request_hash_blank.$details['step']), $job['id'], 'debit_from_user_for_resume_s', 'job');

    if (!$debit_from_user_for_resume_s) {
        $result['status_text'] = 'Биллинг: Ошибка списания с пользователя покупки резюме.';
        return $result;
    }

    $result['details']['jobs_requests']['debit_from_user_for_resume_s'] = $debit_from_user_for_resume_s;

    // оплата пользователю портала
    $details['step']    = 'pay_resume_s_to_project';
    $pay_resume_s_to_project = billing_pay_jobs_request(get_role_id(get_GLOBAL_VARS()['BILLING_BALANCE_ROLE']), $cost_standard_resume, $details, md5($request_hash_blank.$details['step']), $job['id'], 'pay_resume_s_to_project', 'job');

    if (!$pay_resume_s_to_project) {
        $result['status_text'] = 'Биллинг: Ошибка оплаты пользователю портала.';
        return $result;
    }

    $result['status']      = true;
    $result['status_num']  = 0;
    $result['status_text'] = 'Биллинг: Оплата порталу успешно сделана.';
    $result['details']['jobs_requests']['pay_resume_s_to_project'] = $pay_resume_s_to_project;

    add_jobs_history(['Оплата стандартного резюме: ' . $result['status_text']],
                       null, $job['id']);

    add_jobs_history(['Оплата стандартной вакансии: подробно.',
                      'UID резюме: '          . $job['id'],
                      'Баланс пользователя: ' . $buy_user_balance,
                      'Стоимость резюме: '    . $cost_standard_resume,
                      'only_admin'],
                      null, $job['id']);

    return $result;

}

function pay_standart_vacancy(int $job_id) {
    $result                = [];
    $result['status']      = false;
    $result['status_num']  = 1;
    $result['status_text'] = 'Биллинг резерв: Ошибка при инициализации.';

    $job = __DB(['q_my' => "SELECT * FROM `jobs` WHERE `id` = '".$job_id."';",
                 'return_type' => 'r_fetch_assoc'
    ]);

    if (empty($job)) {
        $result['status_text'] = 'Биллинг резерв: Нет вакансии.';
        return $result;
    }

    $job = $job[0];

    if (!in_array($job['status'], [0, 4])) {
        $result['status_text'] = 'Биллинг резерв: не верный статсус вакансии.';
        return $result;
    }

    $cost_standard_vacancy = get_settings()['cost_standard_vacancy'];
    $user_balance          = billing_get_balance($job['user_id']);

    if ($cost_standard_vacancy <= 0) {
        $result['status_text'] = 'Биллинг резерв: сумма меньше или ровна 0.';
        return $result;
    }

    if ($cost_standard_vacancy > $user_balance) {
        $result['status_text'] = 'Биллинг резерв: Недостаточно средств на балансе пользователя.';
        $result['status_num']  = 5;
        $result['deff_summ']   = ($user_balance - $cost_standard_vacancy) * -1;
        return $result;
    }

    $request_hash_blank = $job['id'].$job['user_id'].$job['job'];
    $details            = ['type' => 'standart_vacancy_pay', 'step' => 'init', 'cost_standard_vacancy' => $cost_standard_vacancy, 'job' => $job];
    $details['step']    = 'debit_vacancy_s_user';

    // списание с пользователя вакансии
    $summ_vacancy_minus   = $cost_standard_vacancy * -1;
    $debit_vacancy_s_user = billing_pay_jobs_request($job['user_id'], $summ_vacancy_minus, $details, md5($request_hash_blank.$details['step']), $job['id'], 'debit_vacancy_s_user', 'job');

    if (!$debit_vacancy_s_user) {
        $result['status_text'] = 'Биллинг: Ошибка списания с пользователя вакансии.';
        return $result;
    }

    // оплата пользователю портала
    $details['step']    = 'pay_vacancy_s_to_project';
    $pay_vacancy_s_to_project = billing_pay_jobs_request(get_role_id(get_GLOBAL_VARS()['BILLING_BALANCE_ROLE']), $cost_standard_vacancy, $details, md5($request_hash_blank.$details['step']), $job['id'], 'pay_vacancy_s_to_project', 'job');

    if (!$pay_vacancy_s_to_project) {
        $result['status_text'] = 'Биллинг: Ошибка оплаты пользователю портала.';
        return $result;
    }

    $result['status']      = true;
    $result['status_num']  = 0;
    $result['status_text'] = 'Биллинг: Оплата порталу успешно сделана.';

    add_jobs_history(['Оплата стандартной вакансии: ' . $result['status_text']],
                       null, $job['id']);

    add_jobs_history(['Оплата стандартной вакансии: подробно.',
                      'UID вакансии: '               . $job['id'],
                      'Баланс работодателя: '        . $user_balance,
                      'Стоимость публикации: '       . $cost_standard_vacancy,
                      'only_admin'],
                      null, $job['id']);

    return $result;

}

function pay_cost_pre_vacancy(int $job_id) {
    $result                = [];
    $result['status']      = false;
    $result['status_num']  = 1;
    $result['status_text'] = 'Биллинг резерв: Ошибка при инициализации.';

    $job = __DB(['q_my' => "SELECT * FROM `jobs` WHERE `id` = '".$job_id."';",
        'return_type' => 'r_fetch_assoc'
    ]);

    if (empty($job)) {
        $result['status_text'] = 'Биллинг резерв: Нет вакансии.';
        return $result;
    }

    $job = $job[0];

    if (!in_array($job['status'], [0, 1, 4])) {
        $result['status_text'] = 'Биллинг резерв: не верный статсус вакансии.';
        return $result;
    }

    $cost_online_vacancy = get_settings()['cost_online_vacancy'];
    $user_balance        = billing_get_balance($job['user_id']);

    if ($cost_online_vacancy <= 0) {
        $result['status_text'] = 'Биллинг резерв: сумма меньше или ровна 0.';
        return $result;
    }

    if ($cost_online_vacancy > $user_balance) {
        $result['status_text'] = 'Биллинг резерв: Недостаточно средств на балансе пользователя.';
        $result['status_num']  = 5;
        $result['deff_summ']   = ($user_balance - $cost_online_vacancy) * -1;
        return $result;
    }

    $request_hash_blank = $job['id'].$job['user_id'].$job['job'];
    $details            = ['type' => 'online_vacancy_pay', 'step' => 'init', 'cost_online_vacancy' => $cost_online_vacancy, 'job' => $job];
    $details['step']    = 'debit_vacancy_user_pre_reserv';

    // списание с пользователя вакансии
    $summ_vacancy_minus = $cost_online_vacancy * -1;
    $debit_vacancy_user = billing_pay_jobs_request($job['user_id'], $summ_vacancy_minus, $details, md5($request_hash_blank.$details['step']), $job['id'], 'debit_vacancy_user_pre_reserv', 'job');

    if (!$debit_vacancy_user) {
        $result['status_text'] = 'Биллинг: Ошибка списания с пользователя вакансии.';
        return $result;
    }

    // оплата пользователю портала
    $details['step']    = 'pay_vacancy_to_project';
    $pay_vacancy_to_project = billing_pay_jobs_request(get_role_id(get_GLOBAL_VARS()['BILLING_BALANCE_ROLE']), $cost_online_vacancy, $details, md5($request_hash_blank.$details['step']), $job['id'], 'pay_vacancy_to_project', 'job');

    if (!$pay_vacancy_to_project) {
        $result['status_text'] = 'Биллинг: Ошибка оплаты пользователю портала.';
        return $result;
    }

    $result['status']      = true;
    $result['status_num']  = 0;
    $result['status_text'] = 'Биллинг: Оплата порталу успешно сделана.';

    add_jobs_history(['Оплата онлайн предоплаты вакансии: ' . $result['status_text']],
        null, $job['id']);

    add_jobs_history(['Оплата онлайн предоплаты вакансии: подробно.',
        'UID вакансии: '         . $job['id'],
        'Баланс работодателя: '  . $user_balance,
        'Стоимость публикации: ' . $cost_online_vacancy,
        'only_admin'],
        null, $job['id']);

    return $result;

}

function reserv_job_pre_balance(int $job_id) {
    $result                = [];
    $result['status']      = false;
    $result['status_num']  = 1;
    $result['status_text'] = 'Биллинг резерв: Ошибка при инициализации.';

    $job = __DB(['q_my' => "SELECT * FROM `jobs` WHERE `id` = '".$job_id."';",
                 'return_type' => 'r_fetch_assoc'
    ]);

    if (empty($job)) {
        $result['status_text'] = 'Биллинг резерв: Нет вакансии.';
        return $result;
    }

    $job = $job[0];

    if (!in_array($job['status'], [0, 1, 4])) {
        $result['status_text'] = 'Биллинг резерв: не верный статсус вакансии.';
        return $result;
    }

    $job_summ         = job_get_summ($job['id']);
    $calculations     = get_calculations($job_summ);
    $user_balance     = billing_get_balance($job['user_id']);

    $calculations['summ_vacancy_all'] = $job_summ;

    if ($calculations['summ_vacancy_all'] <= 0) {
        $result['status_text'] = 'Биллинг резерв: сумма меньше или ровна 0.';
        return $result;
    }

    if ($calculations['summ_vacancy_all'] > $user_balance) {
        $result['status_text'] = 'Биллинг резерв: Недостаточно средств на балансе пользователя.';
        $result['status_num']  = 5;
        $result['deff_summ']   = ($user_balance - $calculations['summ_vacancy_all']) * -1;
        return $result;
    }

    $request_hash_blank = $job['id'].$job['user_id'].$job['job'];
    $details            = ['type' => 'reserv_vacancy_pre_pay', 'step' => 'init', 'calculations' => $calculations, 'job' => $job];
    $details['step']    = 'debit_vacancy_user_pre';

    // списание с пользователя вакансии
    $summ_vacancy_all_minus  = $calculations['summ_vacancy_all'] * -1;
    $debit_vacancy_user      = billing_pay_jobs_request($job['user_id'], $summ_vacancy_all_minus, $details, md5($request_hash_blank.$details['step']), $job['id'], 'debit_vacancy_user_pre', 'job');

    if (!$debit_vacancy_user) {
        $result['status_text'] = 'Биллинг резерв: Ошибка списания с пользователя вакансии.';
        return $result;
    }

    // оплата пользователю резерва портала
    $details['step']    = 'pay_reserv_project_pre';
    $pay_reserv_project = billing_pay_jobs_request(get_role_id(get_GLOBAL_VARS()['BILLING_RESERV_ROLE']), $calculations['summ_vacancy_all'], $details, md5($request_hash_blank.$details['step']), $job['id'], 'pay_reserv_project_pre', 'job');

    if (!$pay_reserv_project) {
        $result['status_text'] = 'Биллинг: Ошибка оплаты резерва порталу.';
        return $result;
    }

    $result['status']      = true;
    $result['status_num']  = 0;
    $result['status_text'] = 'Биллинг: Резерв успешно сделан.';

    add_jobs_history(['Резерв: ' . $result['status_text']], null, $job['id']);

    add_jobs_history(['Резерв предоплаты онлайн-вакансии: подробно.',
                      'UID вакансии: '               . $job['id'],
                      'Баланс работодателя: '        . $user_balance,
                      'Сумма вакансии: '             . $job_summ,
                      'Сумма вакансии с комиссеей: ' . $calculations['summ_vacancy_all'],
                      'only_admin'],
                      null, $job['id']);
    return $result;
}

function reserv_job_balance(int $job_id) {
    $result                = [];
    $result['status']      = false;
    $result['status_num']  = 1;
    $result['status_text'] = 'Биллинг резерв: Ошибка при инициализации.';

    $job = __DB(['q_my' => "SELECT * FROM `jobs` WHERE `id` = '".$job_id."';",
                 'return_type' => 'r_fetch_assoc'
    ]);

    if (empty($job)) {
        $result['status_text'] = 'Биллинг резерв: Нет вакансии.';
        return $result;
    }

    $job = $job[0];

    if (!in_array($job['status'], [0, 1, 4])) {
        $result['status_text'] = 'Биллинг резерв: не верный статсус вакансии.';
        return $result;
    }

    $job_summ         = job_get_summ($job['id']);
    $calculations     = get_calculations($job_summ);
    $user_balance     = billing_get_balance($job['user_id']);

    if ($calculations['summ_vacancy_all'] <= 0) {
        $result['status_text'] = 'Биллинг резерв: сумма меньше или ровна 0.';
        return $result;
    }

    if ($calculations['summ_vacancy_all'] > $user_balance) {
        $result['status_text'] = 'Биллинг резерв: Недостаточно средств на балансе пользователя.';
        $result['status_num']  = 5;
        $result['deff_summ']   = ($user_balance - $calculations['summ_vacancy_all']) * -1;
        return $result;
    }

    $request_hash_blank = $job['id'].$job['user_id'].$job['job'];
    $details            = ['type' => 'reserv_vacancy_pay', 'step' => 'init', 'calculations' => $calculations, 'job' => $job];
    $details['step']    = 'debit_vacancy_user';

    // списание с пользователя вакансии
    $summ_vacancy_all_minus  = $calculations['summ_vacancy_all'] * -1;
    $debit_vacancy_user      = billing_pay_jobs_request($job['user_id'], $summ_vacancy_all_minus, $details, md5($request_hash_blank.$details['step']), $job['id'], 'debit_vacancy_user', 'job');

    if (!$debit_vacancy_user) {
        $result['status_text'] = 'Биллинг резерв: Ошибка списания с пользователя вакансии.';
        return $result;
    }

    // оплата пользователю резерва портала
    $details['step']    = 'pay_reserv_project';
    $pay_reserv_project = billing_pay_jobs_request(get_role_id(get_GLOBAL_VARS()['BILLING_RESERV_ROLE']), $calculations['summ_vacancy_all'], $details, md5($request_hash_blank.$details['step']), $job['id'], 'pay_reserv_project', 'job');

    if (!$pay_reserv_project) {
        $result['status_text'] = 'Биллинг: Ошибка оплаты резерва порталу.';
        return $result;
    }

    $result['status']      = true;
    $result['status_num']  = 0;
    $result['status_text'] = 'Биллинг: Резерв успешно сделан.';

    add_jobs_history(['Резерв: ' . $result['status_text']],
                       null, $job['id']);

    add_jobs_history(['Резерв: подробно.',
                      'UID вакансии: '               . $job['id'],
                      'Баланс работодателя: '        . $user_balance,
                      'Сумма вакансии: '             . $job_summ,
                      'Комиссия соискателю %: '      . $calculations['percent_commision_resume'],
                      'Комиссия порталу %: '         . $calculations['percent_commision_project'],
                      'Комиссия соискателю: '        . $calculations['commision_resume'],
                      'Комиссия порталу: '           . $calculations['commision_project'],
                      'Сумма вакансии с комиссеей: ' . $calculations['summ_vacancy_all'],
                      'only_admin'],
                      null, $job['id']);

    return $result;
}

function pay_jobs_request_pre(int $job_request_id) {
    $result                = [];
    $result['status']      = false;
    $result['status_num']  = 1;
    $result['status_text'] = 'Биллинг: Ошибка при инициализации.';

    $job_request = __DB(['q_my' => "SELECT * FROM `jobs_request` WHERE `id` = '".$job_request_id."';",
                         'return_type' => 'r_fetch_assoc'
    ]);

    if (!empty($job_request)) {
        $job_request = $job_request[0];
    } else {
        return $result;
    }

    $request_hash_blank = $job_request['id'].$job_request['vacancy_id'].$job_request['resume_id'].$job_request['vacancy_user_id'].$job_request['resume_user_id'];

    $job_request_pay_reserv_project = __DB(['q_my' => "SELECT * FROM `billing_request` WHERE `object_id` = '".$job_request['vacancy_id']."' AND `object_type` = 'pay_reserv_project_pre';",
        'return_type' => 'r_fetch_assoc'
    ]);

    if (!empty($job_request_pay_reserv_project)) {
        $job_request_pay_reserv_project = unserialize($job_request_pay_reserv_project[0]['request_details']);
    } else {
        $result['status_text'] = 'Биллинг: Ошибка, не найдена запись резерва для расчетов.';
        return $result;
    }

    if (!isset($job_request_pay_reserv_project['calculations'])) {
        $result['status_text'] = 'Биллинг: Ошибка, нет расчетов из резерва.';
        return $result;
    }

    $calculations         = $job_request_pay_reserv_project['calculations'];
    $reserv_user_balance  = billing_get_balance(get_role_id(get_GLOBAL_VARS()['BILLING_RESERV_ROLE']));

    if ($calculations['summ_vacancy_all'] <= 0) {
        $result['status_text'] = 'Биллинг: Ошибка стоимости вакансии.';
        return $result;
    }

    if ($reserv_user_balance < $calculations['summ_vacancy_all']) {
        $result['status_text'] = 'Биллинг: Недостаточно средств на резерве для оплаты.';
        $result['status_num']  = 5;
        $result['deff_summ']   = ($reserv_user_balance - $calculations['summ_vacancy_all']) * -1;
        return $result;
    }
    // списание с резерва
    $details                          = ['type' => 'vacancy_pay', 'step' => 'init', 'calculations' => $calculations, 'job_request' => $job_request];
    $details['step']                  = 'debit_reserv_user';
    $summ_vacancy_all_log             = $calculations['summ_vacancy_all'];
    $calculations['summ_vacancy_all'] = $calculations['summ_vacancy_all'] * -1; // списание с резерва вакансии.
    $debit_reserv_user                = billing_pay_jobs_request(get_role_id(get_GLOBAL_VARS()['BILLING_RESERV_ROLE']), $calculations['summ_vacancy_all'], $details, md5($request_hash_blank.$details['step']), $job_request_id, 'debit_reserv_user', 'job_request');

    if (!$debit_reserv_user) {
        $result['status_text'] = 'Биллинг: Ошибка списания с резерва.';
        return $result;
    }

    // оплата соискателю
    $details['step'] = 'pay_resume_user';
    $pay_resume_user = billing_pay_jobs_request($job_request['resume_user_id'], $calculations['summ_vacancy'], $details, md5($request_hash_blank.$details['step']), $job_request_id, 'pay_resume_user', 'job_request');

    if (!$pay_resume_user) {
        $result['status_text'] = 'Биллинг: Ошибка оплаты вакансии соискателю.';
        return $result;
    }

    $result['status']      = true;
    $result['status_num']  = 0;
    $result['status_text'] = 'Биллинг: Оплата успешно прошла.';

    add_jobs_history(['Оплата: ' . $result['status_text']],
        $job_request_id);

    add_jobs_history(['Оплата: подробно.',
        'UID отклика: '                . $job_request_id,
        'Комиссия соискателю %: '      . $calculations['percent_commision_resume'],
        'Комиссия порталу %: '         . $calculations['percent_commision_project'],
        'Комиссия соискателю: '        . $calculations['commision_resume'],
        'Комиссия порталу: '           . $calculations['commision_project'],
        'Сумма вакансии с комиссеей: ' . $summ_vacancy_all_log,
        'only_admin'],
        $job_request_id);

    return $result;
}

function pay_jobs_request(int $job_request_id) {
    $result                = [];
    $result['status']      = false;
    $result['status_num']  = 1;
    $result['status_text'] = 'Биллинг: Ошибка при инициализации.';

    $job_request = __DB(['q_my' => "SELECT * FROM `jobs_request` WHERE `id` = '".$job_request_id."';",
                         'return_type' => 'r_fetch_assoc'
    ]);

    if (!empty($job_request)) {
        $job_request = $job_request[0];
    } else {
        return $result;
    }

    $request_hash_blank = $job_request['id'].$job_request['vacancy_id'].$job_request['resume_id'].$job_request['vacancy_user_id'].$job_request['resume_user_id'];

    $job_request_pay_reserv_project = __DB(['q_my' => "SELECT * FROM `billing_request` WHERE `object_id` = '".$job_request['vacancy_id']."' AND `object_type` = 'pay_reserv_project';",
                                            'return_type' => 'r_fetch_assoc'
    ]);

    if (!empty($job_request_pay_reserv_project)) {
        $job_request_pay_reserv_project = unserialize($job_request_pay_reserv_project[0]['request_details']);
    } else {
        $result['status_text'] = 'Биллинг: Ошибка, не найдена запись резерва для расчетов.';
        return $result;
    }

    if (!isset($job_request_pay_reserv_project['calculations'])) {
        $result['status_text'] = 'Биллинг: Ошибка, нет расчетов из резерва.';
        return $result;
    }

    $calculations         = $job_request_pay_reserv_project['calculations'];
    $reserv_user_balance  = billing_get_balance(get_role_id(get_GLOBAL_VARS()['BILLING_RESERV_ROLE']));

    if ($calculations['summ_vacancy_all'] <= 0) {
        $result['status_text'] = 'Биллинг: Ошибка стоимости вакансии.';
        return $result;
    }

    if ($reserv_user_balance < $calculations['summ_vacancy_all']) {
        $result['status_text'] = 'Биллинг: Недостаточно средств на резерве для оплаты.';
        $result['status_num']  = 5;
        $result['deff_summ']   = ($reserv_user_balance - $calculations['summ_vacancy_all']) * -1;
        return $result;
    }
    // списание с резерва
    $details                          = ['type' => 'vacancy_pay', 'step' => 'init', 'calculations' => $calculations, 'job_request' => $job_request];
    $details['step']                  = 'debit_reserv_user';
    $summ_vacancy_all_log             = $calculations['summ_vacancy_all'];
    $calculations['summ_vacancy_all'] = $calculations['summ_vacancy_all'] * -1; // списание с резерва вакансии.
    $debit_reserv_user                = billing_pay_jobs_request(get_role_id(get_GLOBAL_VARS()['BILLING_RESERV_ROLE']), $calculations['summ_vacancy_all'], $details, md5($request_hash_blank.$details['step']), $job_request_id, 'debit_reserv_user', 'job_request');

    if (!$debit_reserv_user) {
        $result['status_text'] = 'Биллинг: Ошибка списания с резерва.';
        return $result;
    }

    // оплата соискателю
    $details['step'] = 'pay_resume_user';
    $pay_resume_user = billing_pay_jobs_request($job_request['resume_user_id'], $calculations['summ_vacancy'], $details, md5($request_hash_blank.$details['step']), $job_request_id, 'pay_resume_user', 'job_request');

    if (!$pay_resume_user) {
        $result['status_text'] = 'Биллинг: Ошибка оплаты вакансии соискателю.';
        return $result;
    }

    // оплата комиссии соискателю - 4%
    $details['step'] = 'pay_resume_user_commision';
    $pay_resume_user_commision = billing_pay_jobs_request($job_request['resume_user_id'], $calculations['commision_resume'], $details, md5($request_hash_blank.$details['step']), $job_request_id, 'pay_resume_user_commision', 'job_request');

    if (!$pay_resume_user_commision) {
        $result['status_text'] = 'Биллинг: Ошибка оплаты комиссии соискателю.';
        return $result;
    }

    // Реферальная программа начало
    $resume_user = __DB(['q_my' => "SELECT * FROM `users` WHERE `id` = '".$job_request['resume_user_id']."';",
                         'return_type' => 'r_fetch_assoc'
    ]);

    if (empty($resume_user)) {
        $result['status_text'] = 'Биллинг: Ошибка реферальной программы, не найдена запись соискателя.';
        return $result;
    } else {
        $resume_user = $resume_user[0];
    }

    $ref_user = __DB(['q_my' => "SELECT * FROM `users` WHERE `id` = '".$resume_user['ref_uid']."';",
                      'return_type' => 'r_fetch_assoc'
    ]);

    if (!empty($ref_user) && isset($calculations['commision_project_proto'])) { // оплатить рефералу можно только с ново созданных вакансий
        $ref_user = $ref_user[0];
        if ($ref_user['ref_status'] == 0) { // обычный реферал статус
            $calculations['commision_project'] = $calculations['commision_project_with_ref_standart'];
            $details['ref_step'] = 'commision_project=commision_project_with_ref_standart';
            // оплата комиссии рефералу по обычному статусту
            $details['step'] = 'pay_commision_ref_standart';
            $pay_commision_ref_standart = billing_pay_jobs_request($ref_user['id'], $calculations['commision_ref_standart'], $details, md5($request_hash_blank.$details['step']), $job_request_id, 'pay_commision_ref_standart', 'job_request');
            if (!$pay_commision_ref_standart) {
                $result['status_text'] = 'Биллинг: Ошибка реферальной программы оплаты комиссии реферату по обычному статусу.';
                return $result;
            }
            add_jobs_history(['Комиссия реферальной программы (% от комиссии сайта): ' . $calculations['percent_ref_standart'],
                              'Сумма выплаты реферальной программы: '                  . $calculations['commision_ref_standart'],
                              'Комиссия порталу без комиссии реферальной программы: '  . $calculations['commision_project_proto'],
                              'Комиссия порталу с комиссией реферальной программы: '   . $calculations['commision_project_with_ref_standart']],
            $job_request_id);
        }
        if ($ref_user['ref_status'] == 1) { // золотой реферал статус
            $calculations['commision_project'] = $calculations['commision_project_with_ref_gold'];
            $details['ref_step'] = 'commision_project=commision_project_with_ref_gold';
            // оплата комиссии рефералу по золотому статусту
            $details['step'] = 'pay_commision_ref_gold';
            $pay_commision_ref_gold = billing_pay_jobs_request($ref_user['id'], $calculations['commision_ref_gold'], $details, md5($request_hash_blank.$details['step']), $job_request_id, 'pay_commision_ref_gold', 'job_request');
            if (!$pay_commision_ref_gold) {
                $result['status_text'] = 'Биллинг: Ошибка реферальной программы оплаты комиссии реферату по золотому статусу.';
                return $result;
            }
            add_jobs_history(['Комиссия золотого аккаунта (% от комиссии сайта): '    . $calculations['percent_ref_gold'],
                              'Сумма выплаты реферальной программы: '                 . $calculations['commision_ref_gold'],
                              'Комиссия порталу без комиссии реферальной программы: ' . $calculations['commision_project_proto'],
                              'Комиссия порталу с комиссией реферальной программы: '  . $calculations['commision_project_with_ref_gold']],
            $job_request_id);
        }
    }

    $plat_users = __DB(['q_my' => "SELECT * FROM `users` WHERE `ref_status` = '2';", // платиновый реферал статус
                        'return_type' => 'r_fetch_assoc'
    ]);

    if (!empty($plat_users)) {
      foreach ($plat_users as $plat_user) {
          $calculations['commision_project'] = $calculations['commision_project_with_ref_plat'];
          $details['ref_step'] = 'commision_project=commision_project_with_ref_plat';
          // оплата комиссии рефералу по золотому статусту
          $details['step'] = 'pay_commision_ref_plat';
          $pay_commision_ref_plat = billing_pay_jobs_request($plat_user['id'], $calculations['commision_ref_plat'], $details, md5($request_hash_blank.$plat_user['id'].$details['step']), $job_request_id, 'pay_commision_ref_plat', 'job_request');
          if (!$pay_commision_ref_plat) {
              $result['status_text'] = 'Биллинг: Ошибка реферальной программы оплаты комиссии реферату по платиновому статусу.';
              return $result;
          }
          add_jobs_history(['Комиссия платинового аккаунта (% от комиссии сайта): ' . $calculations['percent_ref_plat'],
                            'Сумма платиновой выплаты реферальной программы: '      . $calculations['commision_ref_plat'],
                            'Комиссия порталу без комиссии реферальной программы: ' . $calculations['commision_project_proto'],
                            'Комиссия порталу с комиссией реферальной программы: '  . $calculations['commision_project_with_ref_plat']],
                            $job_request_id);
      }
    }
    // Реферальная программа конец

    // оплата комиссии порталу
    $details['step'] = 'pay_commision_project';
    $pay_commision_project = billing_pay_jobs_request(get_role_id(get_GLOBAL_VARS()['BILLING_BALANCE_ROLE']), $calculations['commision_project'], $details, md5($request_hash_blank.$details['step']), $job_request_id, 'pay_commision_project', 'job_request');

    if (!$pay_commision_project) {
        $result['status_text'] = 'Биллинг: Ошибка оплаты комиссии порталу.';
        return $result;
    }

    $result['status']      = true;
    $result['status_num']  = 0;
    $result['status_text'] = 'Биллинг: Оплата успешно прошла.';

    add_jobs_history(['Оплата: ' . $result['status_text']],
                      $job_request_id);

    add_jobs_history(['Оплата: подробно.',
                      'UID отклика: '                . $job_request_id,
                      'Комиссия соискателю %: '      . $calculations['percent_commision_resume'],
                      'Комиссия порталу %: '         . $calculations['percent_commision_project'],
                      'Комиссия соискателю: '        . $calculations['commision_resume'],
                      'Комиссия порталу: '           . $calculations['commision_project'],
                      'Сумма вакансии с комиссеей: ' . $summ_vacancy_all_log,
                      'only_admin'],
                      $job_request_id);

    return $result;
}

function job_get_summ(int $job_id) {
    $job = __DB(['q_my' => "SELECT * FROM `jobs` WHERE `id` = '".$job_id."';",
                 'return_type' => 'r_fetch_assoc'
    ]);
    if (!empty($job)) {
        $job      = $job[0];
        $job_data = @unserialize($job['job_data']);
        if ($job_data === false) {
            return 0;
        }
        if (isset($job_data['vacancy_pay'])) {
            return (int)$job_data['vacancy_pay'];
        }
    }
    return 0;
}

function get_calculations(int $summ_vacancy) {
    $settings                                            = get_settings();
    $calculations                                        = [];
    $calculations['percent_commision_resume']            = $settings['percent_commision_resume'];
    $calculations['percent_commision_project']           = $settings['percent_commision_project'];
    $calculations['percent_ref_standart']                = $settings['percent_ref_standart'];
    $calculations['percent_ref_gold']                    = $settings['percent_ref_gold'];
    $calculations['percent_ref_plat']                    = $settings['percent_ref_plat'];
    $calculations['summ_vacancy']                        = $summ_vacancy;
    $calculations['commision_resume']                    = $summ_vacancy * ($calculations['percent_commision_resume'] / 100);
    $calculations['commision_project']                   = $summ_vacancy * ($calculations['percent_commision_project'] / 100);
    $calculations['commision_project_proto']             = $calculations['commision_project'];
    $calculations['commision_ref_standart']              = $calculations['commision_project'] * ($calculations['percent_ref_standart'] / 100);
    $calculations['commision_ref_gold']                  = $calculations['commision_project'] * ($calculations['percent_ref_gold'] / 100);
    $calculations['commision_ref_plat']                  = $calculations['commision_project'] * ($calculations['percent_ref_plat'] / 100);
    $calculations['commision_project_with_ref_standart'] = $calculations['commision_project'] - $calculations['commision_ref_standart'];
    $calculations['commision_project_with_ref_gold']     = $calculations['commision_project'] - $calculations['commision_ref_gold'];
    $calculations['commision_project_with_ref_plat']     = $calculations['commision_project'] - $calculations['commision_ref_plat'];
    $calculations['commision_all']                       = $calculations['commision_resume'] + $calculations['commision_project'];
    $calculations['summ_resume_all']                     = $calculations['summ_vacancy'] + $calculations['commision_resume'];
    $calculations['summ_vacancy_all']                    = $summ_vacancy + $calculations['commision_all'];
    return $calculations;
}
