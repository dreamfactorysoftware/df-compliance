<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateServiceReportTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // service-reports
        Schema::create(
            'service_report',
            function (Blueprint $t) {
                $t->increments('id');
                $t->integer('service_id')->nullable();
                $t->string('service_name');
                $t->string('user_email');
                $t->string('action')->nullable();
                $t->string('request_verb')->default(0);
                $t->timestamp('created_date')->nullable();
                $t->timestamp('last_modified_date')->useCurrent();
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
        // service-reports
        Schema::dropIfExists('service_report');
    }
}
