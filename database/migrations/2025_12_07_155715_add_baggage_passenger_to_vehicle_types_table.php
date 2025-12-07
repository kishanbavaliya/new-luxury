<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBaggagePassengerToVehicleTypesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('vehicle_types', function (Blueprint $table) {
            $table->string('baggage')->after('name'); 
            $table->string('passenger')->after('baggage');
            $table->json('includes')->nullable()->after('passenger');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('vehicle_types', function (Blueprint $table) {
            $table->dropColumn(['baggage', 'passenger', 'includes']);
        });
    }
}
