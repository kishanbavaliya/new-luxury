<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddHourlyBasePricesToZoneTypePriceTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('zone_type_price', function (Blueprint $table) {
            if (!Schema::hasColumn('zone_type_price', 'hourly_base_prices')) {
                $table->json('hourly_base_prices')->nullable()->after('booking_hourly_price');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('zone_type_price', function (Blueprint $table) {
            if (Schema::hasColumn('zone_type_price', 'hourly_base_prices')) {
                $table->dropColumn('hourly_base_prices');
            }
        });
    }
}
