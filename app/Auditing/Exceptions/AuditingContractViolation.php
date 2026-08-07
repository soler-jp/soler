<?php

namespace App\Auditing\Exceptions;

use RuntimeException;

/**
 * 監査ログのモデル契約に違反する呼び出しがあったときに投げる。
 *
 * 具体的には次のようなプログラマエラーを表す:
 *  - `AuditContext::within()` スコープ外での `AuditLogger::record()` 呼び出し
 *  - スコープが record() 0 件で終了
 *  - `AuditLogger::record()` が DB トランザクション外で呼ばれた
 *  - `AuditChanges` のシリアライズ結果が 64 KB を超えた
 *
 * これらはいずれも「業務経路の実装バグ」であり、呼び出し側で catch して
 * 分岐する想定はない。個別クラスを分けず 1 種類にまとめている。
 */
class AuditingContractViolation extends RuntimeException {}
