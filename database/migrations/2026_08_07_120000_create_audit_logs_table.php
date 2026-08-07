<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isMysql = Schema::getConnection()->getDriverName() === 'mysql';

        Schema::create('audit_logs', function (Blueprint $table) use ($isMysql) {
            $table->id();
            // 追記専用契約を DB 層でも尊重するため、参照先の削除でログ本体が
            // 消えたり書き換わったりしない。BusinessUnit / User の削除は
            // 監査ログが残っている限り DB レベルで拒否される。
            $table->foreignId('business_unit_id')
                ->constrained()
                ->restrictOnDelete()
                ->comment('スコープの境界となる事業体ID');
            $table->string('event_type', 64)
                ->comment('AuditEvent Enum のシリアライズ値');
            $table->foreignId('actor_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete()
                ->comment('操作者。バッチ・システム経路のみ null');
            $actorLabel = $table->string('actor_label', 100)
                ->nullable()
                ->comment('記録時点の actor 表示名スナップショット');
            if ($isMysql) {
                $actorLabel->collation('utf8mb4_bin');
            }
            $table->text('reason')
                ->nullable()
                ->comment('業務理由。deactivation_reason / revision_reason などのコピー');
            $table->unsignedSmallInteger('payload_version')
                ->comment('changes の shape バージョン');
            $table->json('changes')
                ->nullable()
                ->comment('変更内容。shape は AuditChanges を参照');
            $table->json('context')
                ->nullable()
                ->comment('IP・UA・リクエスト経路など任意の付帯情報');
            // 2038 問題を避けるため datetime を使う (timestamp ではない)。
            $table->dateTime('recorded_at', 6)
                ->comment('DB に記録された時刻。時刻の正はこの1本');

            $table->index(
                ['business_unit_id', 'recorded_at', 'id'],
                'audit_logs_bu_recorded_idx',
            );
            $table->index(
                ['business_unit_id', 'event_type', 'recorded_at', 'id'],
                'audit_logs_bu_event_recorded_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
