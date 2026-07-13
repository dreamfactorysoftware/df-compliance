<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class MakeServiceReportServiceNameNullable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table(
            'service_report',
            function (Blueprint $t) {
                $t->string('service_name')->nullable()->change();
            }
        );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table(
            'service_report',
            function (Blueprint $t) {
                $t->string('service_name')->nullable(false)->change();
            }
        );
    }
}
