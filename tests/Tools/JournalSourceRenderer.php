<?php

namespace Tests\Tools;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;

/**
 * テストソースを静的解析し、仕訳登録（TransactionRegistrar::register /
 * registerOpeningEntry）の文と仕訳明細のアサーション（assertSame / assertEquals）を
 * 簿記の記法（日付 | 借方科目 金額 / 貸方科目 金額 | 摘要）に置き換えた
 * フルソースを返すレビュー用ツール。
 *
 * 置き換えた行は登録が「▶」、アサーションが「✓」で示し、それ以外の行は原文のまま出力する。
 * 桁はメソッド内で「▶」「✓」それぞれのグループごとに揃える
 * （摘要の長さに合わせて借方の開始位置を揃える）。
 * テストの実行やDBには一切関与せず、解決できない式は原文のまま表示する。
 */
class JournalSourceRenderer
{
    private PrettyPrinter $printer;

    private NodeFinder $finder;

    public function __construct()
    {
        $this->printer = new PrettyPrinter;
        $this->finder = new NodeFinder;
    }

    public function renderFile(string $path): string
    {
        $output = $this->renderSource(file_get_contents($path));

        if ($output === '') {
            return '';
        }

        return "# {$path}\n\n{$output}";
    }

    /**
     * 仕訳登録の文を1行スタイルに置き換えたソース全体を返す。
     * 仕訳登録が1つもない場合は空文字を返す。
     */
    public function renderSource(string $code): string
    {
        $ast = (new ParserFactory)->createForHostVersion()->parse($code);
        $methods = $this->finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class);

        $setUpMap = [];
        foreach ($methods as $method) {
            if ((string) $method->name === 'setUp') {
                $setUpMap = $this->collectSubAccountNames($method);
            }
        }

        $replacements = [];

        foreach ($methods as $method) {
            $subAccountNames = array_merge($setUpMap, $this->collectSubAccountNames($method));
            $journals = [];
            $assertions = [];

            foreach ($this->finder->findInstanceOf($method, Node\Stmt\Expression::class) as $stmt) {
                $journal = $this->journalDataFor($stmt, $subAccountNames);

                if ($journal !== null) {
                    $journals[] = $journal;

                    continue;
                }

                $assertion = $this->assertionDataFor($stmt);

                if ($assertion !== null) {
                    $assertions[] = $assertion;
                }
            }

            foreach (['▶' => $journals, '✓' => $assertions] as $marker => $group) {
                if ($group === []) {
                    continue;
                }

                $widths = $this->columnWidths($group);

                foreach ($group as $journal) {
                    $replacements[] = [
                        'start' => $journal['start'],
                        'end' => $journal['end'],
                        'marker' => $marker,
                        'lines' => $this->formatJournal($journal, $widths),
                    ];
                }
            }
        }

        if ($replacements === []) {
            return '';
        }

        $sourceLines = explode("\n", $code);

        usort($replacements, fn (array $a, array $b): int => $b['start'] <=> $a['start']);

        foreach ($replacements as $replacement) {
            preg_match('/^\s*/', $sourceLines[$replacement['start'] - 1], $matches);
            $indent = $matches[0];

            array_splice(
                $sourceLines,
                $replacement['start'] - 1,
                $replacement['end'] - $replacement['start'] + 1,
                array_map(
                    fn (string $line): string => $indent.$replacement['marker'].' '.$line,
                    $replacement['lines'],
                ),
            );
        }

        return implode("\n", $sourceLines);
    }

    /**
     * 文が仕訳登録なら描画用データを返し、それ以外は null を返す
     *
     * @param  array<string, string>  $subAccountNames
     * @return array{start: int, end: int, header: string, trailer: string, debits: list<array{0: string, 1: string}>, credits: list<array{0: string, 1: string}>, note: string}|null
     */
    private function journalDataFor(Node\Stmt\Expression $stmt, array $subAccountNames): ?array
    {
        $expr = $stmt->expr;
        $assignPrefix = '';

        if ($expr instanceof Node\Expr\Assign) {
            $assignPrefix = $this->printer->prettyPrintExpr($expr->var).' = ';
            $expr = $expr->expr;
        }

        if (! $expr instanceof Node\Expr\MethodCall) {
            return null;
        }

        $callName = (string) $expr->name;
        $journal = null;

        if ($callName === 'register' && count($expr->getArgs()) >= 3) {
            $journal = $this->journalFromRegister($expr, $assignPrefix, $subAccountNames);
        }

        if ($callName === 'registerOpeningEntry' && count($expr->getArgs()) >= 1) {
            $journal = $this->journalFromOpeningEntry($expr, $assignPrefix);
        }

        if ($journal === null) {
            return null;
        }

        return $journal + ['start' => $stmt->getStartLine(), 'end' => $stmt->getEndLine()];
    }

    /**
     * @param  array<string, string>  $subAccountNames
     * @return array{header: string, trailer: string, debits: list<array{0: string, 1: string}>, credits: list<array{0: string, 1: string}>, note: string}
     */
    private function journalFromRegister(Node\Expr\MethodCall $call, string $assignPrefix, array $subAccountNames): array
    {
        $args = $call->getArgs();
        $transactionData = $this->arrayToMap($args[1]->value);
        $entriesNode = $args[2]->value;

        $date = isset($transactionData['date']) ? $this->scalarText($transactionData['date']) : '?';
        $description = isset($transactionData['description']) ? $this->scalarText($transactionData['description']) : '?';

        $notes = [];
        foreach ($transactionData as $key => $valueNode) {
            if (! in_array($key, ['date', 'description'], true)) {
                $notes[] = "{$key}: ".$this->printer->prettyPrintExpr($valueNode);
            }
        }

        $debits = [];
        $credits = [];

        if ($entriesNode instanceof Node\Expr\Array_) {
            foreach ($entriesNode->items as $item) {
                if (! $item->value instanceof Node\Expr\Array_) {
                    $debits[] = [$this->printer->prettyPrintExpr($item->value), ''];

                    continue;
                }

                $entry = $this->arrayToMap($item->value);
                $cell = $this->entryCell($entry, $subAccountNames);

                if ($this->entryType($entry) === 'credit') {
                    $credits[] = $cell;
                } else {
                    $debits[] = $cell;
                }
            }
        } else {
            $notes[] = 'entries: '.$this->printer->prettyPrintExpr($entriesNode);
        }

        return [
            'header' => $assignPrefix.$date,
            'trailer' => $description,
            'debits' => $debits,
            'credits' => $credits,
            'note' => implode(', ', $notes),
        ];
    }

    /**
     * @return array{header: string, trailer: string, debits: list<array{0: string, 1: string}>, credits: list<array{0: string, 1: string}>, note: string}
     */
    private function journalFromOpeningEntry(Node\Expr\MethodCall $call, string $assignPrefix): array
    {
        $entriesNode = $call->getArgs()[0]->value;
        $debits = [];

        if ($entriesNode instanceof Node\Expr\Array_) {
            foreach ($entriesNode->items as $item) {
                if (! $item->value instanceof Node\Expr\Array_) {
                    $debits[] = [$this->printer->prettyPrintExpr($item->value), ''];

                    continue;
                }

                $entry = $this->arrayToMap($item->value);
                $accountName = isset($entry['account_name']) ? $this->scalarText($entry['account_name']) : '?';
                $subAccountName = isset($entry['sub_account_name']) ? $this->scalarText($entry['sub_account_name']) : $accountName;
                $amount = isset($entry['amount']) ? $this->amountText($entry['amount']) : '?';

                $debits[] = [
                    $accountName === $subAccountName ? $accountName : "{$accountName}/{$subAccountName}",
                    $amount,
                ];
            }
        } else {
            $debits[] = [$this->printer->prettyPrintExpr($entriesNode), ''];
        }

        return [
            'header' => $assignPrefix.'期首残高',
            'trailer' => '',
            'debits' => $debits,
            'credits' => [],
            'note' => '',
        ];
    }

    /**
     * 文が仕訳明細のアサーション（assertSame / assertEquals の期待値が
     * account_name と amount を持つ連想配列またはそのリスト）なら描画用データを返す
     *
     * @return array{start: int, end: int, header: string, trailer: string, debits: list<array{0: string, 1: string}>, credits: list<array{0: string, 1: string}>, note: string}|null
     */
    private function assertionDataFor(Node\Stmt\Expression $stmt): ?array
    {
        $expr = $stmt->expr;

        if (! $expr instanceof Node\Expr\MethodCall
            || ! in_array((string) $expr->name, ['assertSame', 'assertEquals'], true)) {
            return null;
        }

        $args = $expr->getArgs();

        if (count($args) < 2 || ! $args[0]->value instanceof Node\Expr\Array_) {
            return null;
        }

        $entries = $this->namedEntryMaps($args[0]->value);

        if ($entries === null) {
            return null;
        }

        $debits = [];
        $credits = [];

        foreach ($entries as $entry) {
            $cell = $this->entryCellFromNamedMap($entry);
            $typeNode = $entry['type'] ?? null;

            if ($typeNode instanceof Node\Scalar\String_ && $typeNode->value === 'credit') {
                $credits[] = $cell;
            } else {
                $debits[] = $cell;
            }
        }

        return [
            'header' => '',
            'trailer' => $this->printer->prettyPrintExpr($args[1]->value),
            'debits' => $debits,
            'credits' => $credits,
            'note' => '',
            'start' => $stmt->getStartLine(),
            'end' => $stmt->getEndLine(),
        ];
    }

    /**
     * 配列リテラルが仕訳明細（account_name と amount を持つ連想配列、
     * またはそのリスト）なら キー => 値ノード の連想配列のリストを返す
     *
     * @return list<array<string, Node\Expr>>|null
     */
    private function namedEntryMaps(Node\Expr\Array_ $array): ?array
    {
        $isNamedEntry = fn (array $map): bool => isset($map['account_name'], $map['amount']);

        $map = $this->arrayToMap($array);

        if ($isNamedEntry($map)) {
            return [$map];
        }

        $entries = [];

        foreach ($array->items as $item) {
            if (! $item->value instanceof Node\Expr\Array_) {
                return null;
            }

            $entry = $this->arrayToMap($item->value);

            if (! $isNamedEntry($entry)) {
                return null;
            }

            $entries[] = $entry;
        }

        return $entries === [] ? null : $entries;
    }

    /**
     * account_name / sub_account_name / amount 形式の連想配列から表示用の [科目名, 金額] を作る
     *
     * @param  array<string, Node\Expr>  $entry
     * @return array{0: string, 1: string}
     */
    private function entryCellFromNamedMap(array $entry): array
    {
        $accountName = $this->scalarText($entry['account_name']);
        $subAccountName = isset($entry['sub_account_name']) ? $this->scalarText($entry['sub_account_name']) : $accountName;

        $name = $accountName === $subAccountName ? $accountName : "{$accountName}/{$subAccountName}";

        $annotations = [];
        foreach ($entry as $key => $valueNode) {
            if (! in_array($key, ['account_name', 'sub_account_name', 'amount', 'type'], true)) {
                $annotations[] = "{$key}: ".$this->scalarText($valueNode);
            }
        }

        if ($annotations !== []) {
            $name .= ' ('.implode(', ', $annotations).')';
        }

        return [$name, $this->amountText($entry['amount'])];
    }

    /**
     * メソッド内の全仕訳を通した各カラムの表示幅を求める
     *
     * @param  list<array{header: string, trailer: string, debits: list<array{0: string, 1: string}>, credits: list<array{0: string, 1: string}>}>  $journals
     * @return array{header: int, debitName: int, debitAmount: int, creditName: int, creditAmount: int}
     */
    private function columnWidths(array $journals): array
    {
        $widths = [
            'header' => 0,
            'debitName' => 0,
            'debitAmount' => 0,
            'creditName' => 0,
            'creditAmount' => 0,
        ];

        foreach ($journals as $journal) {
            $widths['header'] = max($widths['header'], mb_strwidth($journal['header']));

            foreach ($journal['debits'] as [$name, $amount]) {
                $widths['debitName'] = max($widths['debitName'], mb_strwidth($name));
                $widths['debitAmount'] = max($widths['debitAmount'], mb_strwidth($amount));
            }

            foreach ($journal['credits'] as [$name, $amount]) {
                $widths['creditName'] = max($widths['creditName'], mb_strwidth($name));
                $widths['creditAmount'] = max($widths['creditAmount'], mb_strwidth($amount));
            }
        }

        return $widths;
    }

    /**
     * 1つの仕訳を「日付 | 借方科目 金額 / 貸方科目 金額 | 摘要」の行にする。
     * 借方の開始位置・スラッシュ・区切りの位置はメソッド内の全仕訳で揃う。
     * 日付欄（header）が空のグループ（アサーション）では日付欄ごと省略する。
     *
     * @param  array{header: string, trailer: string, debits: list<array{0: string, 1: string}>, credits: list<array{0: string, 1: string}>, note: string}  $journal
     * @param  array{header: int, debitName: int, debitAmount: int, creditName: int, creditAmount: int}  $widths
     * @return list<string>
     */
    private function formatJournal(array $journal, array $widths): array
    {
        $lines = [];
        $lineCount = max(count($journal['debits']), count($journal['credits']), 1);

        for ($i = 0; $i < $lineCount; $i++) {
            [$debitName, $debitAmount] = $journal['debits'][$i] ?? ['', ''];
            [$creditName, $creditAmount] = $journal['credits'][$i] ?? ['', ''];

            $line = '';

            if ($widths['header'] > 0) {
                $line .= $this->pad($i === 0 ? $journal['header'] : '', $widths['header']).' | ';
            }

            $line .= $this->pad($debitName, $widths['debitName'])
                .' '
                .$this->pad($debitAmount, $widths['debitAmount'], padLeft: true)
                .' / '
                .$this->pad($creditName, $widths['creditName'])
                .' '
                .$this->pad($creditAmount, $widths['creditAmount'], padLeft: true)
                .' | '
                .($i === 0 ? $journal['trailer'] : '');

            if ($i === 0 && $journal['note'] !== '') {
                $line .= '   # '.$journal['note'];
            }

            $lines[] = rtrim($line);
        }

        return $lines;
    }

    /**
     * 補助科目を名前で取得している代入を集めて
     * 変数名 => 科目名（科目名と補助科目名が異なる場合は「科目/補助」）の対応表を作る
     *
     * 対応パターン:
     * - `$cash = ...->getSubAccountByName('現金', '現金')`
     * - `$cash = $this->subAccountByName($unit, '現金')`
     *
     * @return array<string, string>
     */
    private function collectSubAccountNames(Node\Stmt\ClassMethod $method): array
    {
        $names = [];

        foreach ($this->finder->findInstanceOf($method, Node\Expr\Assign::class) as $assign) {
            if (! $assign->var instanceof Node\Expr\Variable || ! is_string($assign->var->name)) {
                continue;
            }

            $call = $assign->expr;

            if (! $call instanceof Node\Expr\MethodCall) {
                continue;
            }

            $name = $this->subAccountNameFromExpr($call);

            if ($name !== null) {
                $names[$assign->var->name] = $name;
            }
        }

        return $names;
    }

    /**
     * 補助科目取得の呼び出しから表示用の科目名を求める
     */
    private function subAccountNameFromExpr(Node\Expr $expr): ?string
    {
        if (! $expr instanceof Node\Expr\MethodCall) {
            return null;
        }

        $call = $expr;
        $callName = (string) $call->name;
        $args = $call->getArgs();

        if (in_array($callName, ['first', 'firstOrFail'], true)) {
            return $this->subAccountNameFromExpr($call->var);
        }

        if ($callName === 'subAccounts') {
            return $this->subAccountNameFromExpr($call->var);
        }

        if ($callName === 'getSubAccountByName'
            && count($args) >= 2
            && $args[0]->value instanceof Node\Scalar\String_
            && $args[1]->value instanceof Node\Scalar\String_) {
            $accountName = $args[0]->value->value;
            $subAccountName = $args[1]->value->value;

            return $accountName === $subAccountName
                ? $accountName
                : "{$accountName}/{$subAccountName}";
        }

        if ($callName === 'getAccountByName'
            && count($args) >= 1
            && $args[0]->value instanceof Node\Scalar\String_) {
            return $args[0]->value->value;
        }

        if ($callName === 'subAccountByName'
            && count($args) >= 2
            && $args[1]->value instanceof Node\Scalar\String_) {
            return $args[1]->value->value;
        }

        return null;
    }

    /**
     * 仕訳1行分の入力から表示用の [科目名, 金額] を作る
     *
     * @param  array<string, Node\Expr>  $entry
     * @param  array<string, string>  $subAccountNames
     * @return array{0: string, 1: string}
     */
    private function entryCell(array $entry, array $subAccountNames): array
    {
        $name = isset($entry['sub_account_id'])
            ? $this->subAccountText($entry['sub_account_id'], $subAccountNames)
            : '?';

        $annotations = [];
        foreach ($entry as $key => $valueNode) {
            if (! in_array($key, ['sub_account_id', 'type', 'net_amount', 'gross_amount'], true)) {
                $annotations[] = "{$key}: ".$this->scalarText($valueNode);
            }
        }

        if ($annotations !== []) {
            $name .= ' ('.implode(', ', $annotations).')';
        }

        if (isset($entry['net_amount'])) {
            $amount = $this->amountText($entry['net_amount']);
        } elseif (isset($entry['gross_amount'])) {
            $amount = '税込'.$this->amountText($entry['gross_amount']);
        } else {
            $amount = '?';
        }

        return [$name, $amount];
    }

    /**
     * @param  array<string, Node\Expr>  $entry
     */
    private function entryType(array $entry): string
    {
        $typeNode = $entry['type'] ?? null;

        if ($typeNode instanceof Node\Scalar\String_) {
            return $typeNode->value;
        }

        if ($typeNode instanceof Node\Expr\ClassConstFetch) {
            return str_contains((string) $typeNode->name, 'CREDIT') ? 'credit' : 'debit';
        }

        return 'debit';
    }

    /**
     * @param  array<string, string>  $subAccountNames
     */
    private function subAccountText(Node\Expr $expr, array $subAccountNames): string
    {
        if ($expr instanceof Node\Expr\PropertyFetch
            && $expr->var instanceof Node\Expr\Variable
            && is_string($expr->var->name)
            && (string) $expr->name === 'id'
            && isset($subAccountNames[$expr->var->name])) {
            return $subAccountNames[$expr->var->name];
        }

        return $this->printer->prettyPrintExpr($expr);
    }

    private function amountText(Node\Expr $expr): string
    {
        if ($expr instanceof Node\Scalar\Int_) {
            return number_format($expr->value);
        }

        return $this->printer->prettyPrintExpr($expr);
    }

    private function scalarText(Node\Expr $expr): string
    {
        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }

        if ($expr instanceof Node\Scalar\Int_) {
            return (string) $expr->value;
        }

        if ($expr instanceof Node\Expr\ClassConstFetch) {
            return (string) $expr->name;
        }

        return $this->printer->prettyPrintExpr($expr);
    }

    /**
     * 文字列キーの配列リテラルを キー => 値ノード の連想配列にする
     *
     * @return array<string, Node\Expr>
     */
    private function arrayToMap(Node\Expr $node): array
    {
        $map = [];

        if (! $node instanceof Node\Expr\Array_) {
            return $map;
        }

        foreach ($node->items as $item) {
            if ($item->key instanceof Node\Scalar\String_) {
                $map[$item->key->value] = $item->value;
            }
        }

        return $map;
    }

    /**
     * 全角文字を考慮して指定表示幅までパディングする
     */
    private function pad(string $value, int $width, bool $padLeft = false): string
    {
        $padding = str_repeat(' ', max(0, $width - mb_strwidth($value)));

        return $padLeft ? $padding.$value : $value.$padding;
    }
}
