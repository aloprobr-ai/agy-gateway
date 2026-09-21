<?php
/**
 * Настройки шлюза.
 *
 * Скопируйте этот файл в config.php и правьте его:
 *     cp config.example.php config.php            (Linux, macOS)
 *     copy config.example.php config.php          (Windows)
 *
 * Почти всё здесь уже заполнено так, чтобы шлюз завёлся без правок.
 * Пустая строка в путях означает «определи сам»: шлюз найдёт agy, домашний
 * каталог и место для файлов по правилам своей системы. Вписывать пути
 * вручную нужно только тогда, когда угадано не то.
 */

return [
    // Чем отвечать на /v1/chat/completions:
    //   'cli' — запускать Antigravity CLI (agy) на этой же машине. Ключи Google
    //           не нужны: CLI работает под вашей учётной записью Antigravity.
    //   'api' — ходить в Gemini по HTTP с ключами из gemini_keys.
    // Клиент может переключить бэкенд на один запрос префиксом модели:
    // "cli/gemini-3-pro" или "api/gemini-2.5-flash".
    'backend' => 'cli',

    // ------------------------------------------------------------ бэкенд CLI
    'cli' => [
        // Путь к agy. Пусто — ищем сами: сначала в PATH, потом в привычных
        // местах (%LOCALAPPDATA%\agy\bin и %APPDATA%\npm в Windows,
        // /usr/local/bin и ~/.local/bin в Linux).
        //
        // Вписать полный путь стоит, если шлюз работает под веб-сервером:
        // у PHP-FPM свой PATH, и agy из вашей консоли там может не найтись.
        //   Windows:  'C:\Users\Вы\AppData\Local\agy\bin\agy.exe'
        //   Linux:    '/usr/local/bin/agy'
        // Можно задать и массив — команду с префиксом:
        //   ['sudo', '-u', 'agent', '/usr/local/bin/agy']
        'command' => '',

        // Песочница для CLI (только Linux, нужен unshare из util-linux).
        // Прячет системные каталоги от запущенного CLI — см. bin/agy-jail.
        //   'auto'  — включить, если получится (по умолчанию);
        //   'on'    — требовать: нет песочницы — нет ответа;
        //   'off'   — не запирать;
        //   путь    — свой скрипт-обёртка вместо встроенного.
        // В Windows такого средства нет, и при 'auto' шлюз работает без него;
        // что защиты нет, видно в /health.
        'jail' => 'auto',

        // Домашний каталог того, от чьего имени работает PHP: там agy держит
        // авторизацию и беседы. Пусто — берётся из HOME или USERPROFILE.
        // Под веб-сервером переменная часто пуста — тогда впишите путь:
        //   aaPanel/nginx:  '/home/www'
        'home' => '',

        // Каталог бесед CLI. Пусто -> <home>/.gemini/antigravity-cli/brain
        'brain_dir' => '',

        // PATH для дочернего процесса. Пусто — тот же, что у самого PHP.
        // Заполняйте, если agy не находит node:
        //   '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'
        'path' => '',

        // Дополнительные переменные окружения процесса (например, прокси).
        'env' => [
            // 'HTTPS_PROXY' => 'http://user:pass@host:port',
        ],

        // Рабочий каталог беседы: там модель создаёт файлы.
        // Пусто -> storage/work внутри проекта, по подкаталогу на беседу.
        'workdir' => '',

        // Куда класть присланные картинки, чтобы CLI их прочитал.
        // Пусто -> storage/images, а под песочницей -> <home>/agy-share/images
        // (каталог проекта внутри песочницы не виден).
        'image_dir' => '',
        'keep_images' => false,   // true — не удалять файлы после ответа

        // Флаги, добавляемые к каждому запуску.
        // --dangerously-skip-permissions обязателен: иначе CLI ждёт, что
        // человек подтвердит каждое действие, а у HTTP-запроса человека нет.
        // Именно поэтому рядом стоит песочница, см. 'jail'.
        'extra_args' => ['--dangerously-skip-permissions'],

        // Как передать модель: agy --model "<имя>".
        'model_flag' => '--model',
        // Имена моделей — как их печатает `agy models` (первый столбец).
        // Актуальный список обновляет bin/update_models.php.
        'default_model' => 'gemini-3.7-flash-medium',
        'model_map' => [
            'agy'          => 'gemini-3.7-flash-medium',
            'agy-fast'     => 'gemini-3.7-flash-low',
            'agy-pro'      => 'gemini-3.1-pro-high',
            'gpt-4o'       => 'gemini-3.7-flash-medium',
            'gpt-4o-mini'  => 'gemini-3.7-flash-low',
            'gpt-4.1'      => 'gemini-3.1-pro-high',
            'default'      => '',
        ],
        // Передавать в --model незнакомые имена как есть (обычно не нужно).
        'pass_unknown_model' => false,

        // Как bin/update_models.php узнаёт список моделей у CLI.
        // Команды пробуются по очереди, пока какая-то не выдаст список.
        'models_command' => [
            ['models'],
            ['--list-models'],
            ['models', 'list'],
            ['--help'],
        ],
        // Свой шаблон разбора вывода (PCRE, имя модели — в первой группе).
        'models_pattern' => '',

        // Таймауты, сек.
        'timeout' => 180,             // сколько ждём ответа
        'new_session_timeout' => 30,  // сколько ждём появления новой беседы

        // Сколько хранить привязку «сессия -> беседа CLI», сек.
        'session_ttl' => 604800,
    ],

    // ------------------------------------------------------------ бэкенд API
    // Ключи Gemini (Google AI Studio). Можно указать несколько — они
    // перебираются по кругу, при 429/5xx запрос повторяется со следующим.
    // В режиме 'cli' не нужны, но /v1/embeddings и /v1/images/generations
    // работают только по ним.
    'gemini_keys' => [
        // 'AIzaSy...ВАШ_КЛЮЧ',
    ],

    'gemini_base' => 'https://generativelanguage.googleapis.com',
    'gemini_api_version' => 'v1beta',
    'default_model' => 'gemini-2.5-flash',

    // Алиасы: имя модели OpenAI -> реальная модель Gemini. Нужны, чтобы софт,
    // жёстко зашитый на gpt-4o, работал без правок.
    'model_map' => [
        'gpt-4o'              => 'gemini-2.5-flash',
        'gpt-4o-mini'         => 'gemini-2.5-flash-lite',
        'gpt-4.1'             => 'gemini-2.5-pro',
        'gpt-4.1-mini'        => 'gemini-2.5-flash',
        'gpt-4-turbo'         => 'gemini-2.5-pro',
        'gpt-3.5-turbo'       => 'gemini-2.5-flash-lite',
        'text-embedding-3-small' => 'text-embedding-004',
        'text-embedding-3-large' => 'text-embedding-004',
        'text-embedding-ada-002' => 'text-embedding-004',
        'dall-e-3'            => 'gemini-2.5-flash-image',
        'gpt-image-1'         => 'gemini-2.5-flash-image',
    ],

    'image_model' => 'gemini-2.5-flash-image',
    'embedding_model' => 'text-embedding-004',

    // ------------------------------------------------------------------ доступ
    // Ключи ваших клиентов — то, что уходит в заголовок Authorization: Bearer.
    // Пустой массив = вход без ключа. На своей машине это удобно, но шлюз
    // тогда нельзя открывать наружу: любой, кто до него достучится, получит
    // и вашу учётную запись Antigravity, и право запускать CLI.
    'api_keys' => [
        // 'sk-...',   — или выдайте ключ на странице /keys
    ],

    // Страница /keys: кто может её открыть и чем подтверждаются действия.
    'admin' => [
        // С каких адресов видна страница. Пусто — только с этой же машины.
        'ips' => ['127.0.0.1', '::1'],
        // Пароль на выдачу и отзыв ключей. Пусто — выдача запрещена.
        // Это НЕ ключ доступа к API, а отдельная строка, придумайте любую.
        'token' => '',
    ],

    // Свои обновления настольного клиента (/up и /archive). Нужно, только если
    // вы раздаёте сборки сами, а не через GitHub Releases.
    'releases' => [
        'publisher_ips' => ['127.0.0.1'],
        'publish_token' => '',
    ],

    // ---------------------------------------------------------------- прочее
    // Разрешить загрузку картинок по внешним http(s) ссылкам в image_url.
    // false — принимаются только data:image/...;base64,... (безопаснее: шлюз
    // не станет ходить по ссылкам, которые ему присылают).
    'allow_remote_images' => true,
    'max_image_bytes' => 12 * 1024 * 1024,

    // Адрес, по которому шлюз доступен клиентам. Пусто — берётся из запроса.
    // Заполняйте, если шлюз стоит за прокси, который не шлёт X-Forwarded-*.
    'base_url' => '',

    'timeout' => 180,
    'connect_timeout' => 15,
    'max_retries' => 2,

    // Порог безопасности Gemini: BLOCK_NONE | BLOCK_ONLY_HIGH |
    // BLOCK_MEDIUM_AND_ABOVE | BLOCK_LOW_AND_ABOVE
    'safety_threshold' => 'BLOCK_NONE',

    // Логи — в logs/ рядом с проектом.
    'log_enabled' => true,
    'log_requests' => true,   // строка на каждый запрос
    'log_bodies' => false,    // тела запросов и ответов: много места и личные данные

    // Сколько запросов в минуту с одного клиентского ключа. 0 — без ограничения.
    'rate_limit_per_min' => 0,

    // CORS: '*' или список доменов ['https://example.com'].
    'cors_origins' => '*',
];
