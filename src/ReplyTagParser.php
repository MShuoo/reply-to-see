<?php

namespace Mshuo\ReplyToSee;

class ReplyTagParser
{
    private const OPEN_TAG = '[REPLY]';
    private const CLOSE_TAG = '[/REPLY]';
    private const OPEN_LEN = 7;
    private const CLOSE_LEN = 8;

    public static function replace(string $s, string $replace): string
    {
        $len = strlen($s);
        if ($len === 0 || (strpos($s, self::OPEN_TAG) === false && strpos($s, self::CLOSE_TAG) === false)) {
            return $s;
        }

        $result = '';
        $stack = [];
        $pos = 0;
        while ($pos < $len) {
            $openPos = strpos($s, self::OPEN_TAG, $pos);
            $closePos = strpos($s, self::CLOSE_TAG, $pos);
            if ($openPos === false && $closePos === false) {
                $result .= substr($s, $pos);
                break;
            }
            if ($openPos !== false && ($closePos === false || $openPos < $closePos)) {
                $result .= substr($s, $pos, $openPos - $pos);
                $stack[] = strlen($result);
                $result .= self::OPEN_TAG;
                $pos = $openPos + self::OPEN_LEN;
            } else {
                $result .= substr($s, $pos, $closePos - $pos);
                if (!empty($stack)) {
                    $lastPos = array_pop($stack);
                    $result = substr($result, 0, $lastPos) . $replace;
                }
                $pos = $closePos + self::CLOSE_LEN;
            }
        }

        // 未闭合时从最早的开始标签截到文末，避免后面的正文漏出
        if (!empty($stack)) {
            $result = substr($result, 0, $stack[0]) . $replace;
        }

        return $result;
    }

    public static function strip(string $s): string
    {
        $len = strlen($s);
        if ($len === 0 || (strpos($s, self::OPEN_TAG) === false && strpos($s, self::CLOSE_TAG) === false)) {
            return $s;
        }

        $result = '';
        $stack = [];
        $pos = 0;
        while ($pos < $len) {
            $openPos = strpos($s, self::OPEN_TAG, $pos);
            $closePos = strpos($s, self::CLOSE_TAG, $pos);
            if ($openPos === false && $closePos === false) {
                $result .= substr($s, $pos);
                break;
            }
            if ($openPos !== false && ($closePos === false || $openPos < $closePos)) {
                $result .= substr($s, $pos, $openPos - $pos);
                $stack[] = strlen($result);
                $result .= self::OPEN_TAG;
                $pos = $openPos + self::OPEN_LEN;
            } else {
                $result .= substr($s, $pos, $closePos - $pos);
                if (!empty($stack)) {
                    $openStart = array_pop($stack);
                    $result = substr($result, 0, $openStart) . substr($result, $openStart + self::OPEN_LEN);
                }
                $pos = $closePos + self::CLOSE_LEN;
            }
        }

        // 从后往前删落单的开始标签，前面的位置才不会被后面的删除弄偏
        while (!empty($stack)) {
            $openStart = array_pop($stack);
            $result = substr($result, 0, $openStart) . substr($result, $openStart + self::OPEN_LEN);
        }

        return $result;
    }
}
