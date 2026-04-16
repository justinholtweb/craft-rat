<?php

namespace justinholtweb\rat\records;

use craft\db\ActiveRecord;

class EditLogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%rat_editlog}}';
    }
}
