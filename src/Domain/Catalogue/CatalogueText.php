<?php
declare(strict_types=1);
namespace App\Domain\Catalogue;

/**
 * Text normalization shared by the stored search text and search queries, so both sides
 * compare the same way: case- and accent-insensitive, punctuation as spaces, "st" as "saint",
 * and trail references without separators ("GR 20", "gr-20" and "GR20" are all "gr20").
 * Non-Latin scripts are kept as they are.
 */
final class CatalogueText
{
    /** Words ignored in a query unless the query has nothing else. */
    private const STOP_WORDS = ['a', 'au', 'aux', 'd', 'de', 'des', 'du', 'et', 'l', 'la', 'le', 'les', 'and', 'of', 'the', 'to', 'y', 'el', 'del', 'da', 'do', 'der', 'die', 'das', 'von'];

    /**
     * Words that name the same thing in several languages. A query word belonging to one of
     * these stems matches any of them, so "Compostelle" also finds "Camino de Santiago".
     */
    private const EQUIVALENTS = [
        // Not "camino": in Spanish it names any path. The Ways of St James keep it as a keyword.
        ['compostel', 'santiago', 'jacques', 'jakobsweg', 'jacobsweg', 'jakob', 'jacobus', 'james'],
        ['boucle', 'loop', 'circuit'],
        ['cascade', 'waterfall'],
        ['lac', 'lake', 'lago'],
        ['sentier', 'trail', 'path', 'chemin'],
        ['tour', 'round'],
    ];

    private const ACCENTS = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'ĉ' => 'c', 'ċ' => 'c', 'ď' => 'd', 'đ' => 'd', 'ð' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e', 'ĕ' => 'e',
        'ğ' => 'g', 'ĝ' => 'g', 'ġ' => 'g', 'ģ' => 'g', 'ĥ' => 'h', 'ħ' => 'h',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ĩ' => 'i', 'ī' => 'i', 'į' => 'i', 'ı' => 'i', 'ĭ' => 'i',
        'ĵ' => 'j', 'ķ' => 'k', 'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l', 'ŀ' => 'l', 'ł' => 'l',
        'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'ő' => 'o', 'ŏ' => 'o',
        'ŕ' => 'r', 'ř' => 'r', 'ŗ' => 'r', 'ś' => 's', 'š' => 's', 'ş' => 's', 'ŝ' => 's', 'ș' => 's',
        'ť' => 't', 'ţ' => 't', 'ț' => 't', 'ŧ' => 't',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ũ' => 'u', 'ū' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u', 'ŭ' => 'u',
        'ŵ' => 'w', 'ý' => 'y', 'ÿ' => 'y', 'ŷ' => 'y', 'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
        'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss', 'þ' => 'th',
        '’' => ' ', '‘' => ' ', '´' => ' ', '`' => ' ', '®' => ' ', '©' => ' ', '™' => ' ',
    ];

    public static function fold(string $text): string
    {
        $text = strtr(mb_strtolower($text, 'UTF-8'), self::ACCENTS);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? '';
        $text = preg_replace('/(?<=^| )st(?= |$)/', 'saint', $text) ?? $text;
        $text = preg_replace('/(?<=^| )ste(?= |$)/', 'sainte', $text) ?? $text;
        return trim(preg_replace('/ +/', ' ', $text) ?? '');
    }

    /** A trail reference as searched: "GR® 20" → "gr20"; null without letters or digits. */
    public static function refKey(?string $ref): ?string
    {
        if ($ref === null) return null;
        $key = str_replace(' ', '', self::fold($ref));
        return $key === '' ? null : $key;
    }

    /**
     * Query words, each as the list of stems any of which must appear in the search text.
     *
     * @return list<list<string>>
     */
    public static function terms(string $query): array
    {
        $words = array_values(array_filter(explode(' ', self::fold($query)), fn(string $w) => $w !== ''));
        $useful = array_values(array_filter($words, fn(string $w) => !in_array($w, self::STOP_WORDS, true)));
        if ($useful !== []) $words = $useful;
        return array_map(function (string $word): array {
            foreach (self::EQUIVALENTS as $stems) {
                foreach ($stems as $stem) {
                    if (mb_strlen($word) >= 4 && (str_starts_with($word, $stem) || str_starts_with($stem, $word))) return $stems;
                }
            }
            return [$word];
        }, array_slice($words, 0, 8));
    }
}
