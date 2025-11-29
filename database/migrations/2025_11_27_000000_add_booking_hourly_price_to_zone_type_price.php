<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBookingHourlyPriceToZoneTypePrice extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('zone_type_price')) {
            Schema::table('zone_type_price', function (Blueprint $table) {
                if (!Schema::hasColumn('zone_type_price', 'booking_hourly_price')) {
                    $table->double('booking_hourly_price', 10, 2)->default(0)->after('price_per_time');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable('zone_type_price')) {
            Schema::table('zone_type_price', function (Blueprint $table) {
                if (Schema::hasColumn('zone_type_price', 'booking_hourly_price')) {
                    $table->dropColumn('booking_hourly_price');
                }
            });
        }
    }
}
