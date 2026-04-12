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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('type'); // 'deposit' or 'transfer'
            $table->decimal('amount', 10, 2);
            $table->string('description')->nullable();
            $table->string('status')->default('completed'); // 'pending', 'completed', 'reversed', 'failed'
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('original_transaction_id')->nullable()->constrained('transactions')->onDelete('cascade');
            $table->string('reversal_reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
