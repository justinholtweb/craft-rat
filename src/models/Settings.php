<?php

namespace justinholtweb\rat\models;

use craft\base\Model;

/**
 * What Rat records, and for how long.
 */
class Settings extends Model
{
    /**
     * Days to keep edit-log rows. Older rows are pruned during Craft's garbage collection, or by
     * `php craft rat/log/prune`. 0 keeps them forever.
     */
    public int $retentionDays = 90;

    /**
     * Record saves made on the front end by visitors who aren't signed in. Off by default: those
     * are carts recalculating and forms submitting — on a store, several rows per cart action —
     * not anybody editing content.
     */
    public bool $trackAnonymousSiteSaves = false;

    /**
     * Element types never recorded, as class names. Commerce orders by default: a cart is an
     * order, and it is saved on every change to it. Commerce keeps its own order history.
     *
     * @var string[]
     */
    public array $excludedElementTypes = ['craft\\commerce\\elements\\Order'];

    protected function defineRules(): array
    {
        return [
            [['retentionDays'], 'integer', 'min' => 0],
            [['trackAnonymousSiteSaves'], 'boolean'],
            [['excludedElementTypes'], 'each', 'rule' => ['string']],
        ];
    }

    public function setAttributes($values, $safeOnly = true): void
    {
        // The settings screen posts the exclusions as a one-per-line textarea.
        if (isset($values['excludedElementTypes']) && is_string($values['excludedElementTypes'])) {
            $values['excludedElementTypes'] = array_values(array_filter(array_map(
                fn(string $line) => ltrim(trim($line), '\\'),
                preg_split('/[\r\n,]+/', $values['excludedElementTypes']) ?: [],
            )));
        }

        parent::setAttributes($values, $safeOnly);
    }
}
