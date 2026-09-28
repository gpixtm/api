<?php
declare(strict_types=1);
namespace App\Infrastructure\Catalogue;

/**
 * Streams ways and relations from osmium's OPL text format, one object per line. Ways must
 * carry node locations (`osmium add-locations-to-ways`). Tag values, keys and roles are
 * unescaped from OPL's `%hex%` code points.
 */
final class OplReader
{
    /**
     * @param resource $stream
     * @return \Generator<array{type:'w',id:int,first:int,last:int,points:list<array{float,float}>}|array{type:'r',id:int,tags:array<string,string>,members:list<array{string,int,string}>}>
     */
    public static function read($stream): \Generator
    {
        while (($line = fgets($stream)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') continue;
            $type = $line[0];
            if ($type !== 'w' && $type !== 'r') continue;
            $fields = explode(' ', $line);
            $id = (int)substr($fields[0], 1);
            $tags = ''; $list = '';
            foreach ($fields as $field) {
                if ($field === '') continue;
                if ($field[0] === 'T') $tags = substr($field, 1);
                elseif ($field[0] === ($type === 'w' ? 'N' : 'M')) $list = substr($field, 1);
            }
            if ($type === 'w') {
                if ($list === '') continue;
                $nodes = explode(',', $list);
                preg_match_all('/x(-?[0-9.]+)y(-?[0-9.]+)/', $list, $m, PREG_SET_ORDER);
                $points = array_map(fn(array $p) => [(float)$p[2], (float)$p[1]], $m);
                yield ['type' => 'w', 'id' => $id, 'first' => self::nodeId($nodes[0]), 'last' => self::nodeId($nodes[count($nodes) - 1]), 'points' => $points];
            } else {
                $members = [];
                if ($list !== '') {
                    foreach (explode(',', $list) as $member) {
                        $at = strpos($member, '@');
                        if ($at === false || $member === '') continue;
                        $members[] = [$member[0], (int)substr($member, 1, $at - 1), self::unescape(substr($member, $at + 1))];
                    }
                }
                yield ['type' => 'r', 'id' => $id, 'tags' => self::tags($tags), 'members' => $members];
            }
        }
    }

    /** @return array<string,string> */
    public static function tags(string $text): array
    {
        $tags = [];
        if ($text === '') return $tags;
        foreach (explode(',', $text) as $pair) {
            $equals = strpos($pair, '=');
            if ($equals === false) continue;
            $tags[self::unescape(substr($pair, 0, $equals))] = self::unescape(substr($pair, $equals + 1));
        }
        return $tags;
    }

    public static function unescape(string $text): string
    {
        if (!str_contains($text, '%')) return $text;
        return preg_replace_callback('/%([0-9a-fA-F]+)%/', fn(array $m) => mb_chr((int)hexdec($m[1]), 'UTF-8') ?: '', $text) ?? $text;
    }

    private static function nodeId(string $node): int
    {
        $x = strpos($node, 'x');
        return (int)substr($node, 1, $x === false ? null : $x - 1);
    }
}
