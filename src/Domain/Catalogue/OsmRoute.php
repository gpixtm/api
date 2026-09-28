<?php
declare(strict_types=1);
namespace App\Domain\Catalogue;

/**
 * What an OpenStreetMap route relation's tags say about a trail: its displayed name, the
 * details kept for the trail page, the searchable text and the roles of its members.
 * Names and descriptions are source content and are never translated.
 */
final class OsmRoute
{
    public const NAME_LENGTH = 300;
    public const DESCRIPTION_LENGTH = 5000;
    /** A description this short can name an otherwise unnamed route. */
    public const SHORT_NAME = 80;

    /** Way roles that belong to the route's own line; alternatives and excursions do not. */
    private const LINE_ROLES = ['', 'main', 'forward', 'backward', 'route', 'hiking', 'section'];

    /** Tags whose words help find the trail, besides its name. */
    private const SEARCH_TAGS = ['name', 'official_name', 'osmc:name', 'alt_name', 'short_name', 'loc_name', 'name:fr', 'name:en', 'name:es', 'name:it', 'name:de', 'name:pt', 'name:ca', 'name:eu', 'name:oc', 'name:co', 'ref', 'from', 'to', 'via'];

    /**
     * The displayed name: the route's name or reference, else the name of its
     * waymarks (`osmc:name`), else "from – to", else a short description such as
     * "Wissembourg - Weiler - Climbach", common on local waymarked networks.
     */
    public static function name(array $tags): ?string
    {
        foreach (['name', 'official_name', 'ref', 'osmc:name'] as $key) {
            $value = self::text($tags[$key] ?? null, self::NAME_LENGTH);
            if ($value !== null) return $value;
        }
        $from = self::text($tags['from'] ?? null, 140);
        $to = self::text($tags['to'] ?? null, 140);
        if ($from !== null && $to !== null) return $from.' – '.$to;
        $description = self::text($tags['description'] ?? null, self::DESCRIPTION_LENGTH);
        return $description !== null && mb_strlen($description) <= self::SHORT_NAME ? $description : null;
    }

    /** The trail page details, without empty values. */
    public static function details(string $type, int $id, array $tags): array
    {
        $details = [];
        foreach (['ref' => 'ref', 'network' => 'network', 'osmc:symbol' => 'marking', 'colour' => 'colour', 'from' => 'from', 'to' => 'to', 'via' => 'via',
            'operator' => 'operator', 'wikidata' => 'wikidata', 'wikipedia' => 'wikipedia', 'distance' => 'distance', 'ascent' => 'ascent', 'descent' => 'descent'] as $tag => $key) {
            $value = self::text($tags[$tag] ?? null, 300);
            if ($value !== null) $details[$key] = $value;
        }
        foreach (['website', 'url', 'contact:website'] as $tag) {
            $value = self::text($tags[$tag] ?? null, 500);
            if ($value !== null && preg_match('#^https?://#i', $value)) { $details['website'] = $value; break; }
        }
        if (in_array($tags['roundtrip'] ?? null, ['yes', 'no'], true)) $details['roundtrip'] = $tags['roundtrip'] === 'yes';
        if (($tags['pilgrimage'] ?? null) === 'yes') $details['pilgrimage'] = true;
        $descriptions = [];
        foreach ($tags as $key => $value) {
            if ($key === 'description' || preg_match('/^description:([a-z]{2,3})$/D', (string)$key, $m)) {
                $text = self::text($value, self::DESCRIPTION_LENGTH);
                if ($text !== null) $descriptions[$key === 'description' ? 'default' : $m[1]] = $text;
            }
        }
        if ($descriptions !== []) $details['descriptions'] = $descriptions;
        $details['osm'] = ['type' => $type, 'id' => $id];
        return $details;
    }

    /** The default description: the untagged one, else the first language given. */
    public static function description(array $details): string
    {
        $descriptions = $details['descriptions'] ?? [];
        return $descriptions['default'] ?? (reset($descriptions) ?: '');
    }

    public static function searchText(array $tags): string
    {
        $words = [];
        foreach (self::SEARCH_TAGS as $key) if (is_string($tags[$key] ?? null)) $words[] = $tags[$key];
        if (is_string($tags['wikipedia'] ?? null)) $words[] = preg_replace('/^[a-z-]+:/', '', $tags['wikipedia']);
        return CatalogueText::fold(implode(' ', array_unique($words)));
    }

    public static function isLineRole(string $role): bool { return in_array($role, self::LINE_ROLES, true); }

    /** A child route's role in its parent itinerary. */
    public static function memberRole(string $role): string
    {
        return match ($role) {
            'alternative', 'variant' => 'variant',
            'connection', 'link' => 'link',
            'excursion' => 'excursion',
            'approach' => 'approach',
            default => 'main',
        };
    }

    /**
     * Whether a route is one of the Ways of St James. Unambiguous names are enough; words that
     * also name places ("Santiago", "Saint-Jacques") need the pilgrimage tag or a route word.
     */
    public static function isWayOfStJames(array $tags): bool
    {
        $words = [];
        foreach ([...self::SEARCH_TAGS, 'description', 'description:fr', 'description:en', 'description:es'] as $key) if (is_string($tags[$key] ?? null)) $words[] = $tags[$key];
        $text = ' '.CatalogueText::fold(implode(' ', $words)).' ';
        if (preg_match('/ (compostel\w*|jakobsweg\w*|jacobsweg\w*|jakobusweg\w*|jacobusweg\w*|via podiensis|via turonensis|via lemovicensis|via tolosana|via gebennensis|via arverna|camino de santiago|caminho de santiago|cami de sant jaume|way of saint james|camino frances|camino del norte|camino primitivo|camino portugues|caminho portugues|via de la plata|camino ingles|camino aragones) /', $text)) return true;
        if (preg_match('/ (chemin|chemins|voie|voies|via|route|camino|caminho|way|weg) (de |du |des |d |of )?saint (jacques|jacob|james|jaume|jakob) /', $text)) return true;
        return ($tags['pilgrimage'] ?? null) === 'yes' && preg_match('/ (santiago|jacques|jakob\w*|jacob\w*|james|jaume|iago) /', $text) === 1;
    }

    private static function text(mixed $value, int $length): ?string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) return null;
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($value === '') return null;
        return mb_strlen($value) > $length ? rtrim(mb_substr($value, 0, $length - 1)).'…' : $value;
    }
}
