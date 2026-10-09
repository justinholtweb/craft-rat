<?php

namespace justinholtweb\rat\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $elementId
 * @property int $siteId
 * @property int|null $userId
 * @property string $elementType
 * @property string|null $elementLabel
 * @property bool $isNew
 * @property string $action
 * @property string|null $details
 * @property string $dateCreated
 */
class EditLogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%rat_editlog}}';
    }
}
