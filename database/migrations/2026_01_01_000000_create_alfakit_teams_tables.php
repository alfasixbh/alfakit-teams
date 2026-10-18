<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Teams, memberships (with a role) and invitations. Already have a `teams` table? Add the
 * missing columns (`slug`, `logo_path`, `personal_team`, `auto_join_domain`) instead and
 * point `TeamsPlugin::teamModel()` at your model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('logo_path')->nullable();
            $table->boolean('personal_team')->default(false);
            $table->string('auto_join_domain')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('team_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 64);
            $table->timestamps();

            $table->unique(['team_id', 'user_id']);
        });

        Schema::create('team_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 64);
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'email']);
        });

        if (! Schema::hasColumn('users', 'current_team_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->foreignId('current_team_id')->nullable()->constrained('teams')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'current_team_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('current_team_id');
            });
        }

        Schema::dropIfExists('team_invitations');
        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
    }
};
