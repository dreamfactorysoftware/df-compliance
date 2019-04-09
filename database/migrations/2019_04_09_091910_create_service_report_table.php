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
        $driver = Schema::getConnection()->getDriverName();
        // Even though we take care of this scenario in the code,
        // SQL Server does not allow potential cascading loops,
        // so set the default no action and clear out created/modified by another user when deleting a user.
        $onDelete = (('sqlsrv' === $driver) ? 'no action' : 'set null');

        // service-reports
        Schema::create(
            'service_report',
            function (Blueprint $t) use ($onDelete){
                $t->increments('id');
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
        Schema::dropIfExists('service_reports');
    }
}
