<?php

namespace justinholtweb\rat\migrations;

use craft\db\Migration;

/**
 * Lets the log record deletes, restores and structure moves, not only saves.
 *
 * Adds `action` (every existing row is a save) and `details`, and drops the foreign key that
 * deleted an element's log rows along with the element. That cascade meant a permanent delete
 * erased the history of whatever was deleted, including any record of who deleted it.
 */
class m261008_000000_record_deletes_restores_and_moves extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%rat_editlog}}';

        if (!$this->db->columnExists($table, 'action')) {
            $this->addColumn($table, 'action', $this->string(20)->notNull()->defaultValue('save')->after('isNew'));
            $this->createIndex(null, $table, ['action']);
        }

        if (!$this->db->columnExists($table, 'details')) {
            $this->addColumn($table, 'details', $this->text()->null()->after('action'));
        }

        $this->dropForeignKeyIfExists($table, ['elementId']);

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261008_000000_record_deletes_restores_and_moves cannot be reverted.\n";

        return false;
    }
}
