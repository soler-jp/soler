<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isMysql = Schema::getConnection()->getDriverName() === 'mysql';

        Schema::create('audit_log_targets', function (Blueprint $table) use ($isMysql) {
            $table->id();
            // 親 audit_log が実質不変なので cascade でも副作用は発生しない。
            // 一貫性のため明示的に cascade を指定する。
            $table->foreignId('audit_log_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('親監査ログ');
            // 追記専用契約を DB 層でも尊重するため restrict。
            $table->foreignId('business_unit_id')
                ->constrained()
                ->restrictOnDelete()
                ->comment('冗長列。親と一致することを AuditLogger が保証');
            $table->string('role', 32)
                ->comment('対象の役割。AuditTargetRole Enum のシリアライズ値');
            // ASCII 範囲で足りるため utf8mb4 の 4 バイト/文字を回避する。
            $auditableType = $table->string('auditable_type', 191)
                ->comment('対象モデルクラス');
            $auditableId = $table->string('auditable_id', 36)
                ->comment('対象 ID。bigint/UUID/ULID を透過的に受ける');
            if ($isMysql) {
                $auditableType->charset('ascii')->collation('ascii_bin');
                $auditableId->charset('ascii')->collation('ascii_bin');
            }
            $table->dateTime('recorded_at', 6)
                ->comment('親の recorded_at を冗長で保持。子テーブル単独クエリ用');

            $table->index(
                ['business_unit_id', 'auditable_type', 'auditable_id', 'recorded_at', 'audit_log_id'],
                'audit_log_targets_bu_auditable_recorded_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log_targets');
    }
};
