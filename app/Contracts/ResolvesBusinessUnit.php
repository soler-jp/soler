<?php

namespace App\Contracts;

use App\Models\BusinessUnit;

/**
 * このモデルが属する BusinessUnit を解決できることを表す契約。
 *
 * 認可（Policy）はリソースへの到達経路を個別に知らず、
 * このメソッドだけを見て対象の BusinessUnit を得る。
 */
interface ResolvesBusinessUnit
{
    public function resolveBusinessUnit(): BusinessUnit;
}
