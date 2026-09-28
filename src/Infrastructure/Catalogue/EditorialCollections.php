<?php
declare(strict_types=1);
namespace App\Infrastructure\Catalogue;

use App\Domain\Catalogue\CatalogueText;
use App\Domain\Catalogue\OsmCataloguePlan;

/**
 * Collections Gpix curates across sources and countries. Phones translate their name and
 * description from `details.editorial`, a stable key; `name` is the English fallback. Their
 * search text holds the words walkers use for them in several languages. Membership is
 * refreshed by each acquisition run.
 */
final class EditorialCollections
{
    public const SOURCE = 'gpix';

    public const ST_JAMES = [
        'ref' => 'collection/st-james',
        'name' => 'Ways of St James',
        'description' => '',
        'details' => ['editorial' => 'st-james'],
        'keywords' => OsmCataloguePlan::ST_JAMES_KEYWORDS,
    ];

    public static function searchText(array $collection): string
    {
        return CatalogueText::fold($collection['name'].' '.$collection['keywords']);
    }
}
