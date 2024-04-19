<?php

namespace classes;

use Exception;
use mysqli;

class Database implements DatabaseInterface
{
    private mysqli $mysqli;
    protected static string $bindPattern  = '#(\?[\#\w\d]*)#us';
    protected static string $blockPattern = '#({[^}]*})#us';

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * @throws Exception
     */
    public function buildQuery(string $query, array $args = []): string
    {
        $this->validateBrackets($query);

        $binds = [];

        // Получение параметров ? и их позиций из запроса
        $matches = [];
        preg_match_all(self::$bindPattern, $query, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$pattern, $position]) {
            $binds[] = [
                'position' => $position,
                'pattern'  => $pattern,
                'value'    => $this->prepareArg(current($args), $pattern)
            ];
            next($args);
        }

        // Пост-проверка синтаксиса параметров
        if (!empty(array_diff(array_column($binds, 'pattern'), ['?', '?d', '?f', '?a', '?#']))) {
            throw new Exception('Invalid query params syntax');
        }

        // Пост-проверка соответствия количества параметров и аргументов
        if (count($binds) != count($args)) {
            throw new Exception('Invalid query args count');
        }

        // Массив параметров в формате Позиция => Значение
        $binds = array_column($binds, 'value', 'position');

        // Получение блоков и их позиций из запроса
        $matches = [];
        preg_match_all(self::$blockPattern, $query, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$block, $start]) {
            $length = mb_strlen($block);

            // Получение параметров входящих в блок
            $items = array_filter($binds, fn($key) => $start <= $key && $key <= $start + $length, ARRAY_FILTER_USE_KEY);

            // Проверка налчиия SKIP параметра внутри блока
            if (array_search($this->skip(), $items)) {
                $value = str_repeat(' ', $length); // Замена блока на пустую строку той же длины
                $binds = array_diff_key($binds, $items); // Удаление параметров блока
            } else {
                $value = str_replace(['{', '}'], ' ', $block); // Замена на блок без скобок, той же длины
            }

            // Обновление блока в запросе
            $query = substr_replace($query, $value, $start, $length);
        }

        // Последовательно заменяем каждый параметр на его значение
        foreach ($binds as $bind) {
            $query = preg_replace(self::$bindPattern, $bind, $query, 1);
        }

        return trim(preg_replace('#\s+#u', ' ', $query));
    }

    /**
     * @return string
     */
    public function skip(): string
    {
        static $value = sprintf('skip@%s', sha1('skip'));
        return $value;
    }

    /**
     * @param  string  $query
     * @return void
     * @throws Exception
     */
    protected function validateBrackets(string $query): void
    {
        $inspector = 0;
        $brackets = preg_replace('#[^{}]*#u', '', $query);

        foreach (str_split($brackets) as $bracket) {
            $inspector += ($bracket === "{" ? 1 : -1);

            if (0 > $inspector || $inspector > 1) {
                throw new Exception('Invalid query params syntax');
            }
        }
    }

    /**
     * @param  mixed  $arg
     * @param  mixed|null  $type
     * @return int|float|string|null
     * @throws Exception
     */
    protected function prepareArg(mixed $arg, mixed $type = null): null|int|float|string
    {
        if ($arg === $this->skip()) {
            return $this->skip();
        }

        switch ($type) {
            case 'column':
                if (mb_strlen((string) $arg) === 0) {
                    throw new Exception('Table column name can\'t be empty');
                }

                return str_replace('`', '``', $arg);

            case '?d':
                return is_null($arg) ? 'NULL' : (int) $arg;

            case '?f':
                // float - число с плавающей точкой, могут быть проблемы с точность
                return is_null($arg) ? 'NULL' : (float) $arg;

            case '?a':
                $arg = (array) $arg; // Одиночные значения преобразуем в массив для унификации

                if (empty($arg)) {
                    throw new Exception(sprintf('Args "%s" types can\'t be empty', $type));
                }

                // Строгая проверка - последовательным считается массив с порядком ключей от 0 .. n и шагом +1
                //$is_assoc = !array_is_list($arg);

                // Мягкая проверка - последовательным считается массив c числовыми ключами в любой последовательности
                $is_assoc = (bool) array_filter($arg, fn($key) => !ctype_digit((string) $key), ARRAY_FILTER_USE_KEY);

                $arg = array_map(
                    function ($key, $value) use ($is_assoc) {
                        return $is_assoc
                            ? sprintf('`%s` = %s', $this->prepareArg($key, 'column'), $this->prepareArg($value))
                            : $this->prepareArg($value);
                    },
                    array_keys($arg),
                    $arg
                );

                return implode(', ', $arg);

            case '?#':
                $arg = (array) $arg; // Одиночные значения преобразуем в массив для унификации

                if (empty($arg)) {
                    throw new Exception(sprintf('Args "%s" types can\'t be empty', $type));
                }

                $arg = array_map(
                    fn($value) => sprintf('`%s`', $this->prepareArg($value, 'column')),
                    $arg
                );

                return implode(', ', $arg);

            default:
                // ?, string, float, int, bool (приводится к 0 или 1) и null в соответсвии с типом знаения
                return match (true) {
                    is_string($arg) => sprintf("'%s'", $this->mysqli->real_escape_string($arg)),
                    is_float($arg)  => (float) $arg,
                    is_int($arg)    => (int) $arg,
                    is_bool($arg)   => (int) $arg,
                    is_null($arg)   => 'NULL',
                    default         => throw new Exception('Denied arg type')
                };
        }
    }
}
