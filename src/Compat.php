<?php
declare(strict_types=1);

/**
 * Подпорки под то, чего может не оказаться в PHP.
 *
 * PHP для Windows приезжает без php.ini, и расширения в нём выключены все
 * разом — включая mbstring. Требовать его ради трёх функций, которыми мы
 * считаем длину строки и режем текст для логов, было бы обидно: человек
 * скачал шлюз, а вместо ответа получил «включите расширение».
 *
 * Поэтому здесь ровно те функции, которые нужны шлюзу, и ровно для UTF-8.
 * Если mbstring всё-таки стоит, ничего не объявляется и работает настоящий.
 *
 * Это не замена расширения: curl и openssl подпоркой не заменишь, они нужны
 * бэкенду 'api', переводчику и обновлению списка моделей. Про них честно
 * скажет bin/doctor.php.
 */

if (!function_exists('mb_strlen')) {
    /**
     * Длина строки в символах, а не в байтах.
     *
     * Для не-UTF-8 строки preg_match_all вернёт false — тогда отвечаем длиной
     * в байтах. Это заведомо больше настоящего числа символов, но здесь длина
     * нужна лишь для оценки числа токенов, и завысить безопаснее, чем занизить.
     */
    function mb_strlen(string $string, ?string $encoding = null): int
    {
        $count = @preg_match_all('/./us', $string);
        return $count === false ? strlen($string) : $count;
    }
}

if (!function_exists('mb_substr')) {
    /** Кусок строки по символам. Отрицательные начало и длина — как у substr(). */
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string
    {
        $chars = @preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return $length === null ? substr($string, $start) : substr($string, $start, $length);
        }
        $slice = $length === null ? array_slice($chars, $start) : array_slice($chars, $start, $length);
        return implode('', $slice);
    }
}

if (!function_exists('mb_stripos')) {
    /**
     * Поиск без учёта регистра, ответ — в символах.
     *
     * Регистр сворачивает сам PCRE с флагами /iu: он знает про Unicode,
     * поэтому «граНат» и «ГРАНАТ» для него одно и то же, а не только
     * латиница, как у байтового stripos().
     */
    function mb_stripos(string $haystack, string $needle, int $offset = 0, ?string $encoding = null)
    {
        if ($needle === '') {
            return false;
        }
        $byteOffset = $offset > 0 ? strlen(mb_substr($haystack, 0, $offset)) : 0;
        $ok = @preg_match('/' . preg_quote($needle, '/') . '/iu', $haystack, $m, PREG_OFFSET_CAPTURE, $byteOffset);
        if ($ok !== 1) {
            return false;
        }
        return mb_strlen(substr($haystack, 0, $m[0][1]));
    }
}
