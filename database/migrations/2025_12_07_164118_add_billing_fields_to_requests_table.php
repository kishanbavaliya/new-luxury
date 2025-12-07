<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBillingFieldsToRequestsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
         Schema::table('requests', function (Blueprint $table) {
            $table->string('invoice_type')->nullable();
            $table->string('billing_country')->nullable();
            $table->string('billing_first_name')->nullable();
            $table->string('billing_last_name')->nullable();
            $table->string('billing_address')->nullable();
            $table->string('billing_city')->nullable();
            $table->string('billing_zipcode')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropColumn([
                'invoice_type',
                'billing_country',
                'billing_first_name',
                'billing_last_name',
                'billing_address',
                'billing_city',
                'billing_zipcode',
            ]);
        });
    }
}
