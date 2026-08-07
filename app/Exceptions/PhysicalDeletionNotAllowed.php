<?php

namespace App\Exceptions;

use DomainException;

/**
 * リソースの物理削除がドメイン禁則で拒否されたことを表す。
 *
 * 主に長期保存対象のエンティティ (BusinessUnit, User) の `->delete()` が
 * 呼ばれたときに投げる。削除の代わりに退会・無効化・匿名化などの
 * 専用フローで扱うべきであることを呼び出し側に伝える。
 */
class PhysicalDeletionNotAllowed extends DomainException {}
