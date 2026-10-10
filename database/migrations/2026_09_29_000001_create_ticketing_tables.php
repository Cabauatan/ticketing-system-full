<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('requester');
        });
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('users');
            $table->foreignId('assignee_id')->nullable()->constrained('users');
            $table->string('subject', 200);
            $table->mediumText('description');
            $table->string('category', 40);
            $table->string('priority', 20)->default('low');
            $table->string('status', 20)->default('new');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['requester_id', 'status']);
            $table->index(['assignee_id', 'status']);
            $table->index(['status', 'priority', 'created_at']);
        });
        Schema::create('ticket_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->text('body');
            $table->boolean('internal')->default(false);
            $table->timestamps();
            $table->index(['ticket_id', 'created_at']);
        });
        Schema::create('ticket_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained();
            $table->foreignId('actor_id')->constrained('users');
            $table->string('action', 30);
            $table->text('detail');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_events');
        Schema::dropIfExists('ticket_comments');
        Schema::dropIfExists('tickets');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('role'));
    }
};
