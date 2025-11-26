<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAsOwnerAsDriverToOwnerNeededDocumentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('owner_needed_documents', function (Blueprint $table) {
            // New columns to indicate whether the document is required for owner and/or driver
            $table->boolean('as_owner')->default(false)->after('active');
            $table->boolean('as_driver')->default(false)->after('as_owner');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('owner_needed_documents', function (Blueprint $table) {
            $table->dropColumn(['as_owner', 'as_driver']);
        });
    }
}
