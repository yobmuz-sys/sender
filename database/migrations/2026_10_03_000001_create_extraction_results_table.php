<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extraction_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('extraction_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->unique(['extraction_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extraction_results');
    }
};
