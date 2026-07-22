<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('initial_prelists', function (Blueprint $table) {
            $table->id();
            $table->string('idsubsls')->unique();
            $table->string('kdkec')->nullable()->index();
            $table->string('nmkec')->nullable();
            $table->string('kddes')->nullable()->index();
            $table->string('nmdesa')->nullable();
            $table->string('kdsls')->nullable()->index();
            $table->string('kdsubsls')->nullable();
            $table->string('nmsls')->nullable();
            $table->string('nmsubsls')->nullable();
            $table->unsignedInteger('total_assignment_fasih')->default(0);
            $table->string('source_sheet')->nullable();
            $table->string('source_file')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('initial_prelists');
    }
};
