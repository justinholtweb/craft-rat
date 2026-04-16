<?php

namespace justinholtweb\rat\models;

use Craft;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\elements\User;
use DateTime;

class EditLog extends Model
{
    public ?int $id = null;
    public ?int $elementId = null;
    public ?int $siteId = null;
    public ?int $userId = null;
    public ?string $elementType = null;
    public ?string $elementLabel = null;
    public bool $isNew = false;
    public ?string $dirtyAttributes = null;
    public ?DateTime $dateCreated = null;

    protected function defineRules(): array
    {
        return [
            [['elementId', 'siteId', 'elementType'], 'required'],
            [['elementId', 'siteId', 'userId'], 'integer'],
            [['elementType', 'elementLabel'], 'string', 'max' => 255],
            [['isNew'], 'boolean'],
        ];
    }

    public function getUser(): ?User
    {
        if (!$this->userId) {
            return null;
        }

        return User::find()->id($this->userId)->one();
    }

    public function getElement(): ?ElementInterface
    {
        if (!$this->elementId || !$this->elementType) {
            return null;
        }

        return Craft::$app->getElements()->getElementById($this->elementId, $this->elementType, $this->siteId);
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
}
