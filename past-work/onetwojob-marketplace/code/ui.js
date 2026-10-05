$(document).ready(function () {
    $('#auth_form').on('submit', function (e) {
        e.preventDefault(); // Отменяем стандартную отправку формы

        const form = this; // Получаем саму форму
        const formData = new FormData(form); // Создаём FormData с использованием DOM-элемента формы

        $.ajax({
            url: '/auth', // Адрес для отправки данных
            method: 'POST', // Метод отправки
            data: formData, // Данные формы
            processData: false, // Не обрабатываем данные как обычные строки
            contentType: false, // Не устанавливаем заголовок content-type
            success: function (data) {
                if (data === 'ok') {
                    // Если статус "ok", перезагружаем страницу
                    location.reload();
                } else {
                    // Если ошибка, показываем сообщение
                    $('#auth_form_msg').text('Неверный логин или пароль').addClass('text-danger');
                }
            },
            error: function (xhr, status, error) {
                console.error('Ошибка:', error);
                // В случае ошибки выводим сообщение
                $('#auth_form_msg').text('Произошла ошибка при отправке запроса').addClass('text-danger');
            }
        });
    });

    $('.history_back').click(function () {
        history.back();
    });

    function getCookie(name) {
        const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? match[2] : null;
    }

    if (!getCookie('cookieConsent')) {
        $('#cookie-banner').fadeIn();
    }

    $('#accept-cookie').on('click', function () {
        document.cookie = "cookieConsent=true; path=/; max-age=" + (60 * 60 * 24 * 365);
        $('#cookie-banner').fadeOut();
    });

    $('.mask-phone').mask("99999999999", {placeholder: ""});
    $('.ex-register-ur').hide();
    $('#organisation').change(function () {
        if ($(this).val() === 'ur') {
            $('.ex-register-ur').show();
        } else {
            $('.ex-register-ur').hide();
        }
    });

    $('#reg_form_submit').on('click', function () {
        HTMLFormElement.prototype.submit.call(document.getElementById('reg_form'));
    });

    $('#search_form_submit').on('click', function () {
        HTMLFormElement.prototype.submit.call(document.getElementById('search_form'));
    });

    $('#submit_btn').on('click', function () {
        HTMLFormElement.prototype.submit.call(document.getElementById('submit_form'));
    });

    $('#submit_href_company_q').on('click', function (e) {
        e.preventDefault(); // отменяем стандартное поведение ссылки
        let query = $('#search_company_q').val().trim(); // получаем значение поля
        window.location.href = '/company?q=' + encodeURIComponent(query);
    });

    $('#submit_href_vacancy_q').on('click', function (e) {
        e.preventDefault(); // отменяем стандартное поведение ссылки
        let query = $('#search_vacancy_q').val().trim(); // получаем значение поля
        window.location.href = '/vacancy?q=' + encodeURIComponent(query);
    });

    $('#submit_href_resume_q').on('click', function (e) {
        e.preventDefault(); // отменяем стандартное поведение ссылки
        let query = $('#search_resume_q').val().trim(); // получаем значение поля
        window.location.href = '/resume?q=' + encodeURIComponent(query);
    });

});
