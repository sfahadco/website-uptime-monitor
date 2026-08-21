<?php

use App\Enums\WebsiteStatusEnum;
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
        Schema::create('websites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('url');
            $table->enum('status', WebsiteStatusEnum::values())->default(WebsiteStatusEnum::UNKNOWN->value);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'url']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('websites');
    }
};
