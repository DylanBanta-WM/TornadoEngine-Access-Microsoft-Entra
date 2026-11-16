<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each rule gives one role to a group's members (by group ID) or one user (by email).
        Schema::create('access_microsoft_entra_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('access_roles')->cascadeOnDelete();
            $table->string('type', 10);
            $table->string('value');
            $table->string('label')->nullable(); // the group's name
            $table->unsignedInteger('matched')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_microsoft_entra_rules');
    }
};
