<?php

namespace justinholtweb\rat\migrations;

use craft\db\Migration;
use craft\helpers\MigrationHelper;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%rat_editlog}}', [
            'id' => $this->primaryKey(),
            'elementId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'userId' => $this->integer()->null(),
            'elementType' => $this->string(255)->notNull(),
            'elementLabel' => $this->string(255)->null(),
            'isNew' => $this->boolean()->defaultValue(false)->notNull(),
            'dirtyAttributes' => $this->text()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%rat_editlog}}', ['elementId']);
        $this->createIndex(null, '{{%rat_editlog}}', ['userId']);
        $this->createIndex(null, '{{%rat_editlog}}', ['elementType']);
        $this->createIndex(null, '{{%rat_editlog}}', ['dateCreated']);
        $this->createIndex(null, '{{%rat_editlog}}', ['elementId', 'siteId']);

        $this->addForeignKey(null, '{{%rat_editlog}}', ['elementId'], '{{%elements}}', ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%rat_editlog}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%rat_editlog}}', ['userId'], '{{%users}}', ['id'], 'SET NULL', null);

        return true;
    }

    public function safeDown(): bool
    {
        MigrationHelper::dropTable('{{%rat_editlog}}', $this);

        return true;
    }
}
