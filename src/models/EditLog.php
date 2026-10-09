<?php

namespace justinholtweb\rat\models;

use Craft;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\elements\User;
use DateTime;

class EditLog extends Model
{
    /** A save: created or edited, told apart by {@see $isNew}. */
    public const ACTION_SAVE = 'save';
    /** Moved to the trash (a soft delete). */
    public const ACTION_DELETE = 'delete';
    /** Deleted permanently, so the element itself is gone. */
    public const ACTION_HARD_DELETE = 'hardDelete';
    /** Restored from the trash. */
    public const ACTION_RESTORE = 'restore';
    /** Moved within a structure. */
    public const ACTION_MOVE = 'move';

    /**
     * Actions that happen to the element as a whole rather than to its content on one site. An
     * element is deleted, restored or moved on every site at once, so these show in its history
     * whichever site it is viewed in.
     */
    public const ELEMENT_WIDE_ACTIONS = [
        self::ACTION_DELETE,
        self::ACTION_HARD_DELETE,
        self::ACTION_RESTORE,
        self::ACTION_MOVE,
    ];

    public ?int $id = null;
    public ?int $elementId = null;
    public ?int $siteId = null;
    public ?int $userId = null;
    public ?string $elementType = null;
    public ?string $elementLabel = null;
    public bool $isNew = false;
    public ?string $dirtyAttributes = null;
    public string $action = self::ACTION_SAVE;

    /**
     * JSON describing the action, where there's more to say than who and when. For a move: the
     * structure action, the element it was placed against, and where it was and is now.
     */
    public ?string $details = null;
    public ?DateTime $dateCreated = null;

    protected function defineRules(): array
    {
        return [
            [['elementId', 'siteId', 'elementType'], 'required'],
            [['elementId', 'siteId', 'userId'], 'integer'],
            [['elementType', 'elementLabel'], 'string', 'max' => 255],
            [['isNew'], 'boolean'],
            [['action'], 'in', 'range' => [
                self::ACTION_SAVE,
                self::ACTION_DELETE,
                self::ACTION_HARD_DELETE,
                self::ACTION_RESTORE,
                self::ACTION_MOVE,
            ]],
        ];
    }

    private ?User $_user = null;
    private bool $_userLoaded = false;
    private ?ElementInterface $_element = null;
    private bool $_elementLoaded = false;

    public function getUser(): ?User
    {
        if (!$this->_userLoaded) {
            $this->setUser($this->userId ? User::find()->id($this->userId)->one() : null);
        }

        return $this->_user;
    }

    /**
     * Hands over an already-loaded user, so a list of edits costs one user query rather than one
     * per row. See {@see \justinholtweb\rat\services\EditTracker::preload()}.
     */
    public function setUser(?User $user): void
    {
        $this->_user = $user;
        $this->_userLoaded = true;
    }

    public function getElement(): ?ElementInterface
    {
        if (!$this->_elementLoaded) {
            // Trashed elements included: an edit to something now in the trash is still visible to
            // whoever could see that element, and the element is still there to check against.
            $type = $this->elementType;
            $this->setElement($this->elementId && $type && is_subclass_of($type, ElementInterface::class)
                ? $type::find()->id($this->elementId)->siteId($this->siteId)->status(null)->trashed(null)->one()
                : null);
        }

        return $this->_element;
    }

    /**
     * Hands over an already-loaded element (or null for one that's gone). See
     * {@see \justinholtweb\rat\services\EditTracker::preload()}.
     */
    public function setElement(?ElementInterface $element): void
    {
        $this->_element = $element;
        $this->_elementLoaded = true;
    }

    public function getDirtyAttributesList(): array
    {
        if (!$this->dirtyAttributes) {
            return [];
        }

        return json_decode($this->dirtyAttributes, true) ?: [];
    }

    public function getElementTypeLabel(): string
    {
        $map = [
            'craft\\elements\\Entry' => 'Entry',
            'craft\\elements\\Asset' => 'Asset',
            'craft\\elements\\GlobalSet' => 'Global',
            'craft\\elements\\Category' => 'Category',
            'craft\\elements\\Tag' => 'Tag',
            'craft\\elements\\User' => 'User',
            'craft\\commerce\\elements\\Product' => 'Product',
            'craft\\commerce\\elements\\Variant' => 'Variant',
            'craft\\commerce\\elements\\Order' => 'Order',
        ];

        return $map[$this->elementType] ?? basename(str_replace('\\', '/', $this->elementType ?? ''));
    }

    /**
     * Whether this entry records the element being deleted, to the trash or permanently.
     */
    public function getIsDeletion(): bool
    {
        return $this->action === self::ACTION_DELETE || $this->action === self::ACTION_HARD_DELETE;
    }

    /**
     * The action as a title-case label, for the widget's Action column.
     */
    public function getActionLabel(): string
    {
        return match ($this->action) {
            self::ACTION_DELETE => Craft::t('rat', 'Deleted'),
            self::ACTION_HARD_DELETE => Craft::t('rat', 'Deleted permanently'),
            self::ACTION_RESTORE => Craft::t('rat', 'Restored'),
            self::ACTION_MOVE => Craft::t('rat', 'Moved'),
            default => $this->isNew ? Craft::t('rat', 'Created') : Craft::t('rat', 'Edited'),
        };
    }

    /**
     * The action as it reads after the editor's name in the sidebar: "Jane *deleted*".
     */
    public function getActionVerb(): string
    {
        return match ($this->action) {
            self::ACTION_DELETE => Craft::t('rat', 'deleted'),
            self::ACTION_HARD_DELETE => Craft::t('rat', 'deleted permanently'),
            self::ACTION_RESTORE => Craft::t('rat', 'restored'),
            self::ACTION_MOVE => Craft::t('rat', 'moved'),
            default => $this->isNew ? Craft::t('rat', 'created') : Craft::t('rat', 'edited'),
        };
    }

    /**
     * The colour of the status dot beside the action label.
     */
    public function getActionColor(): string
    {
        return match ($this->action) {
            self::ACTION_DELETE => 'red',
            self::ACTION_HARD_DELETE => 'black',
            self::ACTION_RESTORE => 'turquoise',
            self::ACTION_MOVE => 'blue',
            default => $this->isNew ? 'green' : 'orange',
        };
    }

    public function getDetailsList(): array
    {
        if (!$this->details) {
            return [];
        }

        $details = json_decode($this->details, true);

        return is_array($details) ? $details : [];
    }

    /**
     * Describes a structure move in a sentence: what it was placed against, and where it was and
     * is now. Labels are the ones recorded at the time, so the sentence still reads right after
     * the elements involved are renamed or deleted. Null for anything other than a move.
     */
    public function getMoveSummary(): ?string
    {
        if ($this->action !== self::ACTION_MOVE) {
            return null;
        }

        $details = $this->getDetailsList();
        $target = isset($details['targetLabel']) ? (string)$details['targetLabel'] : null;

        $placed = match ($details['action'] ?? null) {
            'placeBefore' => $target !== null ? Craft::t('rat', 'Placed before “{label}”.', ['label' => $target]) : null,
            'placeAfter' => $target !== null ? Craft::t('rat', 'Placed after “{label}”.', ['label' => $target]) : null,
            'prepend' => $target !== null
                ? Craft::t('rat', 'Moved to the start of “{label}”.', ['label' => $target])
                : Craft::t('rat', 'Moved to the start of the top level.'),
            'append' => $target !== null
                ? Craft::t('rat', 'Moved to the end of “{label}”.', ['label' => $target])
                : Craft::t('rat', 'Moved to the end of the top level.'),
            default => null,
        };

        $from = is_array($details['from'] ?? null) ? $this->describePosition($details['from']) : null;
        $to = is_array($details['to'] ?? null) ? $this->describePosition($details['to']) : null;

        $parts = array_filter([
            $placed,
            $from !== null ? Craft::t('rat', 'Was {position}.', ['position' => $from]) : null,
            $to !== null ? Craft::t('rat', 'Now {position}.', ['position' => $to]) : null,
        ]);

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * @param array{parentId?: int|null, parentLabel?: string|null, position?: int|null} $position
     */
    private function describePosition(array $position): string
    {
        $place = !empty($position['parentId'])
            ? Craft::t('rat', 'under “{label}”', ['label' => (string)($position['parentLabel'] ?? '#' . $position['parentId'])])
            : Craft::t('rat', 'at the top level');

        if (!empty($position['position'])) {
            $place .= ', ' . Craft::t('rat', 'position {num}', ['num' => (int)$position['position']]);
        }

        return $place;
    }
}
