<?php
namespace dlds\galleryManager\migrations;

use yii\db\Schema;
use yii\db\Migration;

class m140930_003227_gallery_manager extends Migration
{
    public function up()
    {

        $this->createTable(
            '{{%gallery_image}}',
            array(
                'id' => Schema::TYPE_PK,
                'owner_type' => Schema::TYPE_STRING,
                'owner_id' => Schema::TYPE_STRING . ' NOT NULL',
                'rank' => Schema::TYPE_INTEGER . ' NOT NULL DEFAULT 0',
                'name' => Schema::TYPE_STRING,
                'description' => Schema::TYPE_TEXT
            )
        );
    }

    public function down()
    {
        $this->dropTable('{{%gallery_image}}');
    }
}
