<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            // Quién recibe, aparte del dueño de la cuenta.
            $table->string('recipient_name', 100)->nullable()->after('user_id');

            // Ubicación DANE: los códigos son la fuente y los textos guardan el
            // nombre oficial para mostrar. Son nulos para no romper filas previas.
            $table->string('department_code', 2)->nullable()->after('shipping_zone_id');
            $table->string('department', 60)->nullable()->after('department_code');
            $table->string('city_code', 5)->nullable()->after('city');

            // El nombre casero («Casa», «Trabajo») y las indicaciones del repartidor.
            $table->string('label', 30)->nullable()->after('city_code');
            $table->string('instructions', 200)->nullable()->after('label');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropColumn([
                'recipient_name',
                'department_code',
                'department',
                'city_code',
                'label',
                'instructions',
            ]);
        });
    }
};
