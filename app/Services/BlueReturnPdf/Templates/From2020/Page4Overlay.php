<?php

// 4ページ(貸借対照表)は令和二年分以降用(FA3075)と令和五年分以降用(FA3076)で様式番号だけが違い、
// 欄配置は同一(geometry JSON の digit_cell_groups 突き合わせで一致を確認済み)のため、From2023 の定義を共用する。

return require __DIR__.'/../From2023/Page4Overlay.php';
