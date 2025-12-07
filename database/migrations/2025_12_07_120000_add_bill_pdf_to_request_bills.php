<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBillPdfToRequestBills extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('requests')) {
            if (!Schema::hasColumn('requests', 'bill_pdf')) {
                Schema::table('requests', function (Blueprint $table) {
                    $table->string('bill_pdf')->nullable();
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable('requests')) {
            if (Schema::hasColumn('requests', 'bill_pdf')) {
                Schema::table('requests', function (Blueprint $table) {
                    $table->dropColumn('bill_pdf');
                });
            }
        }
    }
}
